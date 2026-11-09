/**
 * Made-up but believable clinic data for the landing page mocks. No real
 * people: names are generic and amounts are examples.
 */
export type MockEvent = {
    day: number;
    start: number;
    hours: number;
    color: number;
    title: string;
    who: string;
    patients: string[];
    status?: 'ongoing' | 'done';
    isDaily?: boolean;
    room?: string;
};

export const practitioners = [
    {
        name: 'Dr. Lena Ortiz',
        role: 'Lead GP',
        initials: 'LO',
        color: 0,
    },
    {
        name: 'Sam Whitfield',
        role: 'Physiotherapist',
        initials: 'SW',
        color: 1,
    },
    {
        name: 'Priya Nair',
        role: 'Speech Therapist',
        initials: 'PN',
        color: 2,
    },
];

export const weekDays = ['Mon 3', 'Tue 4', 'Wed 5', 'Thu 6', 'Fri 7'];

export const weekEvents: MockEvent[] = [
    {
        day: 0,
        start: 0.5,
        hours: 1,
        color: 0,
        title: 'Initial assessment',
        who: 'Dr. Lena Ortiz',
        patients: ['Ana Reyes'],
        status: 'done',
        room: 'Rm 1',
    },
    {
        day: 0,
        start: 2,
        hours: 1.5,
        color: 1,
        title: 'Group physio',
        who: 'Sam Whitfield',
        patients: ['Marco Santos', 'Jo Kim', 'Ella Brooks'],
        status: 'done',
        room: 'Gym A',
    },
    {
        day: 1,
        start: 0.25,
        hours: 0.75,
        color: 1,
        title: 'Marco Santos',
        who: 'Morning rehab · Daily',
        patients: ['Marco Santos'],
        status: 'done',
        isDaily: true,
    },
    {
        day: 1,
        start: 1.25,
        hours: 1,
        color: 2,
        title: 'Speech therapy',
        who: 'Priya Nair',
        patients: ['Noah Lee'],
        status: 'ongoing',
        room: 'Rm 3',
    },
    {
        day: 1,
        start: 2.75,
        hours: 1.25,
        color: 0,
        title: 'Follow-up consult',
        who: 'Dr. Lena Ortiz',
        patients: ['Ana Reyes'],
        room: 'Rm 1',
    },
    {
        day: 2,
        start: 0,
        hours: 1,
        color: 1,
        title: 'Sports rehab',
        who: 'Sam Whitfield',
        patients: ['Jo Kim'],
        isDaily: true,
    },
    {
        day: 2,
        start: 2,
        hours: 2,
        color: 2,
        title: 'Parent workshop',
        who: 'Priya Nair',
        patients: ['Noah Lee', 'Mia Tan', 'Leo Cruz', 'Ava Lim'],
        room: 'Studio',
    },
    {
        day: 3,
        start: 1,
        hours: 1,
        color: 0,
        title: 'Annual check-up',
        who: 'Dr. Lena Ortiz',
        patients: ['Ruth Adeyemi'],
        room: 'Rm 1',
    },
    {
        day: 3,
        start: 2.5,
        hours: 1.25,
        color: 1,
        title: 'Mobility block',
        who: 'Sam Whitfield',
        patients: ['Ella Brooks'],
        isDaily: true,
    },
    {
        day: 4,
        start: 0.5,
        hours: 1.5,
        color: 1,
        title: 'Group physio',
        who: 'Sam Whitfield',
        patients: ['Marco Santos', 'Ella Brooks'],
        room: 'Gym A',
    },
    {
        day: 4,
        start: 2.75,
        hours: 1,
        color: 2,
        title: 'Speech therapy',
        who: 'Priya Nair',
        patients: ['Noah Lee'],
        room: 'Rm 3',
    },
];

export const hours = ['8:00', '9:00', '10:00', '11:00', '12:00'];

export const initials = (name: string): string =>
    name
        .split(' ')
        .filter((part) => /^[A-Z]/.test(part) && !part.endsWith('.'))
        .map((part) => part[0])
        .join('')
        .slice(0, 2);
