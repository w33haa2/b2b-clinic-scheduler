<?php

namespace App\Http\Resources;

use App\Models\PatientFormField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientFormField
 */
class PatientFormFieldResource extends JsonResource
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
            'key' => $this->key,
            'label' => $this->label,
            'placeholder' => $this->placeholder,
            'help_text' => $this->help_text,
            'type' => $this->patient_form_field_type_id->code(),
            'options' => $this->options ?? [],
            'validation' => $this->validation ?? [],
            'is_required' => $this->is_required,
            'section' => $this->section,
            'position' => $this->position,
            // A built-in field is a column on `patients`: it can be renamed
            // and reordered, but never removed or retyped.
            'is_built_in' => $this->isBuiltIn(),
            'is_archived' => $this->trashed(),
            'archived_at' => $this->deleted_at,
            'answer_count' => $this->when($request->boolean('with_usage'), fn (): int => $this->answerCount()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
