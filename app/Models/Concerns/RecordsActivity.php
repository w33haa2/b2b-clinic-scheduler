<?php

namespace App\Models\Concerns;

use App\Support\ActivityLogger;
use BackedEnum;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Records created / updated / archived / deleted / restored events of a model
 * in the activity log, with the changed attributes on updates.
 *
 * Models implement activityEntity() and activityLabel(), and may override
 * activityClinicId(), activityAction() and the attribute lists below.
 */
trait RecordsActivity
{
    public static function bootRecordsActivity(): void
    {
        static::created(fn (self $model) => $model->recordActivity('created'));
        static::updated(fn (self $model) => $model->recordActivity('updated'));
        static::deleted(fn (self $model) => $model->recordActivity($model->isSoftDeleting() ? 'archived' : 'deleted'));
        // Only fired by models that use SoftDeletes.
        static::registerModelEvent('restored', fn (self $model) => $model->recordActivity('restored'));
    }

    /**
     * Whether the delete in progress only archives the record (SoftDeletes).
     */
    protected function isSoftDeleting(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this), true)
            && method_exists($this, 'isForceDeleting')
            && ! $this->isForceDeleting();
    }

    /**
     * The noun used in action names, e.g. "patient" → "patient.created".
     */
    abstract public function activityEntity(): string;

    /**
     * A human name for this record at the time of the action.
     */
    abstract public function activityLabel(): string;

    /**
     * The clinic whose log this belongs to; null for platform-level records.
     */
    public function activityClinicId(): ?int
    {
        return $this->getAttribute('clinic_id');
    }

    /**
     * Extra facts stored with the entry (e.g. which session a patient was added to).
     *
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return [];
    }

    /**
     * The action name for an event. Override for domain-specific names.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function activityAction(string $event, array $changes): string
    {
        return "{$this->activityEntity()}.{$event}";
    }

    /**
     * Attributes never logged (besides the model's $hidden ones).
     *
     * @return list<string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'password'];
    }

    /**
     * Attributes whose values are personal: the change is logged, the values are not.
     *
     * @return list<string>
     */
    protected function activityRedactedAttributes(): array
    {
        return [];
    }

    protected function recordActivity(string $event): void
    {
        if (ActivityLogger::isQuiet()) {
            return;
        }

        $changes = $event === 'updated' ? $this->activityChanges() : [];

        if ($event === 'updated' && $changes === []) {
            return;
        }

        app(ActivityLogger::class)->log(
            action: $this->activityAction($event, $changes),
            subject: $this,
            clinicId: $this->activityClinicId(),
            label: $this->activityLabel(),
            changes: $changes,
            context: $this->activityContext(),
        );
    }

    /**
     * Changed attributes as ['field' => ['old' => …, 'new' => …]].
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function activityChanges(): array
    {
        $ignored = [...$this->activityIgnoredAttributes(), ...$this->getHidden()];
        $redacted = $this->activityRedactedAttributes();
        $changes = [];

        foreach (array_keys($this->getChanges()) as $attribute) {
            if (in_array($attribute, $ignored, true)) {
                continue;
            }

            $changes[$attribute] = in_array($attribute, $redacted, true)
                ? ['old' => null, 'new' => null]
                : ['old' => $this->loggableValue($this->getRawOriginal($attribute)), 'new' => $this->loggableValue($this->getAttributes()[$attribute] ?? null)];
        }

        return $changes;
    }

    private function loggableValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
