<?php

/**
 * @file PorticoExportDom.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportDom
 * @brief Portico export plugin DOM functions for export
 */

namespace APP\plugins\importexport\portico;

use APP\author\Author;
use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\section\Section;
use APP\submission\Submission;
use DateTimeImmutable;
use DOMImplementation;
use DOMElement;
use PKP\citation\Citation;
use PKP\citation\CitationDAO;
use PKP\context\Context;
use PKP\controlledVocab\ControlledVocab;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\galley\Galley;

class PorticoExportDom
{
    /** @var string DTD URL of the exported XML */
    private const PUBMED_DTD_URL = 'http://jats.nlm.nih.gov/archiving/1.2/JATS-archivearticle1.dtd';

    /** @var string DTD ID of the exported XML */
    private const PUBMED_DTD_ID = '-//NLM//DTD JATS (Z39.96) Journal Archiving and Interchange DTD v1.2 20190208//EN';

    /** @var Context Context */
    private $_context;

    /** @var Issue Issue */
    private $_issue;

    /** @var Submission Submission */
    private $_article;

    /** @var DOMElement Document node */
    private $_document;

    /** @var Section Section */
    private $_section;

    /**
     * Constructor
     */
    public function __construct(Journal $context, Issue $issue, Submission $article)
    {
        $this->_context = $context;
        $this->_issue = $issue;
        $this->_article = $article;
        if ($sectionId = $this->_article->getSectionId()) {
            $this->_section = Repo::section()->get($sectionId);
        }

        $domImplementation = new DOMImplementation();
        $this->_document = $domImplementation->createDocument(
            '1.0',
            '',
            $domImplementation->createDocumentType('article', self::PUBMED_DTD_ID, self::PUBMED_DTD_URL)
        );
        $articleNode = $this->_buildArticle();
        $this->_document->appendChild($articleNode);
    }

    /**
     * Serializes the document
    */
    public function __toString(): string
    {
        return $this->_document->saveXML();
    }

