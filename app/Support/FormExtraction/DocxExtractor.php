<?php

namespace App\Support\FormExtraction;

use ZipArchive;

/**
 * Reads a Word document. A .docx is a zip of XML, so the text comes out with
 * the zip extension alone — no Word, no conversion step. Paragraphs and
 * table cells become lines, then the plain-text heuristics finish the job.
 */
class DocxExtractor implements ExtractsFormFields
{
    public function __construct(private PlainTextExtractor $plainText) {}

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['docx'];
    }

    /**
     * @return list<ExtractedField>
     */
    public function extract(string $path): array
    {
        $text = $this->textFrom($path);

        return $text === '' ? [] : $this->plainText->fromText($text);
    }

    private function textFrom(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return '';
        }

        // Paragraphs, line breaks, tabs and table cells all become whitespace
        // a line-based parser can work with.
        $withBreaks = preg_replace(
            ['/<w:p[ >]/', '/<w:br\b[^>]*\/?>/', '/<w:tab\b[^>]*\/?>/', '/<\/w:tc>/'],
            ["\n<w:p ", "\n", '    ', '    '],
            $xml,
        ) ?? $xml;

        return html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
