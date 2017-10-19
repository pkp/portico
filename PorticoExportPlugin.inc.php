<?php

/**
 * @file plugins/importexport/portico/PorticoExportPlugin.inc.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PorticoExportPlugin
 * @ingroup plugins_importexport_portico
 *
 * @brief Portico export plugin
 */

import('classes.plugins.ImportExportPlugin');

import('lib.pkp.classes.xml.XMLCustomWriter');

class PorticoExportPlugin extends ImportExportPlugin {
	/** @var $parentPluginName string Name of parent plugin */
	var $parentPluginName;
	
	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		return $success;
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	function getName() {
		return 'PorticoExportPlugin';
	}

	function getDisplayName() {
		return __('plugins.importexport.portico.displayName');
	}

	function getDescription() {
		return __('plugins.importexport.portico.description.short');
	}

	/**
	 * Get the portico plugin
	 * @return object
	 */
	function &getPorticoPlugin() {
		$plugin =& PluginRegistry::getPlugin('importexport', $this->parentPluginName);
		return $plugin;
	}

	/**
	 * Display verbs for the management interface.
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array(
				'disable',
				__('manager.plugins.disable')
			);
			$verbs[] = array(
				'settings',
				__('plugins.importexport.portico.settings')
			);
		} elseif ($this->getSupported()) {
			$verbs[] = array(
				'enable',
				__('manager.plugins.enable')
			);
		}
		return $verbs;
	}

	/**
	 * Check whether or not this plugin is enabled
	 * @return boolean
	 */
	function getEnabled() {
		$journal =& Request::getJournal();
		$journalId = $journal?$journal->getId():0;
		return $this->getSetting($journalId, 'enabled');
	}

	/**
	 * Determine whether or not this plugin is supported.
	 * @return boolean
	 */
	function getSupported() {
		return class_exists('ZipArchive');
	}

	function display(&$args, $request) {
		$templateMgr =& TemplateManager::getManager();
		parent::display($args, $request);
		$issueDao =& DAORegistry::getDAO('IssueDAO');

		$journal =& $request->getJournal();
		
		// set the issn and abbreviation template variables
		if ($journal->getSetting("onlineIssn")) {
			$templateMgr->assign('issn', $journal->getSetting("onlineIssn"));
		}
		elseif ($journal->getSetting("printIssn")) {
			$templateMgr->assign('issn', $journal->getSetting("printIssn"));
		}
		
		if ($journal->getLocalizedSetting("abbreviation")) {
			$templateMgr->assign('abbreviation', $journal->getLocalizedSetting("abbreviation"));
		}
		
		switch (array_shift($args)) {
			case 'exportIssues':
				if ($request->getUserVar('export') == 'download') {
					$issueIds = $request->getUserVar('issueId');
					if (!isset($issueIds)) $issueIds = array();
					$issues = array();
					foreach ($issueIds as $issueId) {
						$issue =& $issueDao->getIssueById($issueId, $journal->getId());
						if (!$issue) $request->redirect();
						$issues[] =& $issue;
						unset($issue);
					}
					$this->exportIssues($journal, $issues);
					break;	
				}
				elseif ($request->getUserVar('export') == 'ftp') {
					$issueIds = $request->getUserVar('issueId');
					if (!isset($issueIds)) $issueIds = array();
					$issues = array();
					foreach ($issueIds as $issueId) {
						$issue =& $issueDao->getIssueById($issueId, $journal->getId());
						if (!$issue) $request->redirect();
						$issues[] =& $issue;
						unset($issue);
					}
					$this->ftpIssues($journal, $issues);
					break;
				}
				
			case 'exportIssue':
				$issueId = array_shift($args);
				$issue =& $issueDao->getIssueById($issueId, $journal->getId());
				if (!$issue) $request->redirect();
				$this->exportIssue($journal, $issue);
				break;
			case 'ftpIssues':
				$issueIds = $request->getUserVar('issueId');
				if (!isset($issueIds)) $issueIds = array();
				$issues = array();
				foreach ($issueIds as $issueId) {
					$issue =& $issueDao->getIssueById($issueId, $journal->getId());
					if (!$issue) $request->redirect();
					$issues[] =& $issue;
					unset($issue);
				}
				$this->ftpIssues($journal, $issues);
				break;
			case 'ftpIssue':
				$issueId = array_shift($args);
				$issue =& $issueDao->getIssueById($issueId, $journal->getId());
				if (!$issue) $request->redirect();
				$this->ftpIssue($journal, $issue);
				break;
			case 'issues':
				// Display a list of issues for export
				$this->setBreadcrumbs(array(), true);
				AppLocale::requireComponents(LOCALE_COMPONENT_OJS_EDITOR);
				$issueDao =& DAORegistry::getDAO('IssueDAO');
				$issues =& $issueDao->getIssues($journal->getId(), Handler::getRangeInfo('issues'));

				$templateMgr->assign_by_ref('issues', $issues);
				$templateMgr->display($this->getTemplatePath() . 'issues.tpl');
				break;
			default:
				$this->setBreadcrumbs();
				$templateMgr->display($this->getTemplatePath() . 'index.tpl');
		}
	}

