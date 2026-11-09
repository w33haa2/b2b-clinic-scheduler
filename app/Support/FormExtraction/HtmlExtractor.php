<?php

namespace App\Support\FormExtraction;

use App\Enums\PatientFormFieldType;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Reads an HTML form. Real `<label>`, `<input>`, `<select>` and `<textarea>`
 * elements are read directly, which makes this the most accurate driver. A
 * page with no form elements falls back to reading its text.
 */
class HtmlExtractor implements ExtractsFormFields
{
    public function __construct(
        private FieldGuesser $guesser,
        private PlainTextExtractor $plainText,
    ) {}

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return ['html', 'htm'];
    }

    /**
     * @return list<ExtractedField>
     */
    public function extract(string $path): array
    {
        $html = (string) file_get_contents($path);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $fields = $this->fromControls(new DOMXPath($document));

        return $fields !== [] ? $fields : $this->plainText->fromText((string) $document->textContent);
    }

    /**
     * @return list<ExtractedField>
     */
    private function fromControls(DOMXPath $xpath): array
    {
        $fields = [];
        $seen = [];

        /** @var DOMElement $control */
        foreach ($xpath->query('//input|//select|//textarea') ?: [] as $control) {
            $inputType = mb_strtolower($control->getAttribute('type') ?: 'text');

            if (in_array($inputType, ['hidden', 'submit', 'button', 'reset', 'image', 'file'], true)) {
                continue;
            }

            $label = $this->labelFor($control, $xpath);

            if ($label === '') {
                continue;
            }

            $field = $this->guesser->guess(
                label: $label,
                options: $this->optionsFor($control, $xpath),
                sourceLine: '<'.$control->nodeName.(($name = $control->getAttribute('name')) !== '' ? " name=\"{$name}\"" : '').'>',
            );

            if ($field === null || isset($seen[$field->key])) {
                continue;
            }

            $seen[$field->key] = true;
            $fields[] = new ExtractedField(
                key: $field->key,
                label: $field->label,
                type: $this->typeFor($control, $inputType, $field->type),
                options: $field->options,
                confidence: 'high',
                sourceLine: $field->sourceLine,
                section: $field->section,
                isRequired: $control->hasAttribute('required'),
            );
        }

        return $fields;
    }

    /**
     * The `<label for>`, a wrapping label, the aria-label, or the placeholder.
     */
    private function labelFor(DOMElement $control, DOMXPath $xpath): string
    {
        $id = $control->getAttribute('id');

        if ($id !== '') {
            $labels = $xpath->query(sprintf('//label[@for="%s"]', addslashes($id)));
            $label = $labels === false ? null : $labels->item(0);

            if ($label instanceof DOMNode) {
                return Str::of((string) $label->textContent)->squish()->value();
            }
        }

        for ($parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (mb_strtolower($parent->nodeName) === 'label') {
                return Str::of((string) $parent->textContent)->squish()->value();
            }
        }

        return Str::of($control->getAttribute('aria-label') ?: $control->getAttribute('placeholder') ?: $control->getAttribute('name'))
            ->replace(['_', '-'], ' ')
            ->squish()
            ->value();
    }

    /**
     * @return list<string>
     */
    private function optionsFor(DOMElement $control, DOMXPath $xpath): array
    {
        if (mb_strtolower($control->nodeName) !== 'select') {
            return [];
        }

        $options = [];

        foreach ($xpath->query('.//option', $control) ?: [] as $option) {
            if (! $option instanceof DOMNode) {
                continue;
            }

            $text = Str::of((string) $option->textContent)->squish()->value();

            if ($text !== '') {
                $options[] = $text;
            }
        }

        return $options;
    }

    private function typeFor(DOMElement $control, string $inputType, PatientFormFieldType $guessed): PatientFormFieldType
    {
        return match (mb_strtolower($control->nodeName)) {
            'textarea' => PatientFormFieldType::Textarea,
            'select' => $control->hasAttribute('multiple') ? PatientFormFieldType::Multiselect : PatientFormFieldType::Select,
            default => match ($inputType) {
                'email' => PatientFormFieldType::Email,
                'tel' => PatientFormFieldType::Phone,
                'date' => PatientFormFieldType::Date,
                'number' => PatientFormFieldType::Number,
                'checkbox' => PatientFormFieldType::Checkbox,
                'radio' => PatientFormFieldType::Radio,
                default => $guessed,
            },
        };
    }
}
