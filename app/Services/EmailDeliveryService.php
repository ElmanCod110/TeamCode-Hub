<?php

declare(strict_types=1);

namespace TeamCodeHub\Services;

use Throwable;

final class EmailDeliveryService
{
    /**
     * Send a team invitation through the configured HTTPS origin.
     * The bearer token is carried in the URL fragment and is never written to application logs.
     */
    public function sendTeamInvitationEmail(string $recipient, string $token, string $teamName): bool
    {
        $sender = getenv('MAIL_FROM_ADDRESS');
        $baseUrl = getenv('APP_URL');
        if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
            || !is_string($baseUrl) || $baseUrl === '' || preg_match('/[\r\n]/', $baseUrl) === 1) {
            return false;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))) {
            return false;
        }
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || trim($teamName) === '' || strlen($teamName) > 100 || preg_match('//u', $teamName) !== 1) {
            return false;
        }

        $invitationUrl = rtrim($baseUrl, '/') . '/accept-invitation#accept-invitation?token=' . rawurlencode($token);
        $subject = 'You have been invited to a TeamCode Hub team';
        $message = "You have been invited to join the team \"" . trim($teamName) . "\" on TeamCode Hub.\n\n"
            . "Open the secure link below and sign in with the email address this invitation was sent to.\n\n"
            . $invitationUrl . "\n\n"
            . "If you do not have an account yet, create and verify your account first, then open this invitation link again. The invitation expires in seven days and can be used only once.\n\n"
            . "If you were not expecting this invitation, you can ignore this email.\n";
        $headers = "From: " . $sender . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        try {
            return @mail($recipient, $subject, $message, $headers);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Send a verification message through PHP's configured mail transport.
     * A production deployment must set MAIL_FROM_ADDRESS and an HTTPS APP_URL.
     */
    public function sendVerificationEmail(string $recipient, string $token): bool
    {
        $sender = getenv('MAIL_FROM_ADDRESS');
        $baseUrl = getenv('APP_URL');
        if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
            || !is_string($baseUrl) || $baseUrl === '' || preg_match('/[\r\n]/', $baseUrl) === 1) {
            return false;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))) {
            return false;
        }
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        // Keep the bearer token in the URL fragment so it is not sent in HTTP request logs.
        $verificationUrl = rtrim($baseUrl, '/') . '/verify-email#verify-email?token=' . rawurlencode($token);
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

    /**
     * Send a short-lived password reset link using the same HTTPS-only mail policy.
     * The bearer token is placed in the URL fragment so it is not sent in HTTP request logs.
     */
    public function sendPasswordResetEmail(string $recipient, string $token): bool
    {
        $sender = getenv('MAIL_FROM_ADDRESS');
        $baseUrl = getenv('APP_URL');
        if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
            || !is_string($baseUrl) || $baseUrl === '' || preg_match('/[\r\n]/', $baseUrl) === 1) {
            return false;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))) {
            return false;
        }
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        $resetUrl = rtrim($baseUrl, '/') . '/reset-password#reset-password?token=' . rawurlencode($token);
        $subject = 'Reset your TeamCode Hub password';
        $message = "A password reset was requested for your TeamCode Hub account.\n\n"
            . "If you requested this change, open the link below and choose a new password.\n\n"
            . $resetUrl . "\n\n"
            . "This link expires in 30 minutes and can be used only once. Resetting your password signs out existing sessions.\n"
            . "If you did not request this, you can ignore this message. Your current password will remain unchanged.\n";
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
