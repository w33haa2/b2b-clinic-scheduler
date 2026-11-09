<?php

namespace App\Support\FormExtraction;

use App\Models\Clinic;
use App\Models\PatientFormField;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Picks the right driver for an uploaded form, runs it, and marks which of
 * the suggestions the clinic already has.
 *
 * The upload is read where PHP put it and never copied into storage: it is
 * patient paperwork, and nothing about the wizard needs it to outlive the
 * request.
 */
class FormExtractor
{
    public function __construct(
        private PlainTextExtractor $plainText,
        private CsvExtractor $csv,
        private JsonExtractor $json,
        private HtmlExtractor $html,
        private DocxExtractor $docx,
        private PdfExtractor $pdf,
    ) {}

    /**
     * Every extension the wizard accepts, for the validation rule and the
     * "what can I upload?" hint.
     *
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        $extensions = [];

        foreach ($this->drivers() as $driver) {
            $extensions = [...$extensions, ...$driver->extensions()];
        }

        $extensions = array_values(array_unique($extensions));
        sort($extensions);

        return $extensions;
    }

    /**
     * Suggest fields from an uploaded file.
     *
     * @return array{source: string, fields: list<array<string, mixed>>, warning: string|null}
     */
    public function fromUpload(Clinic $clinic, UploadedFile $file): array
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $driver = $this->driverFor($extension);
        $fields = $driver === null ? [] : $driver->extract($file->getRealPath());

        return [
            'source' => mb_strtoupper($extension),
            'fields' => $this->decorate($clinic, $fields),
            'warning' => $this->warningFor($extension, $fields),
        ];
    }

    /**
     * Suggest fields from text the user pasted in. This is the way round a
     * scanned PDF: copy the text out and paste it here.
     *
     * @return array{source: string, fields: list<array<string, mixed>>, warning: string|null}
     */
    public function fromText(Clinic $clinic, string $text): array
    {
        $fields = $this->plainText->fromText($text);

        return [
            'source' => 'TEXT',
            'fields' => $this->decorate($clinic, $fields),
            'warning' => $fields === [] ? (string) __('No fields were recognised in that text.') : null,
        ];
    }

    /**
     * @return list<ExtractsFormFields>
     */
    private function drivers(): array
    {
        return [$this->plainText, $this->csv, $this->json, $this->html, $this->docx, $this->pdf];
    }

    private function driverFor(string $extension): ?ExtractsFormFields
    {
        foreach ($this->drivers() as $driver) {
            if (in_array($extension, $driver->extensions(), true)) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * Drop duplicates and say which suggestions the clinic already has, so
     * the wizard can pre-tick only the genuinely new ones.
     *
     * @param  list<ExtractedField>  $fields
     * @return list<array<string, mixed>>
     */
    private function decorate(Clinic $clinic, array $fields): array
    {
        /** @var Collection<string, PatientFormField> $existing */
        $existing = $clinic->patientFormFields()->withTrashed()->get()->keyBy('key');
        $seen = [];
        $decorated = [];

        foreach ($fields as $field) {
            if (isset($seen[$field->key])) {
                continue;
            }

            $seen[$field->key] = true;
            $match = $existing->get($field->key);

            $decorated[] = [
                ...$field->toArray(),
                'existing_field_id' => $match?->id,
                'existing_label' => $match?->label,
                'existing_is_archived' => $match?->trashed() ?? false,
            ];
        }

        return $decorated;
    }

    /**
     * @param  list<ExtractedField>  $fields
     */
    private function warningFor(string $extension, array $fields): ?string
    {
        if ($fields !== []) {
            return null;
        }

        if ($extension === 'pdf') {
            return (string) __('Nothing could be read from that PDF. Scanned pages hold no text, so copy the text out and paste it in the box below instead.');
        }

        return (string) __('No fields were recognised in that file. You can paste the text in the box below, or add the fields by hand.');
    }
}
