<?php

namespace App\Enums;

use App\Enums\Concerns\HasLookupCode;

/**
 * Rows of the patient_form_field_types lookup table. Case values are the row IDs.
 */
enum PatientFormFieldType: int
{
    use HasLookupCode;

    case Text = 1;
    case Textarea = 2;
    case Number = 3;
    case Date = 4;
    case Select = 5;
    case Multiselect = 6;
    case Checkbox = 7;
    case Radio = 8;
    case Email = 9;
    case Phone = 10;

    /**
     * Whether the field offers a fixed list of choices.
     */
    public function hasOptions(): bool
    {
        return match ($this) {
            self::Select, self::Multiselect, self::Radio => true,
            default => false,
        };
    }

    /**
     * Whether an answer is a list rather than a single value.
     */
    public function isMultiValue(): bool
    {
        return $this === self::Multiselect;
    }

    /**
     * The Laravel rules that come from the type itself, before the field's
     * own `required` and `validation` settings are added.
     *
     * @return list<string>
     */
    public function baseRules(): array
    {
        return match ($this) {
            self::Text, self::Textarea => ['string'],
            self::Number => ['numeric'],
            self::Date => ['date'],
            self::Select, self::Radio => ['string'],
            self::Multiselect => ['array'],
            self::Checkbox => ['boolean'],
            self::Email => ['string', 'email', 'max:255'],
            self::Phone => ['string', 'max:50'],
        };
    }

    /**
     * Whether answers already given can survive a change to this type.
     * Loosening (anything to text) is always safe; tightening is not.
     */
    public function canSafelyBecome(self $type): bool
    {
        if ($this === $type) {
            return true;
        }

        return match ($type) {
            self::Text, self::Textarea => true,
            default => false,
        };
    }
}
