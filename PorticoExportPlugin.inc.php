<?php

/**
 * @file PorticoExportPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportPlugin
 * @brief Portico export plugin
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');

class PorticoExportPlugin extends ImportExportPlugin {
	/** @var Context the current context */
	private $_context;

	/**
	 * @copydoc ImportExportPlugin::display()
	 */
	public function display($args, $request) {
		$this->_context = $request->getContext();

		parent::display($args, $request);
		$templateManager = TemplateManager::getManager();

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
					}
					finally {
						unlink($path);
					}
				}
				catch (Exception $e) {
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
	 * @return string
	 */
	private function _createFilename() : string {
		return $this->_context->getLocalizedSetting('acronym') . '_batch_' . date('Y-m-d-H-i-s') . '.zip';
	}

	/**
 	 * Downloads a zip file with the selected issues
 	 * @param string $path the path of the zip file
	 */
	private function _download(string $path) : void {
		header('content-type: application/zip');
		header('content-disposition: attachment; filename=' . $this->_createFilename());
		header('content-length: ' . filesize($path));
		readfile($path);
	}

	/**
	 * Return a list of deposit endpoints.
	 * @return array
	 */
	public function getEndpoints($contextId) {
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
 	 * @param string $path the path of the zip file
	 */
	private function _export(string $path) : void {
		$endpoints = $this->getEndpoints($this->_context->getId());

		// Verify that the credentials are complete
		foreach ($endpoints as $credentials) {
			if (empty($credentials['type']) || empty($credentials['hostname'])) {
				throw new Exception(__('plugins.importexport.portico.export.failure.settings'));
			}
		}

		// Perform the deposit
		require_once(dirname(__FILE__) . '/vendor/autoload.php');
		foreach ($endpoints as $credentials) {
			switch ($credentials['type']) {
				case 'ftp':
					$adapter = new League\Flysystem\Ftp\FtpAdapter(
						League\Flysystem\Ftp\FtpConnectionOptions::fromArray([
							'host' => $credentials['hostname'],
							'username' => $credentials['username'],
							'password' => $credentials['password'],
							'root' => $credentials['path'],
						])
					);
					break;
				case 'loc':
				case 'portico':
				case 'sftp':
					$adapter = new League\Flysystem\PhpseclibV2\SftpAdapter(
						new League\Flysystem\PhpseclibV2\SftpConnectionProvider(
							$credentials['hostname'],
							$credentials['username'],
							$credentials['password'],
						),
						$credentials['path']
					);
					break;
				default: throw new Exception('Unknown endpoint type!');
			}
			$fs = new League\Flysystem\Filesystem($adapter);
			$fp = fopen($path, 'r');
			$fs->writeStream($this->_createFilename(), $fp);
			fclose($fp);
		}
	}

	/**
 	 * Creates a zip file with the given issues
	 * @param array $issueIds
	 * @return string the path of the creates zip file
	 */
	private function _createFile(array $issueIds) : string {
		$this->import('PorticoExportDom');

		// create zip file
		$path = tempnam(sys_get_temp_dir(), 'tmp');
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE) !== true) {
			error_log('Unable to create Portico ZIP: ' . $zip->getStatusString());
			throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
		}
		try {
			$issueDao = DAORegistry::getDAO('IssueDAO');
			$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
			foreach ($issueIds as $issueId) {
				if (!($issue = $issueDao->getById($issueId, $this->_context->getId()))) {
					throw new Exception(__('plugins.importexport.portico.export.failure.loadingIssue', ['issueId' => $issueId]));
				}

				// add submission XML
				foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
					$document = new PorticoExportDom($this->_context, $issue, $article);
					$articlePathName = $article->getId() . '/' . $article->getId() . '.xml';
					if (!$zip->addFromString($articlePathName, $document)) {
						error_log("Unable add $articlePathName to Portico ZIP");
						throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
					}

					// add galleys
					foreach ($article->getGalleys() as $galley) {
						if ($submissionFile = $galley->getFile()) {
							if (file_exists($filePath = $submissionFile->getFilePath())) {
								if (!$zip->addFile($filePath, $article->getId() . '/' . $submissionFile->getClientFileName())) {
									error_log("Unable add file $filePath to Portico ZIP");
									throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
								}
							}
						}
					}
				}
			}
		}
		finally {
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
	public function manage($args, $request) {
		if ($request->getUserVar('verb') == 'settings') {
			$user = $request->getUser();
			$this->addLocaleData();
			AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
			$this->import('PorticoSettingsForm');
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
	public function executeCLI($scriptName, &$args){
	}

	/**
	 * @copydoc ImportExportPlugin::usage()
	 */
	public function usage($scriptName){
	}

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$isRegistered = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();
		return $isRegistered;
	}

	/**
	 * @copydoc Plugin::getName()
	 */
	public function getName() {
		return __CLASS__;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.importexport.portico.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.importexport.portico.description.short');
	}
}
