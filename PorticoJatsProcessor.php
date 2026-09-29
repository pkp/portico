<?php

/**
 * @file plugins/generic/portico/PorticoJatsProcessor.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoJatsProcessor
 *
 * @brief Post-processes jatsTemplate-generated XML into Portico's metadata XML:
 * switches to the archiving DTD, strips the article body, and rewrites/removes
 * galley self-uri and supplementary-material references to match what is actually
 * packaged in the ZIP.
 */

namespace APP\plugins\generic\portico;

use DOMDocument;
use DOMElement;
use PKP\i18n\LocaleConversion;

class PorticoJatsProcessor
{
    private const JOURNAL_PUBLISHING_DTD_PUBLIC_ID = '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN';
    private const JOURNAL_PUBLISHING_DTD_SYSTEM_ID = 'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd';

    private const ARCHIVING_DTD_PUBLIC_ID = '-//NLM//DTD JATS (Z39.96) Journal Archiving and Interchange DTD v1.2 20190208//EN';
    private const ARCHIVING_DTD_SYSTEM_ID = 'http://jats.nlm.nih.gov/archiving/1.2/JATS-archivearticle1.dtd';

    /** Matches an OJS article download URL, capturing the trailing submission file ID */
    private const DOWNLOAD_URL_PATTERN = '#/article/download/[^/]+/[^/]+/(\d+)(?:[/?].*)?$#';

    /**
     * Post-process jatsTemplate-generated XML into Portico's metadata XML.
     *
     * @param string $xml Raw jatsTemplate XML for the publication
     * @param array<int, string> $packedFiles Map of submissionFileId => relative filename actually packed into the ZIP
     * @param string|null $fullTextFilename The uploaded full-text JATS document's packaged filename, if one is included alongside it
     *
     * @return string|null Null if $xml could not be parsed
     */
    public static function processMetadata(string $xml, array $packedFiles, ?string $fullTextFilename = null): ?string
    {
        $dom = self::parseWithArchivingDtd($xml);
        if ($dom === null) {
            return null;
        }

        self::removeBody($dom);
        $lastSelfUri = self::processGalleyReferences($dom, $packedFiles);
        if ($fullTextFilename !== null) {
            self::addFullTextSelfUri($dom, $lastSelfUri, $fullTextFilename);
        }

        return $dom->saveXML();
    }

    /**
     * Post-process an uploaded full-text JATS document for packaging: adjusts the DTD
     * and file references (images, PDF self-uri, supplementary material) only.
     *
     * @param string $xml Raw uploaded JATS XML content
     * @param array<string, string> $mediaFilesByName Map of original media filename => relative packed filename
     * @param array<int, array{filename: string, locale: string, label: string}> $pdfFilenames Every packaged PDF's relative filename, locale, and galley label
     * @param array<string, string> $fileUrlsToFilenames Map of this submission's own download/view URLs => relative packed filename
     *
     * @return string|null Null if $xml could not be parsed
     */
    public static function processFullText(string $xml, array $mediaFilesByName, array $pdfFilenames, array $fileUrlsToFilenames): ?string
    {
        $dom = self::parseWithArchivingDtd($xml);
        if ($dom === null) {
            return null;
        }

        self::rewriteGraphics($dom, $mediaFilesByName);
        self::rewritePdfSelfUri($dom, $pdfFilenames);
        self::rewriteSupplementaryMaterial($dom, $fileUrlsToFilenames);

        return $dom->saveXML();
    }

