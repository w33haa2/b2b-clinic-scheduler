<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Enums\ProgressRating;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PatientProgressNoteRequest;
use App\Http\Resources\PatientProgressNoteResource;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientProgressNote;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PatientProgressNoteController extends Controller
{
    /**
     * A patient's progress notes, oldest first.
     *
     * Read top to bottom they are the patient's history: each note rates the
     * change since the one before it. `meta.trend` summarises the run
     * (how many improved, declined, and where they are now), and
     * `meta.metrics` has every measurement grouped by name, ready to chart.
     */
    public function index(Request $request, Clinic $clinic, Patient $patient): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'progress' => ['sometimes', 'string', Rule::in(ProgressRating::codes())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $notes = $patient->progressNotes()
            ->when($filters['progress'] ?? null, fn ($query, string $progress) => $query->where('progress_rating_id', ProgressRating::fromCode($progress)))
            ->with(['clinicUser.user', 'clinicSession', 'metrics'])
            ->chronological()
            ->paginate($filters['per_page'] ?? 25);

        return PatientProgressNoteResource::collection($notes)->additional(['meta' => [
            'trend' => $this->trendFor($patient),
            'metrics' => $this->metricsFor($patient),
        ]]);
    }

    /**
     * Write a progress note for a patient.
     */
    public function store(
        PatientProgressNoteRequest $request,
        Clinic $clinic,
        Patient $patient,
        ClinicContext $context,
    ): PatientProgressNoteResource {
        $note = DB::transaction(function () use ($request, $clinic, $patient, $context): PatientProgressNote {
            $note = $clinic->patientProgressNotes()->create([
                ...$request->noteAttributes(),
                'patient_id' => $patient->id,
                'clinic_user_id' => $context->member()->id,
            ]);

            $this->syncMetrics($note, $request->metrics());

            return $note;
        });

        return new PatientProgressNoteResource($note->load(['clinicUser.user', 'clinicSession', 'metrics']));
    }

    /**
     * Change a progress note. Only its author may, so the history stays
     * attributable; a Clinic Admin can remove one instead.
     */
    public function update(
        PatientProgressNoteRequest $request,
        Clinic $clinic,
        Patient $patient,
        PatientProgressNote $progressNote,
        ClinicContext $context,
    ): PatientProgressNoteResource {
        $this->ensureWritableBy($progressNote, $context);

        DB::transaction(function () use ($request, $progressNote): void {
            $progressNote->update($request->noteAttributes());
            $this->syncMetrics($progressNote, $request->metrics());
        });

        return new PatientProgressNoteResource($progressNote->load(['clinicUser.user', 'clinicSession', 'metrics']));
    }

    /**
     * Remove a progress note. It is kept in the database, out of the
     * history, so the record of a clinical judgement is never destroyed.
     */
    public function destroy(
        Clinic $clinic,
        Patient $patient,
        PatientProgressNote $progressNote,
        ClinicContext $context,
    ): Response {
        $this->ensureWritableBy($progressNote, $context);

        $progressNote->delete();

        return response()->noContent();
    }

    /**
     * The author may edit their own note; a Clinic Admin may edit any.
     */
    private function ensureWritableBy(PatientProgressNote $note, ClinicContext $context): void
    {
        $member = $context->member();

        abort_unless($member->isAdmin() || $note->clinic_user_id === $member->id, 403);
    }

    /**
     * @param  list<array{name: string, value: string, unit: string|null}>|null  $metrics
     */
    private function syncMetrics(PatientProgressNote $note, ?array $metrics): void
    {
        if ($metrics === null) {
            return;
        }

        $note->metrics()->delete();

        foreach ($metrics as $metric) {
            $note->metrics()->create($metric);
        }
    }

    /**
     * How the patient has been doing across all their notes.
     *
     * @return array{notes: int, improved: int, unchanged: int, declined: int, latest: string|null, direction: int}
     */
    private function trendFor(Patient $patient): array
    {
        /** @var Collection<int, PatientProgressNote> $notes */
        $notes = $patient->progressNotes()->chronological()->get(['progress_rating_id']);
        $latest = $notes->last();

        return [
            'notes' => $notes->count(),
            'improved' => $notes->where('progress_rating_id', ProgressRating::Improved)->count(),
            'unchanged' => $notes->where('progress_rating_id', ProgressRating::Unchanged)->count(),
            'declined' => $notes->where('progress_rating_id', ProgressRating::Declined)->count(),
            'latest' => $latest?->progress_rating_id->code(),
            'direction' => $latest?->progress_rating_id->direction() ?? 0,
        ];
    }

    /**
     * Every measurement the patient has, grouped by name and in date order,
     * so the page can draw one line per measure.
     *
     * @return list<array<string, mixed>>
     */
    private function metricsFor(Patient $patient): array
    {
        $rows = DB::table('patient_progress_metrics')
            ->join('patient_progress_notes', 'patient_progress_notes.id', '=', 'patient_progress_metrics.patient_progress_note_id')
            ->where('patient_progress_notes.patient_id', $patient->id)
            ->whereNull('patient_progress_notes.deleted_at')
            ->orderBy('patient_progress_notes.recorded_on')
            ->get(['patient_progress_metrics.name', 'patient_progress_metrics.value', 'patient_progress_metrics.unit', 'patient_progress_notes.recorded_on']);

        return array_values($rows
            ->groupBy('name')
            ->map(fn ($points, string $name): array => [
                'name' => $name,
                'unit' => $points->first()->unit,
                'points' => array_values($points->map(fn (object $point): array => [
                    'date' => (string) str($point->recorded_on)->before(' '),
                    'value' => (string) $point->value,
                ])->all()),
            ])
            ->all());
    }

    /**
     * Every recent progress note in the clinic, newest first.
     *
     * The Progress notes page opens on this so staff can see who has been
     * seen lately and who is going backwards, without picking a patient
     * first. Filter by `patient_id`, `clinic_user_id`, `progress` and the
     * day (`from` / `to`).
     */
    public function clinicIndex(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'patient_id' => ['sometimes', 'integer'],
            'clinic_user_id' => ['sometimes', 'integer'],
            'progress' => ['sometimes', 'string', Rule::in(ProgressRating::codes())],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $notes = $clinic->patientProgressNotes()
            ->when($filters['patient_id'] ?? null, fn ($query, int|string $id) => $query->where('patient_id', $id))
            ->when($filters['clinic_user_id'] ?? null, fn ($query, int|string $id) => $query->where('clinic_user_id', $id))
            ->when($filters['progress'] ?? null, fn ($query, string $progress) => $query->where('progress_rating_id', ProgressRating::fromCode($progress)))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('recorded_on', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('recorded_on', '<=', $to))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('summary', 'like', "%{$search}%")
                ->orWhereHas('patient', fn (Builder $query) => $query
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->with(['patient', 'clinicUser.user', 'metrics'])
            ->orderByDesc('recorded_on')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return PatientProgressNoteResource::collection($notes);
    }
}
