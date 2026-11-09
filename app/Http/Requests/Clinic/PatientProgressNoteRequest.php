<?php

namespace App\Http\Requests\Clinic;

use App\Enums\ProgressRating;
use App\Models\Clinic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatientProgressNoteRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return [
            'recorded_on' => [$required, 'date_format:Y-m-d'],
            'progress' => [$required, 'string', Rule::in(ProgressRating::codes())],
            'summary' => [$required, 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'plan' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'clinic_session_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('clinic_sessions', 'id')->where('clinic_id', $clinic->id),
            ],
            'metrics' => ['sometimes', 'array', 'max:20'],
            'metrics.*.name' => ['required', 'string', 'max:100', 'distinct'],
            'metrics.*.value' => ['required', 'numeric', 'min:-99999999', 'max:99999999'],
            'metrics.*.unit' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * The note itself, with the rating code turned into its lookup ID.
     *
     * @return array<string, mixed>
     */
    public function noteAttributes(): array
    {
        $attributes = $this->safe()->except(['progress', 'metrics']);

        if ($this->filled('progress')) {
            $attributes['progress_rating_id'] = ProgressRating::fromCode($this->string('progress')->toString());
        }

        return $attributes;
    }

    /**
     * The measurements recorded with the note, or null when the request did
     * not mention them (so an update leaves them alone).
     *
     * @return list<array{name: string, value: string, unit: string|null}>|null
     */
    public function metrics(): ?array
    {
        if (! $this->has('metrics')) {
            return null;
        }

        return array_values(array_map(fn (array $metric): array => [
            'name' => trim((string) $metric['name']),
            'value' => (string) $metric['value'],
            'unit' => isset($metric['unit']) && $metric['unit'] !== '' ? (string) $metric['unit'] : null,
        ], (array) $this->input('metrics', [])));
    }
}
