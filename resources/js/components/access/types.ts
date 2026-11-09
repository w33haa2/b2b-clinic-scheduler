/**
 * URLs for the member and role managers. The same components serve the
 * clinic API (/api/clinics/{clinic}/members, /roles) and the internal API
 * (/api/internal/clinics/{clinic}/users, /roles).
 */
export type MemberEndpoints = {
    list: (page: number, perPage: number) => string;
    store: string;
    member: (memberId: number) => string;
    roles: string;
};

export type RoleEndpoints = {
    list: string;
    store: string;
    role: (roleId: number) => string;
    permissions: string;
};

/** URLs for the invitations manager (internal API: /api/internal/clinics/{clinic}/invitations). */
export type InvitationEndpoints = {
    list: (page: number, perPage: number) => string;
    store: string;
    resend: (invitationId: number) => string;
    invitation: (invitationId: number) => string;
};
