# Activity log

Every change made in the app is recorded in `activity_logs`. Each entry stores:

- who did it
- what changed, and the old and new values
- when, and from which IP

Clinic Admins read their clinic's log. Super admins read everything.

| Level                           | What                                                                                                                                                                                                                                   | Where to read it                                                                                                                        |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| **Clinic** (`clinic_id` set)    | Patients, sessions, daily sessions and enrolments, plotted slots, session patients, billed items, payments (including prepayments), saved schedule filters, services, invoices (including PDF downloads), staff, roles, clinic details | `/admin/clinics/{clinic}/activity`, `GET /api/clinics/{clinic}/activity` (permission `clinic.activity.index`, Clinic Admins by default) |
| **Platform** (`clinic_id` null) | Sign-ins, sign-outs, failed sign-ins, password resets, API tokens, user accounts (including super admins)                                                                                                                              | `/admin/internal/activity`, `GET /api/internal/activity` (super admins)                                                                 |

Super admins also see every clinic entry; the **Everything / Platform / Clinics**
tabs and the clinic filter switch between them.

## The table

| Column                       | Meaning                                                                                                                               |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `clinic_id`                  | The clinic whose log the entry belongs to; null for platform entries                                                                  |
| `user_id`                    | The person who acted (web session or API token). Null for the system (console, queue) and for failed sign-ins (the person is unknown) |
| `action`                     | `{entity}.{event}`, e.g. `patient.created`, `session.status_changed`, `payment.refunded`                                              |
| `subject_type`, `subject_id` | The record acted on (polymorphic)                                                                                                     |
| `subject_label`              | Its name at the time, e.g. "Ana Reyes" or "INV-000014 for Ana Reyes", kept after the record is deleted                                |
| `changes`                    | `{"field": {"old": …, "new": …}}` for updates                                                                                         |
| `context`                    | Extra facts, e.g. which session a patient was added to                                                                                |
| `ip_address`, `created_at`   | Where and when                                                                                                                        |

Entries are **append-only**: there is no update or delete endpoint.
`model:prune` runs daily and deletes entries older than
`ACTIVITY_RETENTION_DAYS` (default 365, `config/activity.php`).

## How entries are written

1. **Automatically, from model events.** The `App\Models\Concerns\RecordsActivity`
   trait records `created`, `updated` (only when a real attribute changed),
   `archived` (soft delete), `deleted` and `restored`. It is used by:
    - `Clinic`, `ClinicUser`, `ClinicRole`
    - `Patient`, `ClinicSession`, `ClinicSessionMember`
    - `DailySession`, `DailySessionPatient`, `CalendarFilter`
    - `LineItem`, `SessionLineItem`, `Invoice`
    - `User`

    Models can rename events. For example:
    - A session whose only change is its status logs `session.status_changed`.
    - Plotting a patient into a slot logs `session_patient.plotted` (or `unplotted`).
    - Enrolling in or leaving a daily session logs `daily_session_patient.enrolled` / `removed`;
      changing a patient's regular plan logs `daily_session_patient.plan_changed`.
    - A one-day change to a patient (slot, service) logs `session_patient.plotted` /
      `unplotted` and `billed_item.updated`.
    - Adding or removing staff logs `member.added` or `member.removed`.

2. **Explicitly, for changes model events cannot see.** Pivot tables do not fire
   model events, so these are logged through `App\Support\ActivityLogger`:
    - Member role changes: `member.roles_changed`.
    - Role permission changes: `role.permissions_changed` (only what was added and removed).
    - Invoice item changes: `invoice.items_changed`.
    - Invoice PDF downloads: `invoice.downloaded`.
    - API tokens: `api_token.created` and `api_token.revoked`.
    - Invitations: `invitation.sent` and `invitation.revoked` come from model
      events; `invitation.resent` and `invitation.accepted` (with the person who
      joined as the actor) are logged by the actions. Category: staff.
    - Payments (`UpdatePaymentStatus`): `payment.recorded` or
      `payment.refunded`, with the status change and, in `context`, the session,
      the amount and `prepaid: true` for prepayments. `Payment` has no
      model-event logging, so creating a patient's payment is covered by
      `session_patient.added`.
    - Days scheduled by the hourly `clinic:schedule-daily-sessions` run:
      one `daily_session.days_scheduled` entry per daily session (no actor),
      with the number of days in `context`.

    **Bulk changes are summarised.** Creating a daily session, enrolling a
    patient or re-planning upcoming days writes many rows (one day, patient,
    bill and payment each). These run inside `ActivityLogger::quietly()`,
    which suppresses the per-row model-event entries; the entry for the action
    itself (`daily_session.created`, `daily_session_patient.enrolled`,
    `daily_session.updated`, …) is the record.

3. **Authentication events** (`Login`, `Logout`, `Failed`, `PasswordReset`) are
   logged at platform level by listeners in `AppServiceProvider`.

What is **not** logged:

- Passwords, tokens and every attribute in a model's `$hidden`.
- The `created_at`, `updated_at` and `deleted_at` timestamps.
- Transactions, because the transaction ledger is already a permanent record and the payment entry covers it.
- Changes made by database seeders (`WithoutModelEvents`).

**Privacy:** a patient's personal details (birth date, sex, email, phone,
address, notes) are logged as "changed" without the old or new value
(`activityRedactedAttributes()`). Names are kept so the log stays readable.

## Reading entries

`App\Support\ActivityDescriber` turns entries into sentences, for example:

- "Changed status of session Group physio on Tue 4 Nov, 09:00 from Scheduled to In progress"
- "Recorded payment from Ana Reyes for session …"
- "Failed sign-in attempt for ana@example.com"

`ActivityLogResource` returns:

- the sentence
- the category
- the changes, with status IDs turned into words
- the actor
- the clinic (internal endpoint only)
- the IP address and time

In a clinic's log, super admins are shown as "Clinic App support" rather than
by name.

**Filters** (both endpoints; `ActivityLogQuery`):

- `category`: patients, schedule (sessions, session patients, daily sessions,
  enrolments, saved filters), billing (billed items, payments), invoices,
  services, staff, clinic, account
- `user_id`
- `from` / `to`
- `search`: record name, action, or the actor's name or email

The internal endpoint adds:

- `level=platform|clinic`
- `clinic_id`

Each response includes `meta.filters` with the categories and the people (clinic) or
clinics (internal) to offer in dropdowns.

## Adding logging to something new

- **A new model:** `use RecordsActivity;` and implement `activityEntity()` and
  `activityLabel()`. Override `activityClinicId()` if the model has no
  `clinic_id` column, `activityAction()` for domain-specific verbs, and
  `activityRedactedAttributes()` for personal data.
- **A pivot sync or other change without model events:** call
  `app(ActivityLogger::class)->log('thing.happened', $subject, $clinicId, $label, $changes)`.
- **A new action prefix:** add it to a category in `App\Enums\ActivityCategory`
  and give it a sentence in `ActivityDescriber` if the generic
  "Created / Updated / Archived {noun} {label}" does not read well.
- Add a test in `tests/Feature/Api/ActivityLogTest.php`.
