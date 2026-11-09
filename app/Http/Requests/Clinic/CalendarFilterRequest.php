<?php

namespace App\Http\Requests\Clinic;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarFilterRequest extends FormRequest
{
    /**
     * What a condition can look at on the schedule.
     *
     * @var list<string>
     */
    public const FIELDS = ['staff', 'status', 'type', 'patient', 'payment', 'name'];

    /**
     * @var list<string>
     */
    public const OPERATORS = ['is', 'is_not', 'contains'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'rules' => [$required, 'array:match,conditions'],
            'rules.match' => ['required_with:rules', Rule::in(['all', 'any'])],
            'rules.conditions' => ['required_with:rules', 'array', 'min:1', 'max:20'],
            'rules.conditions.*' => ['array:field,operator,values'],
            'rules.conditions.*.field' => ['required', Rule::in(self::FIELDS)],
            'rules.conditions.*.operator' => ['required', Rule::in(self::OPERATORS)],
            'rules.conditions.*.values' => ['required', 'array', 'min:1', 'max:50'],
            'rules.conditions.*.values.*' => ['required', 'string', 'max:255'],
        ];
    }
}
