<?php

/**
 * @file plugins/generic/portico/PorticoExportPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportPlugin
 *
 * @brief Portico export plugin
 */

namespace APP\plugins\generic\portico;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\portico\jobs\PorticoDeliver;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use PKP\context\Context;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\jats\exceptions\UnableToCreateJATSContentException;
use PKP\notification\Notification;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use PKP\submission\Genre;
use PKP\submission\GenreDAO;
use PKP\submissionFile\enums\MediaVariantType;
use PKP\submissionFile\SubmissionFile;
use ZipArchive;

class PorticoExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    /**
     * Galley is the primary PDF document, packed and referenced via <self-uri>
     *
     * @var string
     */
    private const GALLEY_KIND_PDF = 'pdf';

    /**
     * Galley is a supplementary file, packed and referenced via <supplementary-material>
     *
     * @var string
     */
    private const GALLEY_KIND_SUPPLEMENTARY = 'supplementary';

    /**
     * Galley is excluded from the package entirely (e.g. HTML, artwork, dependent files)
     *
     * @var string
     */
    private const GALLEY_KIND_EXCLUDED = 'excluded';

    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request): void
    {
        parent::display($args, $request);
        $templateManager = TemplateManager::getManager();
        $templateManager->assign([
            'ftpLibraryMissing' => !class_exists('\League\Flysystem\Ftp\FtpAdapter'),
        ]);

        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
        }
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return 'PorticoExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.importexport.portico.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.importexport.portico.description.short');
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix(): string
    {
        return 'portico';
    }

    /**
     * @copydoc Plugin::getSetting()
     */
    public function getSetting($contextId, $name)
    {
        $value = parent::getSetting($contextId, $name);
        if ($name === 'endpoints' && is_array($value)) {
            $value = $this->transformEndpointSecrets($value, false);
        }
        return $value;
    }

    /**
     * @copydoc Plugin::updateSetting()
     */
    public function updateSetting($contextId, $name, $value, $type = null)
    {
        if ($name === 'endpoints' && is_array($value)) {
            $value = $this->transformEndpointSecrets($value, true);
        }
        parent::updateSetting($contextId, $name, $value, $type);
    }

    /**
     * Encrypt or decrypt the password/keyphrase of each endpoint. hostname/username/path/etc. stay plain.
     */
    private function transformEndpointSecrets(array $endpoints, bool $encrypt): array
    {
        foreach ($endpoints as &$endpoint) {
            foreach (['password', 'keyphrase'] as $field) {
                if (!empty($endpoint[$field])) {
                    $endpoint[$field] = $encrypt ? app()->encrypt($endpoint[$field]) : app()->decrypt($endpoint[$field]);
                }
            }
        }
        return $endpoints;
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName(): string
    {
        return '\APP\plugins\generic\portico\PorticoExportDeployment';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        return '\APP\plugins\generic\portico\classes\form\PorticoSettingsForm';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportableVersionStages()
     *
     * Portico's archival remit covers every published version stage.
     */
    public function getExportableVersionStages(): array
    {
        return VersionStage::cases();
    }

    /**
     * @copydoc PubObjectsExportPlugin::getDepositSuccessNotificationMessageKey()
     */
    public function getDepositSuccessNotificationMessageKey()
    {
        return 'plugins.importexport.portico.submit.success';
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new PorticoInfoSender())
            ->daily()
            ->name(PorticoInfoSender::class)
            ->withoutOverlapping();
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportActions()
     */
    public function getExportActions($context): array
    {
        $actions = [PubObjectsExportPlugin::EXPORT_ACTION_EXPORT, PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED];
        if ($this->hasCompleteEndpoints($context->getId())) {
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * Whether at least one endpoint is configured, and every configured endpoint is complete.
     */
    public function hasCompleteEndpoints(int $contextId): bool
    {
        $endpoints = $this->getEndpoints($contextId);
        if (empty($endpoints)) {
            return false;
        }
        foreach ($endpoints as $credentials) {
            if (!$this->isEndpointComplete($credentials)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a single endpoint has the fields required to actually deliver to it:
     * type, hostname, username, and either a password or a private key.
     */
    public function isEndpointComplete(array $credentials): bool
    {
        if (empty($credentials['type']) || empty($credentials['hostname']) || empty($credentials['username'])) {
            return false;
        }
        return !empty($credentials['password']) || !empty($credentials['private_key']);
    }

    /**
     * @copydoc PubObjectsExportPlugin::executeExportAction()
     */
    public function executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation = null, $shouldRedirect = true): void
    {
        $context = $request->getContext();
        $path = ['plugin', $this->getName()];

        if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            $result = $this->depositXML($objects, $context, null);
            if ($result === true) {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    Notification::NOTIFICATION_TYPE_SUCCESS
                );
            } else {
                foreach ((array) $result as $error) {
                    $this->_sendNotification(
                        $request->getUser(),
                        $error[0],
                        Notification::NOTIFICATION_TYPE_ERROR,
                        ($error[1] ?? null)
                    );
                }
            }
            $request->redirect(null, null, null, $path, null, $tab);
        } elseif ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            if (count($objects) === 1) {
                $object = reset($objects);
                $result = $this->createZip($object, $context);
                if (isset($result['error'])) {
                    $result['error'][1] = $this->buildMetadataFilename($object);
                }
            } else {
                $result = $this->createZipCollection($objects, $context);
            }
            if (!empty($result['error'])) {
                $this->_sendNotification(
                    $request->getUser(),
                    $result['error'][0],
                    Notification::NOTIFICATION_TYPE_ERROR,
                    ($result['error'][1] ?? null)
                );
                $request->redirect(null, null, null, $path, null, $tab);
                return;
            }
            $filename = ($result['filename'] ?? $this->buildFileName($context, null)) . '.zip';
            $fileManager = new FileManager();
            $fileManager->downloadByPath($result['path'], 'application/zip', false, $filename);
            $fileManager->deleteByPath($result['path']);
        } else {
            parent::executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation, $shouldRedirect);
        }
    }

    /**
     * Dispatches a job per selected object to build and deliver its ZIP package, so a
     * large/slow export can't block the triggering request.
     *
     * @copydoc PubObjectsExportPlugin::depositXML()
     *
     * @param Submission[]|Publication[] $objects
     * @param null|mixed $filename
     *
     * @return bool|array True on success (i.e. successfully queued), or an array of error messages.
     */
    public function depositXML($objects, $context, $filename = null): bool|array
    {
        if (!$this->hasCompleteEndpoints($context->getId())) {
            return [['plugins.importexport.portico.export.failure.settings']];
        }

        foreach ($objects as $object) {
            dispatch(new PorticoDeliver(
                $this->buildFileName($context, $object) . '.zip',
                $object->getId(),
                $object instanceof Publication,
                $context->getId()
            ));
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_SUBMITTED);
        }

        return true;
    }

    /**
     * Write a file to every configured delivery endpoint.
     *
     * @throws Exception
     */
    public function deliverToEndpoints(string $path, string $filename, array $endpoints): void
    {
        foreach ($endpoints as $credentials) {
            switch ($credentials['type']) {
                case 'ftp':
                    $adapter = new FtpAdapter(FtpConnectionOptions::fromArray([
                        'host' => $credentials['hostname'],
                        'port' => ((int) ($credentials['port'] ?? null)) ?: 21,
                        'username' => $credentials['username'],
                        'password' => $credentials['password'],
                        'root' => $credentials['path'],
                    ]));
                    break;
                case 'loc':
                case 'portico':
                case 'sftp':
                    $adapter = new SftpAdapter(
                        new SftpConnectionProvider(
                            host: $credentials['hostname'],
                            username: $credentials['username'],
                            password: !empty($credentials['private_key']) ? null : $credentials['password'],
                            privateKey: $credentials['private_key'] ?? null ?: null,
                            passphrase: $credentials['keyphrase'] ?? null ?: null,
                            port: ((int) ($credentials['port'] ?? null)) ?: 22,
                        ),
                        $credentials['path'] ?? '/',
                        PortableVisibilityConverter::fromArray([
                            'file' => ['public' => 0640, 'private' => 0604],
                            'dir' => ['public' => 0740, 'private' => 7604],
                        ])
                    );
                    break;
                default:
                    throw new Exception('Unknown endpoint type!');
            }
            $fs = new Filesystem($adapter);
            $fp = fopen($path, 'r');
            $fs->writeStream($filename, $fp);
            fclose($fp);
        }
    }

    /**
     * Create an empty temp file for a ZIP package, under files_dir/temp/.
     */
    protected function createTempZipPath(): string
    {
        $exportPath = $this->getExportPath();
        (new FileManager())->mkdirtree($exportPath);
        return tempnam($exportPath, 'PorticoExport_');
    }

    /**
     * Build a ZIP package (metadata XML + galleys) for a single article or publication.
     *
     * @return array{path: string, filename: string}|array{error: array}
     */
    public function createZip(Submission|Publication $object, Context $context): array
    {
        $zipPath = $this->createTempZipPath();
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            @unlink($zipPath);
            return ['error' => ['plugins.importexport.portico.export.failure.creatingFile']];
        }

        $result = $this->fillZip($zip, $zipPath, $object, $context);
        $zip->close();
        if (isset($result['error'])) {
            @unlink($zipPath);
        }

        return $result;
    }

    /**
     * Pack the metadata XML and galleys into an already-open ZIP. Doesn't close the
     * ZIP or touch $zipPath -- that's createZip()'s job, exactly once.
     *
     * @return array{path: string, filename: string}|array{error: array}
     */
    protected function fillZip(ZipArchive $zip, string $zipPath, Submission|Publication $object, Context $context): array
    {
        $article = $object instanceof Publication ? Repo::submission()->get($object->getData('submissionId')) : $object;
        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();

        /** @var GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genres = [];
        foreach ($genreDao->getEnabledByContextId($context->getId())->toArray() as $genre) {
            $genres[$genre->getId()] = $genre;
        }

        // Classify galleys first, so a no-PDF submission fails before any file/URL work.
        $fileService = app()->get('file');
        $classifiedGalleys = [];
        $hasPdf = false;
        foreach ($publication->getData('galleys') ?? [] as $galley) {
            if ($galley->getData('urlRemote') || !$galley->getData('submissionFileId')) {
                continue;
            }
            $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
            if (!$submissionFile) {
                continue;
            }
            $kind = $this->classifyFileForPackaging($submissionFile, $genres);
            if ($kind === self::GALLEY_KIND_EXCLUDED) {
                continue;
            }
            $classifiedGalleys[] = ['galley' => $galley, 'submissionFile' => $submissionFile, 'kind' => $kind];
            if ($kind === self::GALLEY_KIND_PDF) {
                $hasPdf = true;
            }
        }

        // We currently only deposit PDF and XML.
        // TO-DO: ask Portico about other formats.
        if (!$hasPdf) {
            return ['error' => ['plugins.importexport.portico.export.failure.noPdf']];
        }

        // Track submissionFileId => packed filename, to rewrite matching XML references.
        $request = Application::get()->getRequest();
        $dispatcher = $request->getRouter()->getDispatcher();
        $packedFiles = [];
        $pdfFilenames = [];
        $fileUrlsToFilenames = [];
        foreach ($classifiedGalleys as ['galley' => $galley, 'submissionFile' => $submissionFile, 'kind' => $kind]) {
            $filePath = $fileService->get($submissionFile->getData('fileId'))->path;
            $relativeFilename = basename($filePath);
            if (!$zip->addFromString($relativeFilename, $fileService->fs->read($filePath))) {
                return ['error' => ['plugins.importexport.portico.export.failure.creatingFile']];
            }
            $submissionFileId = $submissionFile->getId();
            $packedFiles[$submissionFileId] = $relativeFilename;

            if ($kind === self::GALLEY_KIND_PDF) {
                $pdfFilenames[] = ['filename' => $relativeFilename, 'locale' => $galley->getLocale(), 'label' => $galley->getLabel()];
            } elseif ($kind === self::GALLEY_KIND_SUPPLEMENTARY) {
                // Precompute this file's own URLs to match uploaded-document references exactly.
                $downloadUrl = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$article->getBestId(), $galley->getBestGalleyId(), $submissionFileId], urlLocaleForPage: '');
                $viewUrl = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$article->getBestId(), $galley->getBestGalleyId()], urlLocaleForPage: '');
                $fileUrlsToFilenames[PorticoJatsProcessor::stripScheme($downloadUrl)] = $relativeFilename;
                $fileUrlsToFilenames[PorticoJatsProcessor::stripScheme($viewUrl)] = $relativeFilename;
            }
        }

        // Resolve an uploaded full-text JATS document, if any, before building the
        // metadata XML so its self-uri can cross-reference it by filename.
        // getJatsFile() ignores jatsPublicVisibility -- that only gates the reader-facing
        // public download, unrelated to whether this content is archived externally.
        $jatsFile = Repo::jats()->getJatsFile($publication->getId(), $article->getId(), array_values($genres));
        $fullTextFilename = null;
        if (!$jatsFile->isDefaultContent && $jatsFile->jatsContent && $jatsFile->submissionFile) {
            $fullTextFilename = basename($fileService->get($jatsFile->submissionFile->getData('fileId'))->path);
        }

        if ($jatsFile->isDefaultContent) {
            // getJatsFile() already generated the default content for us (no uploaded
            // full-text document exists) -- reuse it instead of generating it again.
            if ($jatsFile->jatsContent === null) {
                return ['error' => ['plugins.importexport.portico.export.failure.jatsTemplateUnavailable']];
            }
            $jatsXml = $jatsFile->jatsContent;
        } else {
            try {
                $jatsXml = Repo::jats()->createDefaultJatsContent($publication->getId(), $article->getId());
            } catch (UnableToCreateJATSContentException $e) {
                return ['error' => ['plugins.importexport.portico.export.failure.jatsTemplateUnavailable']];
            }
        }

        $metadataFilename = $this->buildMetadataFilename($object) . '.xml';
        $metadataXml = PorticoJatsProcessor::processMetadata($jatsXml, $packedFiles, $fullTextFilename);
        if ($metadataXml === null || !$zip->addFromString($metadataFilename, $metadataXml)) {
            return ['error' => ['plugins.importexport.portico.export.failure.creatingFile']];
        }

        if ($fullTextFilename !== null) {
            $referencedGraphicNames = PorticoJatsProcessor::extractGraphicFilenames($jatsFile->jatsContent);
            $mediaFilesByName = $this->packMediaFiles($zip, $article->getId(), $publication, $referencedGraphicNames);
            $fullTextXml = PorticoJatsProcessor::processFullText($jatsFile->jatsContent, $mediaFilesByName, $pdfFilenames, $fileUrlsToFilenames);
            if ($fullTextXml === null) {
                return ['error' => ['plugins.importexport.portico.export.failure.malformedFullText']];
            }
            if (!$zip->addFromString($fullTextFilename, $fullTextXml)) {
                return ['error' => ['plugins.importexport.portico.export.failure.creatingFile']];
            }
        }

        return [
            'path' => $zipPath,
            'filename' => $this->buildFileName($context, $object),
        ];
    }

    /**
     * Pack only the referenced media files (preferring the high-resolution variant
     * when one exists), since the publication's media gallery may also hold images
     * meant for the HTML galley, or unused uploads.
     *
     * @param string[] $referencedNames Graphic filenames referenced in the uploaded document
     *
     * @return array<string, string> Referenced filename => relative packed filename
     */
    protected function packMediaFiles(ZipArchive $zip, int $submissionId, Publication $publication, array $referencedNames): array
    {
        if (empty($referencedNames)) {
            return [];
        }

        $mediaFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_MEDIA])
            ->filterByAssoc(Application::ASSOC_TYPE_PUBLICATION, [$publication->getId()])
            ->getMany();

        $fileService = app()->get('file');
        $locale = $publication->getData('locale');
        $mediaFilesByName = [];
        $packedByFileId = [];

        foreach ($mediaFiles as $mediaFile) {
            /** @var SubmissionFile $mediaFile */
            $originalName = $mediaFile->getData('name', $locale);
            if (!$originalName || !in_array($originalName, $referencedNames, true)) {
                continue;
            }

            $preferred = $this->resolvePreferredMediaVariant($mediaFile, $submissionId);

            if (!isset($packedByFileId[$preferred->getId()])) {
                $filePath = $fileService->get($preferred->getData('fileId'))->path;
                $relativeFilename = basename($filePath);
                $zip->addFromString($relativeFilename, $fileService->fs->read($filePath));
                $packedByFileId[$preferred->getId()] = $relativeFilename;
            }

            $mediaFilesByName[$originalName] = $packedByFileId[$preferred->getId()];
        }

        return $mediaFilesByName;
    }

    /**
     * Resolve the preferred variant (high-resolution, if one exists in the same
     * variant group) for a media file, for archival-quality packaging.
     */
    protected function resolvePreferredMediaVariant(SubmissionFile $mediaFile, int $submissionId): SubmissionFile
    {
        $variantGroupId = $mediaFile->getData('variantGroupId');
        if (!$variantGroupId) {
            return $mediaFile;
        }

        $highResolutionSibling = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_MEDIA])
            ->filterByVariantGroupIds([$variantGroupId])
            ->filterByMediaVariantTypes([MediaVariantType::HIGH_RESOLUTION])
            ->getMany()
            ->first();

        return $highResolutionSibling ?? $mediaFile;
    }

    /**
     * Classify a submission file for packaging purposes: primary PDF, supplementary file, or excluded.
     */
    protected function classifyFileForPackaging(SubmissionFile $submissionFile, array $genres): string
    {
        $genre = $genres[$submissionFile->getData('genreId')] ?? null;
        if (!$genre) {
            return self::GALLEY_KIND_EXCLUDED;
        }

        if ($genre->getSupplementary()) {
            return self::GALLEY_KIND_SUPPLEMENTARY;
        }

        $isPrimaryDocument = $genre->getCategory() === Genre::GENRE_CATEGORY_DOCUMENT
            && !$genre->getSupplementary()
            && !$genre->getDependent();
        if ($isPrimaryDocument && $submissionFile->getData('mimetype') === 'application/pdf') {
            return self::GALLEY_KIND_PDF;
        }

        return self::GALLEY_KIND_EXCLUDED;
    }

    /**
     * Build the metadata XML filename (without extension) for a package.
     *
     * Case 1 (single DOI for all versions): {articleId}
     * Case 2 (DOI per version): {articleId}_{versionStageCode}{versionMajor}
     */
    public function buildMetadataFilename(Submission|Publication $object): string
    {
        if ($object instanceof Submission) {
            return (string) $object->getId();
        }
        return $object->getData('submissionId') . '_' . $object->getData('versionStage') . $object->getData('versionMajor');
    }

    /**
     * Bundle one or more per-object ZIP packages into a single outer ZIP for download.
     *
     * @param Submission[]|Publication[] $objects
     *
     * @return array{path: string}|array{error: array}
     */
    protected function createZipCollection(array $objects, Context $context): array
    {
        $finalZipPath = $this->createTempZipPath();
        $finalZip = new ZipArchive();
        if ($finalZip->open($finalZipPath, ZipArchive::CREATE) !== true) {
            @unlink($finalZipPath);
            return ['error' => ['plugins.importexport.portico.export.failure.creatingCollectionFile']];
        }

        // addFile() below only reads each per-object zip when $finalZip->close() runs,
        // so none of them may be deleted before that, on any exit path.
        $createdPaths = [];
        foreach ($objects as $object) {
            $package = $this->createZip($object, $context);
            if (!empty($package['error'])) {
                $package['error'][1] = $this->buildMetadataFilename($object);
                $finalZip->close();
                foreach ($createdPaths as $createdPath) {
                    @unlink($createdPath);
                }
                @unlink($finalZipPath);
                return ['error' => $package['error']];
            }
            if (!$finalZip->addFile($package['path'], $package['filename'] . '.zip')) {
                $createdPaths[] = $package['path'];
                $finalZip->close();
                foreach ($createdPaths as $createdPath) {
                    @unlink($createdPath);
                }
                @unlink($finalZipPath);
                return ['error' => ['plugins.importexport.portico.export.failure.creatingFile', $this->buildMetadataFilename($object)]];
            }
            $createdPaths[] = $package['path'];
        }
        $finalZip->close();

        foreach ($createdPaths as $createdPath) {
            @unlink($createdPath);
        }

        return ['path' => $finalZipPath];
    }

    /**
     * Build a ZIP filename (without extension) for a package or a collection of packages.
     *
     * {journalAcronym}_{articleId}_{datetime}.zip (Case 1)
     * {journalAcronym}_{articleId}_{versionStageCode}{versionMajor}_{datetime}.zip (Case 2)
     * {journalAcronym}_export_{datetime}.zip (Export button, multiple objects selected)
     *
     * {datetime} is always the time the export runs, not datePublished.
     */
    protected function buildFileName(Context $context, Submission|Publication|null $object): string
    {
        $locale = $context->getData('primaryLocale');
        $acronym = $context->getData('acronym', $locale) ?: $context->getPath();
        $acronym = preg_replace('/[^a-zA-Z0-9]/', '', $acronym);

        $middle = $object ? $this->buildMetadataFilename($object) : 'export';

        return $acronym . '_' . $middle . '_' . date('Y-m-d-H-i-s');
    }

    /**
     * Helper to convert an error array to a translated string.
     */
    public function convertErrorMessage(array $errorMessage): string
    {
        return __($errorMessage[0], ['param' => $errorMessage[1] ?? null]);
    }

    /**
     * Return a list of configured delivery endpoints.
     */
    public function getEndpoints($contextId): array
    {
        // Convert old-style Portico credentials to a list of endpoints.
        if ($hostname = $this->getSetting($contextId, 'porticoHost')) {
            $username = $this->getSetting($contextId, 'porticoUsername');
            $password = $this->getSetting($contextId, 'porticoPassword');
            $this->updateSetting($contextId, 'endpoints', [[
                'type' => 'ftp',
                'hostname' => $hostname,
                'username' => $username,
                'password' => $password,
            ]], 'object');
            /* @var PluginSettingsDAO $pluginSettingsDao */
            $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
            foreach (['porticoHost', 'porticoUsername', 'porticoPassword'] as $settingName) {
                $pluginSettingsDao->deleteSetting($contextId, $this->getName(), $settingName);
            }
        }
        return (array) $this->getSetting($contextId, 'endpoints');
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args)
    {
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName)
    {
    }
}
