<?php

/**
 * @file plugins/importexport/portico/PorticoExportDom.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportDom
 * @ingroup plugins_importexport_portico
 *
 * @brief Portico export plugin DOM functions for export
 */

import('lib.pkp.classes.xml.XMLCustomWriter');

class PorticoExportDom extends XMLCustomWriter {
	/** @var string DTD URL of the exported XML */
	const PUBMED_DTD_URL = 'http://dtd.nlm.nih.gov/archiving/3.0/archivearticle3.dtd';

	/** @var string DTD ID of the exported XML */
	const PUBMED_DTD_ID = '-//NLM//DTD Journal Publishing DTD v3.0 20080202//EN';

	/**
	 * Generate the Article node.
	 * @param $doc DOMDocument
	 * @param $journal Journal
	 * @param $issue Issue
	 * @param $article PublishedArticle
	 * @return DOMElement
	 */
	public function generateArticleDom(DOMDocument $doc, Journal $journal, Issue $issue, PublishedArticle $article) {
		/* --- Article --- */
		$root = self::createElement($doc, 'article');
		self::setAttribute($root, 'xmlns:xlink', 'http://www.w3.org/1999/xlink');

		/* --- Front --- */
		$articleNode = self::createElement($doc, 'front');
		self::appendChild($root, $articleNode);

		/* --- Journal --- */
		$journalMetaNode = self::createElement($doc, 'journal-meta');
		self::appendChild($articleNode, $journalMetaNode);

		// journal-id
		if ($journal->getLocalizedSetting('abbreviation')) {
			self::createChildWithText($doc, $journalMetaNode, 'journal-id', $journal->getLocalizedSetting('abbreviation'));
		}

		//journal-title-group
		$journalTitleGroupNode = self::createElement($doc, 'journal-title-group');
		self::appendChild($journalMetaNode, $journalTitleGroupNode);

		// journal-title
		self::createChildWithText($doc, $journalTitleGroupNode, 'journal-title', $journal->getLocalizedPageHeaderTitle());

		// issn
		foreach (['printIssn', 'issn', 'onlineIssn'] as $name) {
			if ($value = $journal->getSetting($name)) {
				self::createChildWithText($doc, $journalMetaNode, 'issn', $value);
				break;
			}
		}

		// publisher
		$publisherNode = self::createElement($doc, 'publisher');
		self::appendChild($journalMetaNode, $publisherNode);

		// publisher-name
		$publisherInstitution = $journal->getSetting('publisherInstitution');
		$publisherNameNode = self::createChildWithText($doc, $publisherNode, 'publisher-name', $publisherInstitution);

		/* --- End Journal --- */

		/* --- Article-meta --- */
		$articleMetaNode = self::createElement($doc, 'article-meta');
		self::appendChild($articleNode, $articleMetaNode);

		// article-id (DOI)
		if ($doi = $article->getStoredPubId('doi')) {
			$doiNode = self::createChildWithText($doc, $articleMetaNode, 'article-id', $doi, false);
			self::setAttribute($doiNode, 'pub-id-type', 'doi');
		}

		// article-title
		$titleGroupNode = self::createElement($doc, 'title-group');
		self::appendChild($articleMetaNode, $titleGroupNode);
		self::createChildWithText($doc, $titleGroupNode, 'article-title', $article->getLocalizedTitle());

		// authors
		$contribGroupNode = self::createElement($doc, 'contrib-group');
		self::appendChild($articleMetaNode, $contribGroupNode);

		$authorIndex = 0;
		foreach ($article->getAuthors() as $author) {
			$contribNode = self::generateAuthorDom($doc, $author, $authorIndex++);
			self::appendChild($contribGroupNode, $contribNode);
		}

		$datePublished = $article->getDatePublished();
		if (!$datePublished) {
			$datePublished = $issue->getDatePublished();
		}
		if ($datePublished) {
			$pubDateNode = self::generatePubDateDom($doc, $datePublished, 'epublish');
			self::appendChild($articleMetaNode, $pubDateNode);
		}

		// volume, issue, etc.
		if ($issue->getVolume()) {
			self::createChildWithText($doc, $articleMetaNode, 'volume', $issue->getVolume());
		}
		if ($issue->getNumber()) {
			self::createChildWithText($doc, $articleMetaNode, 'issue', $issue->getNumber(), false);
		}

		/* --- fpage / lpage --- */
		// there is some ambiguity for online journals as to what
		// "page numbers" are; for example, some journals (eg. JMIR)
		// use the "e-location ID" as the "page numbers" in PubMed
		$pages = $article->getPages();
		$fpage = $lpage = null;
		if (PKPString::regexp_match_get('/([0-9]+)\s*-\s*([0-9]+)/i', $pages, $matches)) {
			// simple pagination (eg. "pp. 3- 		8")
			list(, $fpage, $lpage) = $matches;
		} elseif (PKPString::regexp_match_get('/(e[0-9]+)\s*-\s*(e[0-9]+)/i', $pages, $matches)) { // e9 - e14, treated as page ranges
			list(, $fpage, $lpage) = $matches;
		} elseif (PKPString::regexp_match_get('/(e[0-9]+)/i', $pages, $matches)) {
			// single elocation-id (eg. "e12")
			$fpage = $lpage = $matches[1];
		} else {
			// we need to insert something, so use the best ID possible
			$fpage = $lpage = $article->getBestArticleId($journal);
		}
		self::createChildWithText($doc, $articleMetaNode, 'fpage', $fpage);
		self::createChildWithText($doc, $articleMetaNode, 'lpage', $lpage);

		/* --- ArticleIdList --- */
		// Pubmed will accept two types of article identifier: pii and doi
		// how this is handled is journal-specific, and will require either
		// configuration in the plugin, or an update to the core code.
		// this is also related to DOI-handling within OJS
		if ($article->getStoredPubId('publisher-id')) {
			$articleIdListNode = self::createElement($doc, 'ArticleIdList');
			self::appendChild($articleNode, $articleIdListNode);

			$articleIdNode = self::createChildWithText($doc, $articleIdListNode, 'article-id', $article->getPubId('publisher-id'));
			self::setAttribute($articleIdNode, 'pub-id-type', 'publisher');
		}

		// supplementary file links
		$galleyDao = DAORegistry::getDAO('ArticleGalleyDAO');
		$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();

		foreach ($suppFiles as $suppFile) {
			$supplementaryMaterialNode = self::createChildWithText($doc, $articleMetaNode, 'supplementary-material', null);
			self::setAttribute($supplementaryMaterialNode, 'xlink:href', $suppFile->getFile()->getLocalizedName());
			self::setAttribute($supplementaryMaterialNode, 'content-type', $suppFile->getFileType());
		}

		// galley links
		import('lib.pkp.classes.file.SubmissionFileManager');
		foreach ($article->getGalleys() as $galley) {
			$selfUriNode = self::createChildWithText($doc, $articleMetaNode, 'self-uri', $galley->getFile()->getLocalizedName());
			self::setAttribute($selfUriNode, 'xlink:href', $galley->getFile()->getLocalizedName());
			self::setAttribute($selfUriNode, 'content-type', $galley->getFileType());
		}

		/* --- Abstract --- */
		if ($article->getLocalizedAbstract()) {
			$abstractNode = self::createElement($doc, 'abstract');
			self::appendChild($articleMetaNode, $abstractNode);
			self::createChildWithText($doc, $abstractNode, 'p', strip_tags($article->getLocalizedAbstract()), false);
		}

		return $root;
	}