    /**
     * Extract the set of graphic filenames referenced in an uploaded JATS document.
     *
     * @return string[] Unique basenames from every <graphic xlink:href>
     */
    public static function extractGraphicFilenames(string $xml): array
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml)) {
            return [];
        }

        $names = [];
        foreach ($dom->getElementsByTagName('graphic') as $node) {
            /** @var DOMElement $node */
            $names[] = basename($node->getAttribute('xlink:href'));
        }

        return array_values(array_unique($names));
    }

    /**
     * Switch the DTD from Journal Publishing to Archiving and Interchange, and parse.
     */
    private static function parseWithArchivingDtd(string $xml): ?DOMDocument
    {
        // Switching the DTD is easiest done as a text substitution before parsing --
        // DOMDocument's doctype is immutable once the document exists.
        $xml = str_replace(
            [self::JOURNAL_PUBLISHING_DTD_PUBLIC_ID, self::JOURNAL_PUBLISHING_DTD_SYSTEM_ID],
            [self::ARCHIVING_DTD_PUBLIC_ID, self::ARCHIVING_DTD_SYSTEM_ID],
            $xml
        );

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml)) {
            return null;
        }

        return $dom;
    }

    /**
     * Rewrite <graphic xlink:href> bare filenames to their packed relative filename.
     * Left untouched if no match is found (e.g. an external image).
     *
     * @param array<string, string> $mediaFilesByName
     */
    private static function rewriteGraphics(DOMDocument $dom, array $mediaFilesByName): void
    {
        foreach (iterator_to_array($dom->getElementsByTagName('graphic')) as $node) {
            /** @var DOMElement $node */
            $name = basename($node->getAttribute('xlink:href'));
            if (isset($mediaFilesByName[$name])) {
                $node->setAttribute('xlink:href', $mediaFilesByName[$name]);
            }
        }
    }

    /**
     * Replace any existing <self-uri content-type="pdf"> nodes under article-meta with
     * one fresh node per packaged PDF -- a self-uri must resolve to a file bundled in
     * this same package, so any existing reference is replaced rather than kept.
     *
     * @param array<int, array{filename: string, locale: string, label: string}> $pdfFilenames
     */
    private static function rewritePdfSelfUri(DOMDocument $dom, array $pdfFilenames): void
    {
        $articleMeta = $dom->getElementsByTagName('article-meta')->item(0);
        if (!$articleMeta) {
            return;
        }

        // Anchor on the sibling immediately before the first PDF self-uri, if any, so the
        // replacements land in the same place the original(s) already were - a position we
        // can trust is DTD-valid, since a well-formed uploaded document already had it there.
        // Captured before removing anything: if there are several adjacent PDF self-uri
        // nodes, a later one could otherwise end up "anchoring" on an earlier one that's
        // also about to be removed.
        $anchorSibling = null;
        $anchorFound = false;
        foreach (iterator_to_array($articleMeta->getElementsByTagName('self-uri')) as $node) {
            /** @var DOMElement $node */
            $contentType = strtolower($node->getAttribute('content-type'));
            if ($contentType === 'pdf' || $contentType === 'application/pdf') {
                if (!$anchorFound) {
                    $anchorSibling = $node->previousSibling;
                    $anchorFound = true;
                }
                $node->parentNode->removeChild($node);
            }
        }

        if ($anchorFound) {
            // Re-read nextSibling now, after removal, so it reflects the current tree.
            $insertBefore = $anchorSibling ? $anchorSibling->nextSibling : $articleMeta->firstChild;
        } else {
            // No PDF self-uri existed to anchor to. self-uri must come before
            // related-article/related-object/abstract/trans-abstract/kwd-group/funding-group/
            // support-group/conference/counts/custom-meta-group per the DTD's
            // article-meta-model - appending unconditionally at the end would be invalid if
            // the uploaded document has any of these. Insert before the first one found, in
            // document order; if none exist, the end of article-meta is a valid position.
            $laterTags = ['related-article', 'related-object', 'abstract', 'trans-abstract', 'kwd-group', 'funding-group', 'support-group', 'conference', 'counts', 'custom-meta-group'];
            $insertBefore = null;
            foreach ($articleMeta->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->tagName, $laterTags, true)) {
                    $insertBefore = $child;
                    break;
                }
            }
        }

        foreach ($pdfFilenames as $pdf) {
            $selfUri = $dom->createElement('self-uri');
            $selfUri->setAttribute('xlink:href', $pdf['filename']);
            $selfUri->setAttribute('content-type', 'application/pdf');
            $selfUri->setAttribute('xml:lang', LocaleConversion::toBcp47($pdf['locale']));
            if ($pdf['label']) {
                $selfUri->setAttribute('xlink:title', $pdf['label']);
            }
            if ($insertBefore) {
                $articleMeta->insertBefore($selfUri, $insertBefore);
            } else {
                $articleMeta->appendChild($selfUri);
            }
        }
    }

    /**
     * Strip the scheme so an http/https mismatch doesn't break an otherwise-exact match.
     */
    public static function stripScheme(string $url): string
    {
        return preg_replace('#^https?://#i', '', $url);
    }

    /**
     * Rewrite <supplementary-material xlink:href> to a relative filename when it
     * matches one of this submission's own download or view URLs (scheme-insensitive).
     * Left untouched otherwise (e.g. an external URL, or another submission's file).
     *
     * @param array<string, string> $fileUrlsToFilenames
     */
    private static function rewriteSupplementaryMaterial(DOMDocument $dom, array $fileUrlsToFilenames): void
    {
        foreach (iterator_to_array($dom->getElementsByTagName('supplementary-material')) as $node) {
            /** @var DOMElement $node */
            $href = self::stripScheme($node->getAttribute('xlink:href'));
            if (isset($fileUrlsToFilenames[$href])) {
                $node->setAttribute('xlink:href', $fileUrlsToFilenames[$href]);
            }
        }
    }

    /**
     * Portico's metadata XML must not include the article body -- the full text lives
     * in the packaged PDF (and, separately, an uploaded JATS XML document, if any).
     */
    private static function removeBody(DOMDocument $dom): void
    {
        foreach (iterator_to_array($dom->getElementsByTagName('body')) as $bodyNode) {
            $bodyNode->parentNode->removeChild($bodyNode);
        }
    }

    /**
     * Rewrite or remove galley references in article-meta based on what was actually packaged.
     *
     * @param array<int, string> $packedFiles
     *
     * @return DOMElement|null The last remaining <self-uri> node, used as the insertion
     *  point for the packaged metadata XML's own self-reference.
     */
    private static function processGalleyReferences(DOMDocument $dom, array $packedFiles): ?DOMElement
    {
        $articleMeta = $dom->getElementsByTagName('article-meta')->item(0);
        if (!$articleMeta) {
            return null;
        }

        // Supplementary files are packaged unconditionally, so no ordering dependency there.
        self::rewriteReferenceNodes($articleMeta, 'supplementary-material', $packedFiles);
        // Self-uri is processed last so its return value -- the last kept self-uri node --
        // is the correct insertion point for the packaged XML's own self-reference below.
        return self::rewriteReferenceNodes($articleMeta, 'self-uri', $packedFiles);
    }

    /**
     * Rewrite or remove references of the given tag name under article-meta based on
     * what was actually packaged. A reference to a file we chose not to package (e.g.
     * an HTML galley) is removed rather than left dangling -- a missing reference is
     * better than one pointing at nothing in the ZIP.
     *
     * @param array<int, string> $packedFiles
     *
     * @return DOMElement|null The last node left in place, if any.
     */
    private static function rewriteReferenceNodes(DOMElement $articleMeta, string $tagName, array $packedFiles): ?DOMElement
    {
        $lastNode = null;
        foreach (iterator_to_array($articleMeta->getElementsByTagName($tagName)) as $node) {
            /** @var DOMElement $node */
            $href = $node->getAttribute('xlink:href');
            if (!preg_match(self::DOWNLOAD_URL_PATTERN, $href, $matches)) {
                $lastNode = $node;
                continue;
            }

            $submissionFileId = (int) $matches[1];
            if (isset($packedFiles[$submissionFileId])) {
                $node->setAttribute('xlink:href', $packedFiles[$submissionFileId]);
                $lastNode = $node;
                continue;
            }

            $node->parentNode->removeChild($node);
        }
        return $lastNode;
    }

    /**
     * jatsTemplate has no way to know that an uploaded full-text JATS document is
     * being packaged alongside its own output, so add a cross-reference to it here,
     * grouped after the other self-uris (landing page, then PDF, then full-text XML).
     */
    private static function addFullTextSelfUri(DOMDocument $dom, ?DOMElement $afterNode, string $fullTextFilename): void
    {
        $articleMeta = $dom->getElementsByTagName('article-meta')->item(0);
        if (!$articleMeta) {
            return;
        }

        $selfUri = $dom->createElement('self-uri');
        $selfUri->setAttribute('xlink:href', $fullTextFilename);
        $selfUri->setAttribute('content-type', 'application/xml');

        if ($afterNode && $afterNode->parentNode === $articleMeta) {
            $articleMeta->insertBefore($selfUri, $afterNode->nextSibling);
        } else {
            $articleMeta->appendChild($selfUri);
        }
    }
}
