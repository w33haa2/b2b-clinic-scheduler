<?php

namespace App\Support;

use App\Models\ClinicUser;
use LogicException;

/**
 * Holds the authenticated user's membership in the clinic named by the
 * current request's {clinic} route parameter. Set by EnsureClinicMember.
 */
class ClinicContext
{
    private ?ClinicUser $member = null;

    public function setMember(ClinicUser $member): void
    {
        $this->member = $member;
    }

    /**
     * The current user's membership, or null outside the clinic area.
     */
    public function memberOrNull(): ?ClinicUser
    {
        return $this->member;
    }

    /**
     * The current user's membership in the requested clinic.
     */
    public function member(): ClinicUser
    {
        return $this->member ?? throw new LogicException('No clinic membership was resolved for this request.');
    }
}
