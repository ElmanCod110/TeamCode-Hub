<?php

declare(strict_types=1);

namespace TeamCodeHub\Services;

use Throwable;

final class EmailDeliveryService
{
    /**
     * Send a verification message through PHP's configured mail transport.
     * A production deployment must set MAIL_FROM_ADDRESS and an HTTPS APP_BASE_URL.
     */
    public function sendVerificationEmail(string $recipient, string $token): bool
    {
        $sender = getenv('MAIL_FROM_ADDRESS');
        $baseUrl = getenv('APP_BASE_URL');
        if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
            || !is_string($baseUrl) || $baseUrl === '' || preg_match('/[\r\n]/', $baseUrl) === 1) {
            return false;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        // Keep the bearer token in the URL fragment so it is not sent in HTTP request logs.
        $verificationUrl = rtrim($baseUrl, '/') . '/#verify-email?token=' . rawurlencode($token);
        $subject = 'Verify your TeamCode Hub email address';
        $message = "Welcome to TeamCode Hub.\n\n"
            . "Confirm that you own this email address by opening the link below and selecting Verify email.\n\n"
            . $verificationUrl . "\n\n"
            . "This link expires in 24 hours and can be used only once. If you did not create this account, you can ignore this message.\n";
        $headers = "From: " . $sender . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        try {
            return @mail($recipient, $subject, $message, $headers);
        } catch (Throwable) {
            return false;
        }
    }
}
