<?php

namespace App\Support\FormExtraction;

use App\Enums\PatientFormFieldType;

/**
 * A field the extractor thinks it found in an uploaded form. Nothing is
 * saved until the user confirms it in the wizard, so every one of these is a
 * suggestion, carrying the line it came from so the user can judge it.
 */
final readonly class ExtractedField
{
    /**
     * @param  list<string>  $options
     * @param  'high'|'medium'|'low'  $confidence
     */
    public function __construct(
        public string $key,
        public string $label,
        public PatientFormFieldType $type,
        public array $options = [],
        public string $confidence = 'medium',
        public ?string $sourceLine = null,
        public ?string $section = null,
        public bool $isRequired = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->code(),
            'options' => $this->options,
            'confidence' => $this->confidence,
            'source_line' => $this->sourceLine,
            'section' => $this->section,
            'is_required' => $this->isRequired,
        ];
    }
}