	function exportIssues(&$journal, &$issues) {
		$this->import('PorticoExportDom');
		$doc =& XMLCustomWriter::createDocument('issues');
		$issuesNode =& XMLCustomWriter::createElement($doc, 'issues');
		XMLCustomWriter::appendChild($doc, $issuesNode);

		// create zip file
		$zipName = $journal->getLocalizedSetting('initials') . '_batch_' . date('Y-m-d') . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);

		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');

		foreach ($issues as $issue) {				
			// add article XML
			foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
				$articlePathName = $article->getId() . '/' . $article->getId() . ".xml";
				$section = $sectionDao->getSection($article->getSectionId());
				$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
				$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
				XMLCustomWriter::appendChild($doc, $articleNode);
				$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
				// add galleys
				import('classes.file.ArticleFileManager');
				$articleFileManager = new ArticleFileManager($article->getId());
				foreach ($article->getGalleys() as $galley) {
					$galleyId = $galley->getId();
				
					$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
					if ($journal->getSetting('enablePublicGalleyId')) {
						$galley =& $galleyDao->getGalleyByBestGalleyId($galleyId, $article->getId());
					} else {
						$galley =& $galleyDao->getGalley($galleyId, $article->getId());
					}

					if ($article && $galley) {
						$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO');
						$articleFile =& $articleFileDao->getArticleFile($galley->getFileId(), null, $article->getId());
				
						if (isset($articleFile)) {
							$fileType = $articleFile->getFileType();
							$filePath = $articleFile->getFilePath();
							if(file_exists($filePath))
							{
								$filename = $articleFile->getFileName();
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
						
						// add additional HTML files
						$isHtml = $galley->isHTMLGalley();
						
						if ($isHtml) {
							$styleFile = $galley->getStyleFile();
							if ($styleFile) {
								if (isset($styleFile)) {
									$styleFileType = $styleFile->getFileType();
									$styleFilePath = $styleFile->getFilePath();
									if(file_exists($styleFilePath)) {
										$styleFilename = $styleFile->getFileName();
										$zip->addFile($styleFilePath, $article->getId() . '/' . $styleFilename);
									}
								}
							}
							
							foreach ($galley->getImageFiles() as $imageFile) {
								if (isset($imageFile)) {
									$imageFileType = $imageFile->getFileType();
									$imageFilePath = $imageFile->getFilePath();
									if(file_exists($imageFilePath)) {
										$imageFilename = $imageFile->getFileName();
										$zip->addFile($imageFilePath, $article->getId() . '/' . $imageFilename);	
									}
								}
							}	
						}
					}
				}
			
				// add Supplementary Files
				$article_suppfiles = $article->getSuppFiles();
		
				foreach ($article_suppfiles as $key => $sup) {
					$suppId = $sup->getId();
					$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
					if ($journal->getSetting('enablePublicSuppFileId')) {
						$suppFile =& $suppFileDao->getSuppFileByBestSuppFileId($article->getId(), $suppId);
					} else {
						$suppFile =& $suppFileDao->getSuppFile((int) $suppId, $article->getId());
					}
			
					if ($article && $suppFile) {
						import('classes.file.ArticleFileManager');
						$articleFileManager = new ArticleFileManager($article->getId());
			
						$suppFileName = $article->getBestArticleId($journal).'-SUP'.($suppFile->getSequence()+1);
			
						$articleFile =& $articleFileManager->getFile($suppFile->getFileId(),null,false,$suppFileName);
			
						if (isset($articleFile)) {
							$fileType = $articleFile->getFileType();
							$filePath = $articleFile->getFilePath();
							if(file_exists($filePath)) {
								$filename = $suppFile->getFileName();
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			}
		}
		$zip->close();
		header('Content-Type: application/zip');
		header('Content-disposition: attachment; filename=' . $zipName);
		header('Content-Length: ' . filesize($zipName));
		readfile($zipName);
		unlink($zipName);
		return true;
	}

	function exportIssue(&$journal, &$issue) {
		$this->import('PorticoExportDom');
		
		// create zip file

		// build zipName filename
		$zipName = $journal->getLocalizedSetting('initials');
		if ($issue->getVolume()) {
			$zipName .= '_' . $issue->getVolume();
		}
		if ($issue->getNumber()) {
			$zipName .= '_' . $issue->getNumber();
		}
		if ($issue->getYear()){
			$zipName .= '_' . $issue->getYear();
		}
		$zipName .= '_' . date('Y-m-d') . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);
		
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		
		// add article XML
		foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
			$articlePathName = $article->getId() . '/' . $article->getId() . ".xml";
			$section = $sectionDao->getSection($article->getSectionId());
			$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
			$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
			XMLCustomWriter::appendChild($doc, $articleNode);
			$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
			// add galleys
			import('classes.file.ArticleFileManager');
			$articleFileManager = new ArticleFileManager($article->getId());
			foreach ($article->getGalleys() as $galley) {
				$galleyId = $galley->getId();
				
				$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				if ($journal->getSetting('enablePublicGalleyId')) {
					$galley =& $galleyDao->getGalleyByBestGalleyId($galleyId, $article->getId());
				} else {
					$galley =& $galleyDao->getGalley($galleyId, $article->getId());
				}

				if ($article && $galley) {
					$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO');
					$articleFile =& $articleFileDao->getArticleFile($galley->getFileId(), null, $article->getId());
				
					if (isset($articleFile)) {
						$fileType = $articleFile->getFileType();
						$filePath = $articleFile->getFilePath();
						if(file_exists($filePath))
						{
							$filename = $articleFile->getFileName();
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
						
						// add additional HTML files
						$isHtml = $galley->isHTMLGalley();
						
						if ($isHtml) {
							$styleFile = $galley->getStyleFile();
							if ($styleFile) {
								if (isset($styleFile)) {
									$styleFileType = $styleFile->getFileType();
									$styleFilePath = $styleFile->getFilePath();
									if(file_exists($styleFilePath)) {
										$styleFilename = $styleFile->getFileName();
										$zip->addFile($styleFilePath, $article->getId() . '/' . $styleFilename);
									}
								}
							}
							
							foreach ($galley->getImageFiles() as $imageFile) {
								if (isset($imageFile)) {
									$imageFileType = $imageFile->getFileType();
									$imageFilePath = $imageFile->getFilePath();
									if(file_exists($imageFilePath)) {
										$imageFilename = $imageFile->getFileName();
										$zip->addFile($imageFilePath, $article->getId() . '/' . $imageFilename);
									}
								}
							}	
						}
					}
				}
			}
			
			// add Supplementary Files
			$article_suppfiles = $article->getSuppFiles();
		
			foreach ($article_suppfiles as $key => $sup) {
				$suppId = $sup->getId();
				$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
				if ($journal->getSetting('enablePublicSuppFileId')) {
					$suppFile =& $suppFileDao->getSuppFileByBestSuppFileId($article->getId(), $suppId);
				} else {
					$suppFile =& $suppFileDao->getSuppFile((int) $suppId, $article->getId());
				}
			
				if ($article && $suppFile) {
					import('classes.file.ArticleFileManager');
					$articleFileManager = new ArticleFileManager($article->getId());
			
					$suppFileName = $article->getBestArticleId($journal).'-SUP'.($suppFile->getSequence()+1);
			
					$articleFile =& $articleFileManager->getFile($suppFile->getFileId(),null,false,$suppFileName);
			
					if (isset($articleFile)) {
						$fileType = $articleFile->getFileType();
						$filePath = $articleFile->getFilePath();
						if(file_exists($filePath)) {
							$filename = $suppFile->getFileName();
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
		}
		$zip->close();

		header('Content-Type: application/zip');
		header('Content-disposition: attachment; filename=' . $zipName);
		header('Content-Length: ' . filesize($zipName));
		readfile($zipName);
		unlink($zipName);
		return true;
	}

    /**
     * Close FTP Connection
     * @param $conn_id
     */
    function closeFtpConnection(&$conn_id)
    {
        ftp_close($conn_id);
    }

    /**
     * Remove zip file from root directory
     */
    function cleanUpZipFile($fileName)
    {
       	unlink($fileName);
    }

	function ftpIssue(&$journal, &$issue) {
		// FTP credentials
		$journalId = $journal->getId();
		
		$ftp_server = $this->getSetting($journalId, 'porticoHost');
		$ftp_user_name = $this->getSetting($journalId, 'porticoUsername');
		$ftp_user_pass = $this->getSetting($journalId, 'porticoPassword');
		
		$this->import('PorticoExportDom');
		
		// create zip file
		$zipName = $journal->getLocalizedSetting('initials');
		if ($issue->getVolume()) {
			$zipName .= '_' . $issue->getVolume();
		}
		if ($issue->getNumber()) {
			$zipName .= '_' . $issue->getNumber();
		}
		if ($issue->getYear()){
			$zipName .= '_' . $issue->getYear();
		}
		$zipName .= '_' . date('Y-m-d') . '.zip';

		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);
		
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		
		// add article XML
		foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
			$articlePathName = $article->getId() . '/' . $article->getId() . ".xml";
			$section = $sectionDao->getSection($article->getSectionId());
			$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
			$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
			XMLCustomWriter::appendChild($doc, $articleNode);
			$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
			// add galleys
			import('classes.file.ArticleFileManager');
			$articleFileManager = new ArticleFileManager($article->getId());
			foreach ($article->getGalleys() as $galley) {
				$galleyId = $galley->getId();
				
				$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				if ($journal->getSetting('enablePublicGalleyId')) {
					$galley =& $galleyDao->getGalleyByBestGalleyId($galleyId, $article->getId());
				} else {
					$galley =& $galleyDao->getGalley($galleyId, $article->getId());
				}

				if ($article && $galley) {
					$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO');
					$articleFile =& $articleFileDao->getArticleFile($galley->getFileId(), null, $article->getId());
				
					if (isset($articleFile)) {
						$fileType = $articleFile->getFileType();
						$filePath = $articleFile->getFilePath();
						if(file_exists($filePath))
						{
							$filename = $articleFile->getFileName();
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
			
			// add Supplementary Files
			$article_suppfiles = $article->getSuppFiles();
		
			foreach ($article_suppfiles as $key => $sup) {
				$suppId = $sup->getId();
				$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
				if ($journal->getSetting('enablePublicSuppFileId')) {
					$suppFile =& $suppFileDao->getSuppFileByBestSuppFileId($article->getId(), $suppId);
				} else {
					$suppFile =& $suppFileDao->getSuppFile((int) $suppId, $article->getId());
				}
			
				if ($article && $suppFile) {
					import('classes.file.ArticleFileManager');
					$articleFileManager = new ArticleFileManager($article->getId());
			
					$suppFileName = $article->getBestArticleId($journal).'-SUP'.($suppFile->getSequence()+1);
			
					$articleFile =& $articleFileManager->getFile($suppFile->getFileId(),null,false,$suppFileName);
			
					if (isset($articleFile)) {
						$fileType = $articleFile->getFileType();
						$filePath = $articleFile->getFilePath();
						if(file_exists($filePath)) {
							$filename = $suppFile->getFileName();
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
		}
		$zip->close();
		
		// set up basic connection 
		$conn_id = ftp_connect($ftp_server); 

		// login with username and password 
		$login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass); 
		
		ftp_pasv($conn_id, true);

		// upload a file
		$templateMgr =& TemplateManager::getManager();
		if (ftp_put($conn_id, $zipName, $zipName, FTP_BINARY)) {
			$this->closeFtpConnection($conn_id);
			$this->cleanUpZipFile($zipName);
			return $templateMgr->display($this->getTemplatePath() . 'exportSuccess.tpl');
			exit; 
		} else {
            $this->closeFtpConnection($conn_id);
            $this->cleanUpZipFile($zipName);
			return $templateMgr->display($this->getTemplatePath() . 'exportFailure.tpl');
			exit; 
		} 

		return true;
	}
	
	function ftpIssues(&$journal, &$issues) {
		// FTP credentials
		$journalId = $journal->getId();
		
		$ftp_server = $this->getSetting($journalId, 'porticoHost');
		$ftp_user_name = $this->getSetting($journalId, 'porticoUsername');
		$ftp_user_pass = $this->getSetting($journalId, 'porticoPassword');
		
		$this->import('PorticoExportDom');
		$doc =& XMLCustomWriter::createDocument('issues');
		$issuesNode =& XMLCustomWriter::createElement($doc, 'issues');
		XMLCustomWriter::appendChild($doc, $issuesNode);

		// create zip file
		$zipName = $journal->getLocalizedSetting('initials') . '_batch_' . date('Y-m-d') . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);

		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');

		foreach ($issues as $issue) {				
			// add article XML
			foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
				$articlePathName = $article->getId() . '/' . $article->getId() . ".xml";
				$section = $sectionDao->getSection($article->getSectionId());
				$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
				$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
				XMLCustomWriter::appendChild($doc, $articleNode);
				$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
				// add galleys
				import('classes.file.ArticleFileManager');
				$articleFileManager = new ArticleFileManager($article->getId());
				foreach ($article->getGalleys() as $galley) {
					$galleyId = $galley->getId();
				
					$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
					if ($journal->getSetting('enablePublicGalleyId')) {
						$galley =& $galleyDao->getGalleyByBestGalleyId($galleyId, $article->getId());
					} else {
						$galley =& $galleyDao->getGalley($galleyId, $article->getId());
					}

					if ($article && $galley) {
						$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO');
						$articleFile =& $articleFileDao->getArticleFile($galley->getFileId(), null, $article->getId());
				
						if (isset($articleFile)) {
							$fileType = $articleFile->getFileType();
							$filePath = $articleFile->getFilePath();
							if(file_exists($filePath))
							{
								$filename = $articleFile->getFileName();
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			
				// add Supplementary Files
				$article_suppfiles = $article->getSuppFiles();
		
				foreach ($article_suppfiles as $key => $sup) {
					$suppId = $sup->getId();
					$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
					if ($journal->getSetting('enablePublicSuppFileId')) {
						$suppFile =& $suppFileDao->getSuppFileByBestSuppFileId($article->getId(), $suppId);
					} else {
						$suppFile =& $suppFileDao->getSuppFile((int) $suppId, $article->getId());
					}
			
					if ($article && $suppFile) {
						import('classes.file.ArticleFileManager');
						$articleFileManager = new ArticleFileManager($article->getId());
			
						$suppFileName = $article->getBestArticleId($journal).'-SUP'.($suppFile->getSequence()+1);
			
						$articleFile =& $articleFileManager->getFile($suppFile->getFileId(),null,false,$suppFileName);
			
						if (isset($articleFile)) {
							$fileType = $articleFile->getFileType();
							$filePath = $articleFile->getFilePath();
							if(file_exists($filePath)) {
								$filename = $suppFile->getFileName();
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			}
		}
		$zip->close();
				
		// set up basic connection 
		$conn_id = ftp_connect($ftp_server); 

		// login with username and password 
		$login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass); 
		
		ftp_pasv($conn_id, true);

		// upload a file
		$templateMgr =& TemplateManager::getManager();
		if (ftp_put($conn_id, $zipName, $zipName, FTP_BINARY)) {
            $this->closeFtpConnection($conn_id);
            $this->cleanUpZipFile($zipName);
			return $templateMgr->display($this->getTemplatePath() . 'exportSuccess.tpl');
			exit; 
		} else {
            $this->closeFtpConnection($conn_id);
            $this->cleanUpZipFile($zipName);
			return $templateMgr->display($this->getTemplatePath() . 'exportFailure.tpl');
			exit; 
		}
	
		return true;
	}
	
 	/*
 	 * Execute a management verb on this plugin
 	 * @param $verb string
 	 * @param $args array
	 * @param $message string Result status message
	 * @param $messageParams array Parameters for status message
	 * @return boolean
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		$returner = true;
		$journal =& Request::getJournal();
		$this->addLocaleData();

		switch ($verb) {
			case 'settings':
				AppLocale::requireComponents(LOCALE_COMPONENT_APPLICATION_COMMON,  LOCALE_COMPONENT_PKP_MANAGER);
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));

				$this->import('PorticoSettingsForm');
				$form = new PorticoSettingsForm($this, $journal->getId());

				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						Request::redirect(null, null, null, array('importexport', $this->getName(), 'settings'));
					} else {
						$form->display();
					}
				} else {
					$form->initData();
					$form->display();
				}
				break;
			case 'enable':
				$this->updateSetting($journal->getId(), 'enabled', true);
				$message = NOTIFICATION_TYPE_PLUGIN_ENABLED;
				$messageParams = array('pluginName' => $this->getDisplayName());
				$returner = false;
				break;
			case 'disable':
				$this->updateSetting($journal->getId(), 'enabled', false);
				$message = NOTIFICATION_TYPE_PLUGIN_DISABLED;
				$messageParams = array('pluginName' => $this->getDisplayName());
				$returner = false;
				break;
		}

		return $returner;
	}
}

?>
