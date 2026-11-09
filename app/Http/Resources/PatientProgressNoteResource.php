<?php

namespace App\Http\Resources;

use App\Models\PatientProgressMetric;
use App\Models\PatientProgressNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PatientProgressNote
 */
class PatientProgressNoteResource extends JsonResource
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
            'patient_id' => $this->patient_id,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'recorded_on' => $this->recorded_on->toDateString(),
            'progress' => $this->progress_rating_id->code(),
            'progress_label' => $this->progress_rating_id->label(),
            'direction' => $this->progress_rating_id->direction(),
            'summary' => $this->summary,
            'body' => $this->body,
            'plan' => $this->plan,
            'author' => new ClinicUserResource($this->whenLoaded('clinicUser')),
            'session' => $this->whenLoaded('clinicSession', fn (): ?array => $this->clinicSession === null ? null : [
                'id' => $this->clinicSession->id,
                'starts_at' => $this->clinicSession->starts_at,
                'label' => $this->clinicSession->activityLabel(),
            ]),
            'metrics' => $this->whenLoaded('metrics', fn (): array => $this->metrics
                ->map(fn (PatientProgressMetric $metric): array => [
                    'id' => $metric->id,
                    'name' => $metric->name,
                    'value' => $metric->value,
                    'unit' => $metric->unit,
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
