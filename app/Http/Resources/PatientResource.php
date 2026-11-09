<?php

namespace App\Http\Resources;

use App\Models\Patient;
use App\Models\PatientFieldValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The built-in fields stay top-level keys, because the schedule, invoices
 * and patient search have always read them there. Whatever the clinic added
 * to its form comes back under `fields`, keyed by field key.
 *
 * @mixin Patient
 */
class PatientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'sex' => $this->sex,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'notes' => $this->notes,
            'fields' => $this->whenLoaded('patientFieldValues', fn (): array => $this->patientFieldValues
                ->filter(fn (PatientFieldValue $value): bool => $value->patientFormField?->trashed() === false)
                ->mapWithKeys(fn (PatientFieldValue $value): array => [$value->patientFormField->key => $value->typedValue()])
                ->all()),
            'archived_fields' => $this->whenLoaded('patientFieldValues', fn (): array => $this->patientFieldValues
                ->filter(fn (PatientFieldValue $value): bool => $value->patientFormField?->trashed() === true)
                ->map(fn (PatientFieldValue $value): array => [
                    'key' => $value->patientFormField->key,
                    'label' => $value->patientFormField->label,
                    'value' => $value->typedValue(),
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
