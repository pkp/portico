<?php

/**
 * @file PorticoExportPlugin.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportPlugin
 * @brief Portico export plugin
 */

namespace APP\plugins\importexport\portico;

use PKP\plugins\ImportExportPlugin;
use ZipArchive;
use Exception;
use PKP\core\JSONMessage;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use APP\i18n\AppLocale;
use APP\notification\NotificationManager;
use APP\facades\Repo;
use APP\core\Services;

class PorticoExportPlugin extends ImportExportPlugin
{
    /** @var Context the current context */
    private $_context;

    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request)
    {
        $this->_context = $request->getContext();

        parent::display($args, $request);
        $templateManager = TemplateManager::getManager();
        $templateManager->assign([
            'pluginName' => self::class,
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
                    $path = $this->_createFile($issueIds);
                    try {
                        if ($request->getUserVar('type') == 'ftp') {
                            $this->_export($path);
                            $templateManager->assign('porticoSuccessMessage', __('plugins.importexport.portico.export.success'));
                        } else {
                            $this->_download($path);
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

        // set the issn and abbreviation template variables
        foreach (['onlineIssn', 'printIssn'] as $name) {
            if ($value = $this->_context->getSetting($name)) {
                $templateManager->assign('issn', $value);
                break;
            }
        }

        if ($value = $this->_context->getLocalizedSetting('abbreviation')) {
            $templateManager->assign('abbreviation', $value);
        }

        $templateManager->display($this->getTemplateResource('index.tpl'));
    }

    /**
     * Generates a filename for the exported file
     */
    private function _createFilename(): string
    {
        return $this->_context->getLocalizedSetting('acronym') . '_batch_' . date('Y-m-d-H-i-s') . '.zip';
    }

    /**
     * Downloads a zip file with the selected issues
     *
     * @param string $path the path of the zip file
     */
    private function _download(string $path): void
    {
        header('content-type: application/zip');
        header('content-disposition: attachment; filename=' . $this->_createFilename());
        header('content-length: ' . filesize($path));
        readfile($path);
    }

    /**
     * Return a list of deposit endpoints.
     *
     * @return array
     */
    public function getEndpoints($contextId)
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
     */
    private function _export(string $path): void
    {
        $endpoints = $this->getEndpoints($this->_context->getId());

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
                        'port' => ($credentials['port'] ?? null) ?: 21,
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
                            passphrase: $credentials['passphrase'] ?? null ?: null, // Convert possible empty string to null
                            port: $credentials['port'] ?? 22,
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
            $fs->writeStream($this->_createFilename(), $fp);
            fclose($fp);
        }
    }

    /**
     * Creates a zip file with the given issues
     *
     * @return string the path of the creates zip file
     */
    private function _createFile(array $issueIds): string
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
                if (!($issue = Repo::issue()->get($issueId, $this->_context->getId()))) {
                    throw new Exception(__('plugins.importexport.portico.export.failure.loadingIssue', ['issueId' => $issueId]));
                }

                // add submission XML
                $submissionCollector = Repo::submission()->getCollector();
                $submissions = $submissionCollector
                    ->filterByContextIds([$this->_context->getId()])
                    ->filterByIssueIds([$issueId])
                    ->orderBy($submissionCollector::ORDERBY_SEQUENCE, $submissionCollector::ORDER_DIR_ASC)
                    ->getMany();
                foreach ($submissions as $article) {
                    $document = new PorticoExportDom($this->_context, $issue, $article);
                    $articlePathName = $article->getId() . '/' . $article->getId() . '.xml';
                    if (!$zip->addFromString($articlePathName, $document)) {
                        error_log("Unable add ${articlePathName} to Portico ZIP");
                        throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
                    }

                    // add galleys
                    $fileService = Services::get('file');
                    $archivePath = rtrim(Config::getVar('files', 'files_dir'), '/');

                    foreach ($article->getGalleys() as $galley) {
                        $submissionFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
                        if (!$submissionFile) {
                            continue;
                        }

                        $filePath = $archivePath.'/'.$fileService->get($submissionFile->getData('fileId'))->path;

                        if (!file_exists($filePath)) {
                            error_log("File does not exist: ".$filePath);
                            throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
                        }
                    
                        if (!$zip->addFile($filePath, $article->getId() . '/' . basename($filePath))) {
                            error_log("Unable add file to Portico ZIP: ".$filePath);
                            throw new Exception(__('plugins..importexport.portico.export.failure.creatingFile'));
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
            AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
            $form = new PorticoSettingsForm($this, $request->getContext()->getId());

            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager = new NotificationManager();
                    $notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_SUCCESS);
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
    public function register($category, $path, $mainContextId = null)
    {
        $isRegistered = parent::register($category, $path, $mainContextId);
        $this->addLocaleData();
        return $isRegistered;
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName()
    {
        return __CLASS__;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.importexport.portico.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.importexport.portico.description.short');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\importexport\portico\PorticoExportPlugin', '\PorticoExportPlugin');
}