	/**
	 * Generate the Author node DOM for the specified author.
	 * @param $doc DOMDocument
	 * @param $author Author
	 * @param $authorIndex 0-based index of current author
	 * @return DOMElement
	 */
	private function generateAuthorDom(DOMDocument $doc, Author $author, $authorIndex) {
		$locale = AppLocale::getLocale();
		$root = self::createElement($doc, 'contrib');
		self::setAttribute($root, 'contrib-type', 'author');

		$nameNode = self::createElement($doc, 'name');
		self::appendChild($root, $nameNode);

		$givenNames = $author->getFirstName();
		if ($author->getMiddleName()) {
			$givenNames .= ' ' . $author->getMiddleName();
		}

		self::createChildWithText($doc, $nameNode, 'surname', $author->getLastName());
		self::createChildWithText($doc, $nameNode, 'given-names', $givenNames);

		if ($authorIndex == 0) {
			// See http://pkp.sfu.ca/bugzilla/show_bug.cgi?id=7774
			self::createChildWithText($doc, $root, 'aff', $author->getLocalizedAffiliation() . '. ' . $author->getEmail(), false);
		}

		return $root;
	}

	/**
	 * Creates pub-date node
	 * @param $doc DOMDocument
	 * @param $pubdate string
	 * @param $pubstatus string
	 * @return DOMElement
	 */
	private function generatePubDateDom(DOMDocument $doc, $pubdate, $pubstatus) {
		$root = self::createElement($doc, 'pub-date');

		self::setAttribute($root, 'pub-type', $pubstatus);

		self::createChildWithText($doc, $root, 'year', date('Y', strtotime($pubdate)) );
		self::createChildWithText($doc, $root, 'month', date('m', strtotime($pubdate)), false );
		self::createChildWithText($doc, $root, 'day', date('d', strtotime($pubdate)), false );

		return $root;
	}

	/**
	 * Formats a date
	 * @param $date string
	 * @param $format string, default value is Y-m-d
	 * @return string Returns the formatted date
	 */
	private function formatDate($date, $format = 'Y-m-d') {
		return empty($date) ? null : date($format, strtotime($date));
	}

	/**
	 * Add ID-nodes to the given node.
	 * @param $doc DOMDocument
	 * @param $node DOMNode
	 * @param $pubObject object
	 * @param $issue Issue
	 */
	private function generatePubId(DOMDocument $doc, DOMNode $node, $pubObject, Issue $issue) {
		$pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $issue->getJournalId());
		if (is_array($pubIdPlugins)) {
			foreach ($pubIdPlugins as $pubIdPlugin) {
				if ($pubId = $pubIdPlugin->getPubId($pubObject)) {
					$pubIdType = $pubIdPlugin->getPubIdType();
					$idNode = self::createChildWithText($doc, $node, 'id', $pubId);
					self::setAttribute($idNode, 'type', $pubIdType);
				}
			}
		}
	}
}
