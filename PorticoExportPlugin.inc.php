<?php

/**
 * @file plugins/importexport/portico/PorticoExportPlugin.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportPlugin
 * @ingroup plugins_importexport_portico
 *
 * @brief Portico export plugin
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');

class PorticoExportPlugin extends ImportExportPlugin {
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

	/**
	 * @copydoc Plugin::getTemplatePath($inCore)
	 */
	function getTemplatePath($inCore = false) {
		return parent::getTemplatePath($inCore) . 'templates/';
	}

	/**
	 * @copydoc ImportExportPlugin::display()
	 */
	public function display($args, $request) {
		parent::display($args, $request);
		$templateManager = TemplateManager::getManager();
		$journal = $request->getContext();

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
					$path = $this->createFile($journal, $issueIds);
					if ($request->getUserVar('type') == 'ftp') {
						$this->export($journal, $path);
						$templateManager->assign('porticoSuccessMessage', __('plugins.importexport.portico.export.success'));
					} else {
						$this->download($journal, $path);
					}
				}
				catch (Exception $e) {
					$templateManager->assign('porticoErrorMessage', $e->getMessage());
				}
				break;
		}

		// set the issn and abbreviation template variables
		foreach (['onlineIssn', 'printIssn', 'issn'] as $name) {
			if ($value = $journal->getSetting($name)) {
				$templateManager->assign('issn', $value);
				break;
			}
		}

		if ($value = $journal->getLocalizedSetting('abbreviation')) {
			$templateManager->assign('abbreviation', $value);
		}

		$templateManager->display($this->getTemplatePath() . 'index.tpl');
	}

	/**
 	 * Generates a filename for the exported file
 	 * @param $journal Journal
	 * @return string
	 */
	private function createFilename(Journal $journal) {
		return $journal->getLocalizedSetting('acronym') . '_batch_' . date('Y-m-d-H-i-s') . '.zip';
	}

	/**
 	 * Downloads a zip file with the selected issues
 	 * @param $journal Journal
 	 * @param $path string the path of the zip file
	 */
	private function download(Journal $journal, $path) {
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename=' . $this->createFilename($journal));
		header('Content-Length: ' . filesize($path));
		readfile($path);
		unlink($path);
	}

	/**
 	 * Exports a zip file with the selected issues to the configured Portico account
 	 * @param $journal Journal
 	 * @param $path string the path of the zip file
	 */
	private function export(Journal $journal, $path) {
		$journalId = $journal->getId();
		$credentials = (object)[
			'server' => $this->getSetting($journalId, 'porticoHost'),
			'user' => $this->getSetting($journalId, 'porticoUsername'),
			'password' => $this->getSetting($journalId, 'porticoPassword')
		];
		foreach($credentials as $parameter) {
			if(!strlen($parameter)) {
				throw new Exception(__('plugins.importexport.portico.export.failure.settings'));
			}
		}
		if(!($ftp = ftp_connect($credentials->server))) {
			throw new Exception(__('plugins.importexport.portico.export.failure.connection', ['host' => $credentials->server]));
		}
		if(!ftp_login($ftp, $credentials->user, $credentials->password)) {
			throw new Exception(__('plugins.importexport.portico.export.failure.credentials'));
		}
		ftp_pasv($ftp, true);
		if (!ftp_put($ftp, $this->createFilename($journal), $path, FTP_BINARY)) {
			throw new Exception(__('plugins.importexport.portico.export.failure.general'));
		}
		ftp_close($ftp);
		unlink($zipName);
	}

	/**
 	 * Creates a zip file with the given issues
 	 * @param $journal Journal
	 * @param $issueIds array
	 * @return string the path of the creates zip file
	 */
	private function createFile(Journal $journal, $issueIds) {
		import('lib.pkp.classes.xml.XMLCustomWriter');
		import('lib.pkp.classes.file.SubmissionFileManager');
		$this->import('PorticoExportDom');

		// create zip file
		$path = tempnam(sys_get_temp_dir(), 'tmp');
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE) !== true) {
			throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
		}

		$issueDao = DAORegistry::getDAO('IssueDAO');
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$galleyDao = DAORegistry::getDAO('ArticleGalleyDAO');
		$submissionFileDAO = DAORegistry::getDAO('SubmissionFileDAO');

		foreach ($issueIds as $issueId) {
			if (!($issue = $issueDao->getById($issueId, $journal->getId()))) {
				throw new Exception(__('plugins.importexport.portico.export.failure.loadingIssue', ['issueId' => $issueId]));
			}

			// add submission XML
			foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
				$doc = XMLCustomWriter::createDocument('article', PorticoExportDom::PUBMED_DTD_ID, PorticoExportDom::PUBMED_DTD_URL);
				$articleNode = PorticoExportDom::generateArticleDom($doc, $journal, $issue, $article);
				XMLCustomWriter::appendChild($doc, $articleNode);
				$articlePathName = $article->getId() . '/' . $article->getId() . '.xml';
				if (!$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc))) {
					throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
				}

				// add galleys
				foreach ($article->getGalleys() as $galley) {
					$galleyId = $galley->getId();

					$galley = $journal->getSetting('enablePublicGalleyId')
						? $galleyDao->getByBestGalleyId($galleyId, $article->getId())
						: $galleyDao->getById($galleyId, $article->getId());

					if ($galley && ($submissionFile = $submissionFileDAO->getLatestRevision($galley->getFileId(), null, $article->getId()))) {
						if (file_exists($filePath = $submissionFile->getFilePath()))
						{
							if (!$zip->addFile($filePath, $article->getId() . '/' . $submissionFile->getLocalizedName())) {
								throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
							}
						}
					}
				}

				// add supplementary files
				$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
				$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();
				foreach ($suppFiles as $suppFile) {
					$suppId = $suppFile->getFileId();
					$suppFile = $journal->getSetting('rtSupplementaryFiles')
						? $submissionFileDAO->getLatestRevision((int) $suppId, null, $article->getId())
						: $submissionFileDAO->getByBestId((int) $suppId, $article->getId());
					if ($suppFile) {
						if ($articleFile = $submissionFileManager->_getFile($suppFile->getFileId())) {
							if(file_exists($filePath = $articleFile->getFilePath())) {
								$filename = $suppFile->getOriginalFileName();
								if (!$zip->addFile($filePath, $article->getId() . '/' . $filename)) {
									throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
								}
							}
						}
					}
				}
			}
		}
		if (!$zip->close()) {
			throw new Exception(__('plugins.importexport.portico.export.failure.creatingFile'));
		}
		return $path;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	public function manage($args, $request) {
		if ($request->getUserVar('verb') == 'settings') {
			$user = $request->getUser();
			$journal = $request->getJournal();
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
					return new JSONMessage();
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
}
