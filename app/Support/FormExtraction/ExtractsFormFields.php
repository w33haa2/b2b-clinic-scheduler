<?php

namespace App\Support\FormExtraction;

/**
 * Reads one kind of uploaded file and suggests the fields it contains.
 *
 * Add a format by writing one of these and listing it in FormExtractor. The
 * contract is deliberately narrow so an OCR driver (for scanned forms) can
 * be dropped in later without touching the wizard.
 */
interface ExtractsFormFields
{
    /**
     * The lower-case file extensions this driver reads.
     *
     * @return list<string>
     */
    public function extensions(): array;

    /**
     * The fields found in the file at the given path.
     *
     * @return list<ExtractedField>
     */
    public function extract(string $path): array;
}
