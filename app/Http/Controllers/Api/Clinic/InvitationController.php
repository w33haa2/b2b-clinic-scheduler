<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Api\Internal\ClinicInvitationController;

/**
 * Clinic Admins invite people to their own clinic: the same endpoints as
 * the internal tool's, under /api/clinics/{clinic}/invitations and the
 * "clinic.admin" middleware (inviting is never a grantable permission).
 */
class InvitationController extends ClinicInvitationController {}
