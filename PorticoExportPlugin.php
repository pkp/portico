<?php

/**
 * @file PorticoExportPlugin.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportPlugin
 * @brief Portico export plugin
 */

namespace APP\plugins\importexport\portico;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\plugins\importexport\portico\classes\migration\upgrade\updatePorticoPluginName;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Exception;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\plugins\ImportExportPlugin;
use PKP\plugins\PluginSettingsDAO;
use ZipArchive;

class PorticoExportPlugin extends ImportExportPlugin
{
    private Context $context;

    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request)
    {
        $this->context = $request->getContext();
        $locale = $this->context->getData('primaryLocale');

        parent::display($args, $request);
        $templateManager = TemplateManager::getManager();
        $templateManager->assign([
            'pluginName' => $this->getName(),
            'ftpLibraryMissing' => !class_exists('\League\Flysystem\Ftp\FtpAdapter')
        ]);

        switch ($route = array_shift($args)) {
            case 'settings':
                return $this->manage($args, $request);
            case 'export':
                $issueIds = $request->getUserVar('selectedIssues') ?? [];
                if (!count($issueIds)) {
                    $templateManager->assign('porticoErrorMessage', __('plugins.importexport.portico.export.failure.noIssueSelected'));
                    break;
                }
                try {
                    // create zip file
                    $path = $this->createFile($issueIds);
                    try {
                        if ($request->getUserVar('type') == 'ftp') {
                            $this->export($path);
                            $templateManager->assign('porticoSuccessMessage', __('plugins.importexport.portico.export.success'));
                        } else {
                            $this->download($path);
                            return;
                        }
                    } finally {
                        unlink($path);
                    }
                } catch (Exception $e) {
                    $templateManager->assign('porticoErrorMessage', $e->getMessage());
                }
                break;
        }

        $contextSettingsUrl = Application::get()->getDispatcher()->url(
            Application::get()->getRequest(),
            PKPApplication::ROUTE_PAGE,
            $this->context->getPath(),
            'management',
            'settings',
            ['context']
        );
        $templateManager->assign('contextSettingsUrl', $contextSettingsUrl);

        // set the issn and abbreviation template variables
        foreach (['onlineIssn', 'printIssn'] as $name) {
            if ($value = $this->context->getData($name)) {
                $templateManager->assign('issn', $value);
                break;
            }
        }

        if ($value = $this->context->getData('abbreviation', $locale)) {
            $templateManager->assign('abbreviation', $value);
        }

        $templateManager->display($this->getTemplateResource('index.tpl'));
    }

    /**
     * Generates a filename for the exported file
     */
    private function createFilename(): string
    {
        $locale = $this->context->getData('primaryLocale');
        return $this->context->getData('acronym', $locale) . '_batch_' . date('Y-m-d-H-i-s') . '.zip';
    }

    /**
     * Downloads a zip file with the selected issues
     *
     * @param string $path the path of the zip file
     */
    private function download(string $path): void
    {
        header('content-type: application/zip');
        header('content-disposition: attachment; filename=' . $this->createFilename());
        header('content-length: ' . filesize($path));
        readfile($path);
    }

    /**
     * Return a list of deposit endpoints.
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
            ]]);
            /* @var PluginSettingsDAO $pluginSettingsDao */
            $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
            foreach (['porticoHost', 'porticoUsername', 'porticoPassword'] as $settingName) {
                $pluginSettingsDao->deleteSetting($contextId, $this->getName(), $settingName);
            }
        }
        return (array) $this->getSetting($contextId, 'endpoints');
    }

    /**
     * Exports a zip file with the selected issues to the configured Portico account
     *
     * @param string $path the path of the zip file
     * @throws Exception|\League\Flysystem\FilesystemException
     */
    private function export(string $path): void
    {
        $endpoints = $this->getEndpoints($this->context->getId());

        // Verify that the credentials are complete
        foreach ($endpoints as $credentials) {
            if (empty($credentials['type']) || empty($credentials['hostname'])) {
                throw new Exception(__('plugins.importexport.portico.export.failure.settings'));
            }
        }

        // Perform the deposit
        foreach ($endpoints as $credentials) {
            switch ($credentials['type']) {
                case 'ftp':
                    $adapter = new \League\Flysystem\Ftp\FtpAdapter(\League\Flysystem\Ftp\FtpConnectionOptions::fromArray([
                        'host' => $credentials['hostname'],
                        'port' => ((int) $credentials['port'] ?? null) ?: 21,
                        'username' => $credentials['username'],
                        'password' => $credentials['password'],
                        'root' => $credentials['path'],
                    ]));
                    break;
                case 'loc':
                case 'portico':
                case 'sftp':
                    $adapter = new \League\Flysystem\PhpseclibV3\SftpAdapter(
                        new \League\Flysystem\PhpseclibV3\SftpConnectionProvider(
                            host: $credentials['hostname'],
                            username: $credentials['username'],
                            password: !empty($credentials['private_key']) ? null : $credentials['password'],
                            privateKey: $credentials['private_key'] ?? null ?: null, // Convert possible empty string to null
                            passphrase: $credentials['keyphrase'] ?? null ?: null, // Convert possible empty string to null
                            port: ((int) $credentials['port'] ?? null) ?: 22,
                        ),
                        $credentials['path'] ?? '/',
                        \League\Flysystem\UnixVisibility\PortableVisibilityConverter::fromArray([
                            'file' => [
                                'public' => 0640,
                                'private' => 0604,
                            ],
                            'dir' => [
                                'public' => 0740,
                                'private' => 7604,
                            ],
                        ])
                    );
                    break;
                default:
                    throw new Exception('Unknown endpoint type!');
            }
            $fs = new \League\Flysystem\Filesystem($adapter);
            $fp = fopen($path, 'r');
            $fs->writeStream($this->createFilename(), $fp);
            fclose($fp);
        }
    }

    /**
     * Creates a zip file with the given issues
     *
     * @return string the path of the created zip file
     * @throws Exception
     */
    private function createFile(array $issueIds): string
    {
        // create zip file
        $path = tempnam(sys_get_temp_dir(), 'tmp');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            error_log('Unable to create Portico ZIP: ' . $zip->getStatusString());
            throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
        }
        try {
            foreach ($issueIds as $issueId) {
                if (!($issue = Repo::issue()->get($issueId, $this->context->getId()))) {
                    throw new Exception(__('plugins.importexport.portico.export.failure.loadingIssue', ['issueId' => $issueId]));
                }

                // add submission XML
                $submissionCollector = Repo::submission()->getCollector();
                $submissions = $submissionCollector
                    ->filterByContextIds([$this->context->getId()])
                    ->filterByIssueIds([$issueId])
                    ->orderBy($submissionCollector::ORDERBY_SEQUENCE, $submissionCollector::ORDER_DIR_ASC)
                    ->getMany();
                foreach ($submissions as $article) { /* @var Submission $article */
                    $document = new PorticoExportDom($this->context, $issue, $article);
                    $articlePathName = $article->getId() . '/' . $article->getId() . '.xml';
                    if (!$zip->addFromString($articlePathName, $document)) {
                        error_log("Unable to add {$articlePathName} to Portico ZIP");
                        throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
                    }

                    // add galleys
                    $fileService = app()->get('file');
                    $galleys = $article->getData('galleys') ?? [];
                    foreach ($galleys as $galley) {
                        $submissionFileId = $galley->getData('submissionFileId');
                        $submissionFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;
                        if (!$submissionFile) {
                            continue;
                        }

                        $filePath = $fileService->get($submissionFile->getData('fileId'))->path;
                        if (!$zip->addFromString($article->getId() . '/' . basename($filePath), $fileService->fs->read($filePath))) {
                            error_log("Unable to add file {$filePath} to Portico ZIP");
                            throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
                        }
                    }
                }
            }
        } finally {
            if (!$zip->close()) {
                error_log('Unable to close Portico ZIP: ' . $zip->getStatusString());
                throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
            }
        }

        return $path;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') == 'settings') {
            $user = $request->getUser();
            $this->addLocaleData();
            $form = new PorticoSettingsForm($this, $request->getContext()->getId());

            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager = new NotificationManager();
                    $notificationManager->createTrivialNotification($user->getId());
                }
            } else {
                $form->initData();
            }
            return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
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

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $isRegistered = parent::register($category, $path, $mainContextId);
        $this->addLocaleData();
        return $isRegistered;
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
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration(): updatePorticoPluginName
    {
        return new updatePorticoPluginName();
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\importexport\portico\PorticoExportPlugin', '\PorticoExportPlugin');
}