    /**
     * Generate the Article node.
     */
    private function _buildArticle(): DOMElement
    {
        $journal = $this->_context;
        $doc = $this->_document;
        $article = $this->_article;
        $publication = $article->getCurrentPublication();
        $issue = $this->_issue;
        $section = $this->_section;

        /* --- Article --- */
        $root = $doc->createElement('article');
        $root->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');

        /* --- Front --- */
        $articleNode = $doc->createElement('front');
        $root->appendChild($articleNode);

        /* --- Journal --- */
        $journalMetaNode = $doc->createElement('journal-meta');
        $articleNode->appendChild($journalMetaNode);

        // journal-id
        if (($abbreviation = $journal->getLocalizedData('abbreviation')) != '') {
            $journalMetaNode->appendChild($doc->createElement('journal-id', $abbreviation));
        }

        //journal-title-group
        $journalTitleGroupNode = $doc->createElement('journal-title-group');
        $journalMetaNode->appendChild($journalTitleGroupNode);

        // journal-title
        $journalTitleGroupNode->appendChild($doc->createElement('journal-title', $journal->getName($journal->getPrimaryLocale())));

        // issn
        foreach (['printIssn' => 'print', 'onlineIssn' => 'online-only'] as $name => $format) {
            if ($issn = $journal->getData($name)) {
                $journalMetaNode
                    ->appendChild($doc->createElement('issn', $issn))
                    ->setAttribute('publication-format', $format);
            }
        }

        // publisher
        $publisherNode = $doc->createElement('publisher');
        $journalMetaNode->appendChild($publisherNode);

        // publisher-name
        $publisherInstitution = $journal->getData('publisherInstitution');
        $publisherNode->appendChild($doc->createElement('publisher-name', $publisherInstitution));

        /* --- End Journal --- */

        /* --- Article-meta --- */
        $articleMetaNode = $doc->createElement('article-meta');
        $articleNode->appendChild($articleMetaNode);

        // article-id (DOI)
        if (($doi = $publication->getStoredPubId('doi'))) {
            $doiNode = $doc->createElement('article-id', $doi);
            $doiNode->setAttribute('pub-id-type', 'doi');
            $articleMetaNode->appendChild($doiNode);
        }

        // article-id (PII)
        // Pubmed will accept two types of article identifier: pii and doi
        // how this is handled is journal-specific, and will require either
        // configuration in the plugin, or an update to the core code.
        // this is also related to DOI-handling within OJS
        if ($publisherId = $publication->getStoredPubId('publisher-id')) {
            $publisherIdNode = $doc->createElement('article-id', $publisherId);
            $publisherIdNode->setAttribute('pub-id-type', 'publisher-id');
            $articleMetaNode->appendChild($publisherIdNode);
        }

        if ($section) {
            $subjGroupNode = $articleMetaNode
                ->appendChild($doc->createElement('article-categories'))
                ->appendChild($doc->createElement('subj-group'));
            $subjGroupNode->setAttribute('xml:lang', $journal->getPrimaryLocale());
            $subjGroupNode->setAttribute('subj-group-type', 'heading');
            $subjGroupNode->appendChild($doc->createElement('subject', $section->getLocalizedTitle()));
        }

        // article-title
        $titleGroupNode = $doc->createElement('title-group');
        $articleMetaNode->appendChild($titleGroupNode);
        $titleGroupNode->appendChild($doc->createElement('article-title', $publication->getLocalizedTitle()));

        // authors
        $authorsNode = $this->_buildAuthors();
        $articleMetaNode->appendChild($authorsNode);

        if ($datePublished = $publication->getData('datePublished') ?: $issue->getDatePublished()) {
            $dateNode = $this->_buildPubDate(new DateTimeImmutable($datePublished));
            $articleMetaNode->appendChild($dateNode);
        }

        // volume, issue, etc.
        if ($v = $issue->getVolume()) {
            $articleMetaNode->appendChild($doc->createElement('volume', $v));
        }
        if ($n = $issue->getNumber()) {
            $articleMetaNode->appendChild($doc->createElement('issue', $n));
        }
        $this->_buildPages($articleMetaNode);

        $galleys = $publication->getData('galleys')->all();
        // supplementary-material (the first galley is reserved for the self-uri link)
        foreach (array_slice($galleys, 1) as $galley) {
            if ($supplementNode = $this->_buildSupplementNode($galley)) {
                $articleMetaNode->appendChild($supplementNode);
            } else {
                error_log('Unable to add galley ' . $galley->getData('id') . ' to article ' . $article->getId());
            }
        }

        // self-uri
        if ($galley = reset($galleys)) {
            if ($selfUriNode = $this->_buildSelfUriNode($galley)) {
                $articleMetaNode->appendChild($selfUriNode);
            } else {
                error_log('Unable to add galley ' . $galley->getData('id') . ' to article ' . $article->getId());
            }
        }

        // keywords
        $keywordVocabs = Repo::controlledVocab()->getBySymbolic(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            Application::ASSOC_TYPE_PUBLICATION,
            $publication->getId(),
            $journal->getSupportedLocales()
        );

        foreach ($keywordVocabs as $locale => $keywords) {
            if (empty($keywords)) {
                continue;
            }
            $kwGroup = $articleMetaNode->appendChild($doc->createElement('kwd-group'));
            $kwGroup->setAttribute('xml:lang', substr($locale, 0, 2));
            foreach ($keywords as $keyword) {
                $kwGroup->appendChild($doc->createElement('kwd', $keyword));
            }
        }

        /* --- Abstract --- */
        if ($abstract = strip_tags($publication->getLocalizedData('abstract'))) {
            $abstractNode = $doc->createElement('abstract');
            $articleMetaNode->appendChild($abstractNode);
            $abstractNode->appendChild($doc->createElement('p', $abstract));
        }

        $citationDao = DAORegistry::getDAO('CitationDAO'); /** @var CitationDAO $citationDao */
        $citations = $citationDao->getByPublicationId($publication->getId())->toArray();
        if (count($citations)) {
            $refList = $root
                ->appendChild($doc->createElement('back'))
                ->appendChild($doc->createElement('ref-list'));
            /** @var Citation $citation */
            foreach ($citations as $i => $citation) {
                ++$i;
                $ref = $refList->appendChild($doc->createElement('ref'));
                $ref->setAttribute('id', "R{$i}");
                $ref->appendChild($doc->createElement('mixed-citation', $citation->getRawCitation()));
            }
        }

        return $root;
    }

    /**
     * Retrieve the file information from a galley
     * @return array
     */
    private function _getFileInformation(Galley $galley)
    {
        if (!($fileId = $galley->getData('submissionFileId'))) {
            return null;
        }
        $fileService = Services::get('file');
        $submissionFile = Repo::submissionFile()->get($fileId);
        $file = $fileService->get($submissionFile->getData('fileId'));
        return [
            'path' => $this->_article->getId() . '/' . basename($file->path),
            'mimetype' => $file->mimetype
        ];
    }

    /**
     * Generate the self-uri node of the article.
     */
    private function _buildSelfUriNode(Galley $galley): DOMElement
    {
        $doc = $this->_document;
        $node = null;
        if ($fileInfo = $this->_getFileInformation($galley)) {
            ['path' => $path, 'mimetype' => $mimetype] = $fileInfo;
            $node = $doc->createElement('self-uri', $path);
            $node->setAttribute('content-type', $mimetype);
            $node->setAttribute('xlink:href', $path);
        } elseif ($url = $galley->getData('urlRemote')) {
            $node = $doc->createElement('self-uri', $url);
            $node->setAttribute('xlink:href', $url);
        }
        if ($label = $galley->getLabel()) {
            $node->setAttribute('xlink:title', $label);
        }
        return $node;
    }

