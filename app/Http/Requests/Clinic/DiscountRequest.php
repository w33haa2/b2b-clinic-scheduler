<?php

namespace App\Http\Requests\Clinic;

use App\Enums\DiscountTarget;
use App\Enums\DiscountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates or edits a discount. `scope` is CLINIC_WIDE (Clinic Admins only)
 * or PERSONAL (the member's own, which only they see). `target` is the side
 * of the split the discount comes out of.
 */
class DiscountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCreating = $this->isMethod('POST');
        $required = $isCreating ? 'required' : 'sometimes';

        return [
            'scope' => [Rule::excludeIf(! $isCreating), 'required', 'string', Rule::in(['CLINIC_WIDE', 'PERSONAL'])],
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, 'string', Rule::in(DiscountType::codes())],
            'target' => [$required, 'string', Rule::in(DiscountTarget::codes())],
            'value' => [$required, 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('value')) {
                    return;
                }

                $type = $this->filled('type') ? DiscountType::fromCode($this->string('type')->toString()) : null;

                if ($type === DiscountType::Percentage && (float) $this->input('value') > 100) {
                    $validator->errors()->add('value', __('A percentage discount cannot be more than 100.'));
                }
            },
        ];
    }

    /**
     * The attributes to save, with the codes turned into their lookup IDs.
     *
     * @return array<string, mixed>
     */
    public function discountAttributes(): array
    {
        $attributes = $this->safe()->except(['type', 'target', 'scope']);

        if ($this->filled('type')) {
            $attributes['discount_type_id'] = DiscountType::fromCode($this->string('type')->toString());
        }

        if ($this->filled('target')) {
            $attributes['discount_target_id'] = DiscountTarget::fromCode($this->string('target')->toString());
        }

        return $attributes;
    }

    /**
     * Determine whether the discount is meant for the whole clinic.
     */
    public function isClinicWide(): bool
    {
        return $this->string('scope')->toString() === 'CLINIC_WIDE';
    }
}
