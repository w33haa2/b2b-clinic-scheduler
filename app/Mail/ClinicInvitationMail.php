<?php

namespace App\Mail;

use App\Models\ClinicInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation to join a clinic, with the link that accepts it. Sent from
 * the queue. People without an account are told they will choose a password.
 */
class ClinicInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ClinicInvitation $invitation,
        public string $token,
        public bool $hasAccount,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You’re invited to join :clinic', ['clinic' => $this->invitation->clinic->name]),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.clinic-invitation',
            text: 'mail.clinic-invitation-text',
            with: [
                'appName' => config('app.name'),
                'clinicName' => $this->invitation->clinic->name,
                'inviterName' => $this->invitation->invitedBy?->name,
                'roleName' => $this->invitation->clinicRole->name ?? 'Clinic Worker',
                'url' => route('invitations.show', $this->token),
                'expiresAt' => $this->invitation->expires_at,
                'hasAccount' => $this->hasAccount,
            ],
        );
    }
}
