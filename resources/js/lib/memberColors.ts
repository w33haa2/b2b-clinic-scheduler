/**
 * Each staff member gets a stable colour on the schedule (like calendars in
 * Google Calendar). The CSS for `.member-color-N` lives in resources/css/app.css.
 */
export const MEMBER_COLOR_COUNT = 8;

export function memberColorIndex(
    memberId: number,
    orderedMemberIds: number[],
): number {
    const position = orderedMemberIds.indexOf(memberId);

    return (position === -1 ? memberId : position) % MEMBER_COLOR_COUNT;
}

/** Inline style for a legend dot or avatar ring in the member's colour. */
export function memberColorVar(index: number): string {
    return `var(--member-color-${index})`;
}
