<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Clinic;
use App\Models\ClinicSessionMember;
use App\Models\Patient;
use App\Models\PatientProgressNote;
use App\Support\ActivityDescriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PatientHistoryController extends Controller
{
    /**
     * Everything that has happened to one patient, newest first.
     *
     * Four strands woven into one timeline: the sessions they attended with
     * what was billed and whether it was paid, the progress notes written
     * about them, and the audit entries for changes to their record. It
     * answers "what has this clinic done for this person, and when".
     *
     * `limit` caps each strand (default 50, max 200).
     */
    public function show(Request $request, Clinic $clinic, Patient $patient): JsonResponse
    {
        $limit = (int) ($request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ])['limit'] ?? 50);

        $entries = array_values(collect()
            ->concat($this->sessions($patient, $limit))
            ->concat($this->progressNotes($patient, $limit))
            ->concat($this->recordChanges($clinic, $patient, $limit))
            ->sortByDesc('at')
            ->take($limit * 2)
            ->all());

        return response()->json(['data' => [
            'patient_id' => $patient->id,
            'entries' => $entries,
        ]]);
    }

    /**
     * Sessions the patient attended, with the bill and its payment state.
     *
     * @return Collection<int, mixed>
     */
    private function sessions(Patient $patient, int $limit): Collection
    {
        return $patient->sessionMembers()
            ->with(['clinicSession.clinicUser.user', 'payment', 'sessionLineItems.lineItem'])
            ->whereHas('clinicSession')
            ->get()
            ->sortByDesc(fn (ClinicSessionMember $member): string => (string) $member->clinicSession?->starts_at)
            ->take($limit)
            ->map(fn (ClinicSessionMember $member): array => [
                'type' => 'session',
                'at' => $member->clinicSession->starts_at,
                'title' => $member->clinicSession->activityLabel(),
                'status' => $member->clinicSession->status_id->code(),
                'held_by' => $member->clinicSession->clinicUser?->activityLabel(),
                'payment_status' => $member->paymentStatus()->code(),
                'amount' => $member->payment?->amountDue(),
                'items' => $member->sessionLineItems
                    ->map(fn ($item): string => ($item->lineItem->name ?? 'Item')." × {$item->quantity}")
                    ->values()
                    ->all(),
            ])
            ->values();
    }

    /**
     * @return Collection<int, mixed>
     */
    private function progressNotes(Patient $patient, int $limit): Collection
    {
        return $patient->progressNotes()
            ->with('clinicUser.user')
            ->orderByDesc('recorded_on')
            ->limit($limit)
            ->get()
            ->map(fn (PatientProgressNote $note): array => [
                'type' => 'progress_note',
                'at' => $note->recorded_on->endOfDay(),
                'title' => $note->summary,
                'progress' => $note->progress_rating_id->code(),
                'progress_label' => $note->progress_rating_id->label(),
                'direction' => $note->progress_rating_id->direction(),
                'author' => $note->clinicUser?->activityLabel(),
                'note_id' => $note->id,
            ])
            ->values();
    }

    /**
     * Audit entries about the patient record itself. The log redacts the
     * personal details, so this says a field changed, not what it became.
     *
     * @return Collection<int, mixed>
     */
    private function recordChanges(Clinic $clinic, Patient $patient, int $limit): Collection
    {
        $describer = app(ActivityDescriber::class);

        return ActivityLog::query()
            ->where('clinic_id', $clinic->id)
            ->where('subject_type', $patient->getMorphClass())
            ->where('subject_id', $patient->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'type' => 'record_change',
                'at' => $log->created_at,
                'title' => $describer->describe($log),
                'author' => $log->user?->name,
                'changes' => array_keys($describer->readableChanges($log)),
            ])
            ->values();
    }
}
