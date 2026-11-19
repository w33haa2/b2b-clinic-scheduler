/**
 * Clinic-time helpers. Daily sessions are planned in the clinic's timezone
 * (their hours and usual slots are wall-clock times there), so their screens
 * show clinic time rather than the browser's. Times are "HH:MM" strings,
 * dates "YYYY-MM-DD"; instants are ISO strings in UTC.
 */

/** The browser's timezone, e.g. "Asia/Manila". */
export function browserTimeZone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

/** The wall-clock date and time of an instant in a timezone. */
export function clinicParts(
    instant: string | Date,
    timeZone: string,
): { date: string; time: string } {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(new Date(instant))
            .map((part) => [part.type, part.value]),
    );

    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        time: `${parts.hour}:${parts.minute}`,
    };
}

/** The instant (ISO, UTC) of a wall-clock date and time in a timezone. */
export function fromClinicTime(
    date: string,
    time: string,
    timeZone: string,
): string {
    const [year, month, day] = date.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);
    const wanted = Date.UTC(year, month - 1, day, hour, minute);
    let instant = wanted;

    // Two passes settle the offset, also across a daylight-saving change.
    for (let pass = 0; pass < 2; pass++) {
        const shown = clinicParts(new Date(instant), timeZone);
        const [y, m, d] = shown.date.split('-').map(Number);
        const [h, min] = shown.time.split(':').map(Number);
        instant -= Date.UTC(y, m - 1, d, h, min) - wanted;
    }

    return new Date(instant).toISOString();
}

/*
 * FullCalendar only understands "local" and "UTC" without a timezone plugin,
 * so the calendar is fed wall-clock strings already in clinic time and told
 * they are UTC. It then does no shifting of its own and draws exactly the
 * clinic's clock. These two convert across that boundary.
 */

/** An instant → "2026-11-14T09:30:00", the clinic's wall clock. */
export function toCalendarTime(
    instant: string | Date,
    timeZone: string,
): string {
    const { date, time } = clinicParts(instant, timeZone);

    return `${date}T${time}:00`;
}

/**
 * A date out of FullCalendar (whose UTC parts are the clinic's wall clock)
 * back to the real instant it stands for.
 */
export function fromCalendarTime(value: Date, timeZone: string): Date {
    const date = [
        value.getUTCFullYear(),
        String(value.getUTCMonth() + 1).padStart(2, '0'),
        String(value.getUTCDate()).padStart(2, '0'),
    ].join('-');
    const time = [
        String(value.getUTCHours()).padStart(2, '0'),
        String(value.getUTCMinutes()).padStart(2, '0'),
    ].join(':');

    return new Date(fromClinicTime(date, time, timeZone));
}

/** "9:30 AM" in the clinic's timezone. */
export function formatClinicTime(instant: string, timeZone: string): string {
    return new Date(instant).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}

export function formatClinicRange(
    startsAt: string,
    endsAt: string,
    timeZone: string,
): string {
    return `${formatClinicTime(startsAt, timeZone)} – ${formatClinicTime(endsAt, timeZone)}`;
}

/** "Tue, Nov 3" in the clinic's timezone. */
export function formatClinicDate(instant: string, timeZone: string): string {
    return new Date(instant).toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone,
    });
}

export function minutesOf(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
}

export function timeOf(minutes: number): string {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** "08:00" → "8:00 AM" (a wall-clock time, no timezone involved). */
export function formatWallTime(time: string): string {
    const [hours, minutes] = time.split(':').map(Number);

    return new Date(Date.UTC(2000, 0, 1, hours, minutes)).toLocaleTimeString(
        undefined,
        { hour: 'numeric', minute: '2-digit', timeZone: 'UTC' },
    );
}
