<?php

declare(strict_types=1);

namespace App\Notifications\Identity;

use App\Actions\Identity\RegisterUserAction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email that carries the signed verification link (SPRINT_01 §S1-07,
 * docs/backend/AUTH_SERVICE.md → `identity.user.registered` → "Send verification email").
 *
 * It transports the already-signed URL built by {@see RegisterUserAction}; the
 * notification never mints the signature itself. Kept mail-only for Sprint 1 (SMS/localised templates
 * are later work).
 */
final class VerifyEmailNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $verificationUrl) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your QAYD email address')
            ->line('Welcome to QAYD. Please confirm your email address to activate your account.')
            ->action('Verify email address', $this->verificationUrl)
            ->line('This link expires in 24 hours. If you did not create a QAYD account, no action is needed.');
    }
}