    /**
     * Generate a supplementary-material node for a galley.
     */
    private function _buildSupplementNode(Galley $galley): ?DOMElement
    {
        $doc = $this->_document;
        $node = $doc->createElement('supplementary-material');
        if ($fileInfo = $this->_getFileInformation($galley)) {
            ['path' => $path, 'mimetype' => $mimetype] = $fileInfo;
            $node->setAttribute('mimetype', $mimetype);
            $node->setAttribute('xlink:href', $path);
        } elseif ($url = $galley->getData('urlRemote')) {
            $node->setAttribute('xlink:href', $url);
        } else {
            return null;
        }
        if ($label = $galley->getData('label')) {
            $node->setAttribute('xlink:title', $label);
            $captionNode = $node->appendChild($doc->createElement('caption'));
            $captionNode->appendChild($doc->createElement('p', $label));
        }
        return $node;
    }

    /**
     * Generate the Author node DOM for the specified author.
     *
     * @param Author $author Author
     */
    private function _buildAuthor(Author $author): DOMElement
    {
        $doc = $this->_document;
        $locale = Locale::getLocale();

        $root = $this->_document->createElement('contrib');
        $root->setAttribute('contrib-type', 'author');

        $nameNode = $this->_document->createElement('name');
        $root->appendChild($nameNode);

        $nameNode->appendChild($doc->createElement('surname', $author->getFamilyName($locale)));
        $nameNode->appendChild($doc->createElement('given-names', $author->getGivenName($locale)));

        $affiliation = $author->getLocalizedAffiliation();
        if (is_array($affiliation)) {
            $affiliation = reset($affiliation);
        }
        if ($affiliation) {
            $root->appendChild($doc->createElement('aff', $affiliation));
        }
        if ($url = $author->getUrl()) {
            $root->appendChild($doc->createElement('uri', $url));
        }
        if ($orcid = $author->getOrcid()) {
            $orcidNode = $root->appendChild($doc->createElement('contrib-id', $orcid));
            $orcidNode->setAttribute('contrib-id-type', 'orcid');
            $orcidNode->setAttribute('authenticated', $author->hasVerifiedOrcid() ? 'true' : 'false');
        }

        if ($email = $author->getEmail()) {
            $root->appendChild($doc->createElement('email', $email));
        }
        $root->appendChild($doc->createElement('role', 'Author'));
        if ($bio = strip_tags($author->getLocalizedBiography())) {
            $bioNode = $doc->createElement('bio');
            $root->appendChild($bioNode);
            $bioNode->appendChild($doc->createElement('p', $bio));
        }

        if ($country = $author->getCountry()) {
            $addressNode = $this->_document->createElement('address');
            $addressNode->appendChild($doc->createElement('country', $country));
            $root->appendChild($addressNode);
        }

        return $root;
    }

    /**
     * Creates pub-date node
     */
    private function _buildPubDate(DateTimeImmutable $date): DOMElement
    {
        $doc = $this->_document;
        $root = $this->_document->createElement('pub-date');

        $root->setAttribute('pub-type', 'epublish');
        $root->appendChild($doc->createElement('year', $date->format('Y')));
        $root->appendChild($doc->createElement('month', $date->format('m')));
        $root->appendChild($doc->createElement('day', $date->format('d')));

        return $root;
    }

    /**
     * Creates the authors node
     */
    private function _buildAuthors(): DOMElement
    {
        $contribGroupNode = $this->_document->createElement('contrib-group');
        foreach ($this->_article->getCurrentPublication()->getData('authors') as $author) {
            $contribNode = $this->_buildAuthor($author);
            $contribGroupNode->appendChild($contribNode);
        }
        return $contribGroupNode;
    }

    /**
     * Set the pages
     *
     * @param DOMElement $parentNode Parent node
     */
    private function _buildPages(DOMElement $parentNode): void
    {
        $publication = $this->_article->getCurrentPublication();
        /* --- fpage / lpage --- */
        // there is some ambiguity for online journals as to what
        // "page numbers" are; for example, some journals (eg. JMIR)
        // use the "e-location ID" as the "page numbers" in PubMed

        $pages = $publication->getData('pages');
        $fpage = $lpage = null;
        if (preg_match('/([0-9]+)\s*[–-\x{2013}]\s*([0-9]+)/ui', $pages, $matches)) {
            // simple pagination (eg. "pp. 3-8")
            [, $fpage, $lpage] = $matches;
        } elseif (preg_match('/(e[0-9]+)\s*[–-\x{2013}]\s*(e[0-9]+)/ui', $pages, $matches)) {
            // e9 - e14, treated as page ranges
            [, $fpage, $lpage] = $matches;
        } elseif (preg_match('/(e[0-9]+)/ui', $pages, $matches)) {
            // single elocation-id (eg. "e12")
            $fpage = $lpage = $matches[1];
        }
        if ($fpage) {
            $parentNode->appendChild($this->_document->createElement('fpage', $fpage));
        }
        if ($lpage) {
            $parentNode->appendChild($this->_document->createElement('lpage', $lpage));
        }
    }
}
