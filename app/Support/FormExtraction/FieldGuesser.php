<?php

namespace App\Support\FormExtraction;

use App\Enums\PatientFormFieldType;
use Illuminate\Support\Str;

/**
 * Turns a label found in a document into a usable field: a machine key, the
 * type it most likely is, and any choices spotted next to it.
 *
 * Everything here is a guess shown to a human for confirmation, so it leans
 * towards the safe answer (plain text) whenever it is unsure.
 */
class FieldGuesser
{
    /**
     * Words in a label that point at a type, most specific first.
     *
     * @var list<array{0: list<string>, 1: PatientFormFieldType}>
     */
    private const HINTS = [
        [['date of birth', 'birth date', 'birthdate', 'dob', 'birthday'], PatientFormFieldType::Date],
        [['email', 'e-mail'], PatientFormFieldType::Email],
        [['mobile', 'phone', 'contact number', 'telephone', 'tel no', 'cellphone'], PatientFormFieldType::Phone],
        [['date', 'admitted on', 'since when'], PatientFormFieldType::Date],
        [['age', 'weight', 'height', 'bmi', 'how many', 'number of', 'quantity'], PatientFormFieldType::Number],
        [['address', 'notes', 'remarks', 'history', 'complaint', 'symptoms', 'allergies', 'medication', 'comments', 'describe', 'reason for'], PatientFormFieldType::Textarea],
        [['sex', 'gender', 'civil status', 'marital status', 'blood type'], PatientFormFieldType::Select],
    ];

    /**
     * Choices to offer when a label is recognised but the document did not
     * spell its options out.
     *
     * @var array<string, list<string>>
     */
    private const KNOWN_OPTIONS = [
        'sex' => ['male', 'female', 'other'],
        'gender' => ['male', 'female', 'other'],
        'civil_status' => ['single', 'married', 'widowed', 'separated'],
        'marital_status' => ['single', 'married', 'widowed', 'separated'],
        'blood_type' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
    ];

    /**
     * Build a field from a label and, when the document showed them, the
     * choices printed beside it.
     *
     * @param  list<string>  $options
     */
    public function guess(string $label, array $options = [], ?string $sourceLine = null, ?string $section = null): ?ExtractedField
    {
        $label = $this->cleanLabel($label);

        if ($label === '' || mb_strlen($label) > 80) {
            return null;
        }

        $key = $this->keyFor($label);

        if ($key === '') {
            return null;
        }

        $options = $this->cleanOptions($options) ?: (self::KNOWN_OPTIONS[$key] ?? []);
        $type = $this->typeFor($label, $options);

        return new ExtractedField(
            key: $key,
            label: $label,
            type: $type,
            options: $type->hasOptions() ? $options : [],
            confidence: $this->confidenceFor($label, $type, $options),
            sourceLine: $sourceLine,
            section: $section,
        );
    }

    /**
     * A snake_case machine name, e.g. "Date of Birth *" → "birth_date".
     */
    public function keyFor(string $label): string
    {
        $normalised = mb_strtolower($this->cleanLabel($label));

        $aliases = [
            'date of birth' => 'birth_date',
            'birth date' => 'birth_date',
            'birthdate' => 'birth_date',
            'birthday' => 'birth_date',
            'dob' => 'birth_date',
            'given name' => 'first_name',
            'first name' => 'first_name',
            'surname' => 'last_name',
            'family name' => 'last_name',
            'last name' => 'last_name',
            'middle name' => 'middle_name',
            'gender' => 'sex',
            'e-mail' => 'email',
            'email address' => 'email',
            'mobile number' => 'phone',
            'contact number' => 'phone',
            'telephone' => 'phone',
            'home address' => 'address',
        ];

        if (isset($aliases[$normalised])) {
            return $aliases[$normalised];
        }

        return Str::of($normalised)->ascii()->snake()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->limit(100, '')->value();
    }

    /**
     * Strip the decoration a printed form puts around a label.
     */
    private function cleanLabel(string $label): string
    {
        return Str::of($label)
            // Trailing fill-in rules, dots and colons.
            ->replaceMatches('/[:：]\s*$/u', '')
            ->replaceMatches('/[_.\x{2026}]{2,}/u', ' ')
            // A leading list marker or number.
            ->replaceMatches('/^\s*(?:[\d]+[.)]|[-*•▪])\s*/u', '')
            // A "required" marker.
            ->replaceMatches('/\s*\*\s*$/u', '')
            ->squish()
            ->value();
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private function cleanOptions(array $options): array
    {
        $cleaned = [];

        foreach ($options as $option) {
            $option = Str::of($option)->replaceMatches('/^[\s☐☑✓□■\[\]()]+/u', '')->squish()->value();

            if ($option !== '' && mb_strlen($option) <= 60) {
                $cleaned[] = $option;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * @param  list<string>  $options
     */
    private function typeFor(string $label, array $options): PatientFormFieldType
    {
        $normalised = mb_strtolower($label);

        foreach (self::HINTS as [$words, $type]) {
            foreach ($words as $word) {
                if (str_contains($normalised, $word)) {
                    // A hinted type loses to actual printed choices.
                    return $options !== [] && ! $type->hasOptions() && $type !== PatientFormFieldType::Date
                        ? PatientFormFieldType::Radio
                        : $type;
                }
            }
        }

        if (count($options) > 1) {
            return count($options) > 4 ? PatientFormFieldType::Select : PatientFormFieldType::Radio;
        }

        if (count($options) === 1) {
            return PatientFormFieldType::Checkbox;
        }

        return PatientFormFieldType::Text;
    }

    /**
     * @param  list<string>  $options
     * @return 'high'|'medium'|'low'
     */
    private function confidenceFor(string $label, PatientFormFieldType $type, array $options): string
    {
        if ($type->hasOptions() && $options !== []) {
            return 'high';
        }

        if ($type !== PatientFormFieldType::Text) {
            return 'high';
        }

        return str_contains($label, ' ') && mb_strlen($label) <= 40 ? 'medium' : 'low';
    }
}
