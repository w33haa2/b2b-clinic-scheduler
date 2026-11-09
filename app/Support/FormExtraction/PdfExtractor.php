<?php

namespace App\Support\FormExtraction;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads a text-based PDF with smalot/pdfparser (pure PHP, no binaries).
 *
 * A PDF that is only a scanned image has no text to find: extract() returns
 * nothing and the wizard says so, rather than pretending it read the page.
 * Reading scans would need OCR, which is not installed.
 */
class PdfExtractor implements ExtractsFormFields
{
    public function __construct(private PlainTextExtractor $plainText) {}

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['pdf'];
    }

    /**
     * @return list<ExtractedField>
     */
    public function extract(string $path): array
    {
        $text = $this->textFrom($path);

        return trim($text) === '' ? [] : $this->plainText->fromText($text);
    }

    private function textFrom(string $path): string
    {
        try {
            return (new Parser)->parseFile($path)->getText();
        } catch (Throwable) {
            // A corrupt or encrypted PDF is a bad upload, not a server error.
            return '';
        }
    }
}
