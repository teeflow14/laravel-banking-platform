<?php
// File: core/app/Services/OtpService.php

namespace App\Services;

use App\Models\Otp;
use App\Notifications\OtpCodeNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;

class OtpService
{
    protected const PURPOSE_LABELS = [
        'password_reset' => 'Password reset',
        'pin_reset' => 'PIN reset',
        'transfer_verification' => 'Transfer verification',
        'login_2fa' => 'Sign-in verification',
        'phone_verification' => 'Phone verification',
        'email_verification' => 'Email verification',
    ];

    /**
     * Generate and email an OTP. $notifiable is whatever the notification
     * should be sent to — typically the User model, but email-only
     * delivery also works via Notification::route('mail', $email) for
     * flows where we haven't confirmed the user yet.
     */
    public function send(string $identifier, string $purpose, ?int $userId, object $notifiable, int $ttlMinutes = 10): Otp
    {
        $result = Otp::generateFor($identifier, $purpose, $userId, $ttlMinutes);

        $label = self::PURPOSE_LABELS[$purpose] ?? 'Verification';

        Notification::send(
            $notifiable,
            new OtpCodeNotification($result['plain_code'], $label, $ttlMinutes)
        );

        return $result['otp'];
    }

    /**
     * Verify a submitted code against the most recent unused, unexpired
     * OTP for this identifier + purpose.
     */
    public function verify(string $identifier, string $purpose, string $code): bool
    {
        $otp = Otp::where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            // created_at has second-level precision in some MySQL schemas;
            // two codes generated in the same second can otherwise select
            // the older hash. The auto-increment id is the authoritative
            // insertion order.
            ->latest('id')
            ->first();

        if (! $otp) {
            return false;
        }

        if ($otp->attempts >= 5) {
            return false; // brute-force ceiling on a single OTP row
        }

        return $otp->verify($code);
    }
}

// End of file: core/app/Services/OtpService.php
