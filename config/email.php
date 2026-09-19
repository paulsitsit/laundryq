<?php
// File: laundryq/config/email.php

declare(strict_types=1);

require_once __DIR__ .
    '/../vendor/phpmailer/phpmailer/src/Exception.php';

require_once __DIR__ .
    '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';

require_once __DIR__ .
    '/../vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function environmentValue(
    string $name,
    string $default = ''
): string {
    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        return $default;
    }

    return trim($value);
}

function configureLaundryQMailer(
    PHPMailer $mail
): void {
    $smtpHost = environmentValue(
        'BREVO_SMTP_HOST',
        'smtp-relay.brevo.com'
    );

    $smtpUsername = environmentValue(
        'BREVO_SMTP_USERNAME'
    );

    $smtpPassword = environmentValue(
        'BREVO_SMTP_PASSWORD'
    );

    $senderEmail = environmentValue(
        'MAIL_FROM_ADDRESS'
    );

    $senderName = environmentValue(
        'MAIL_FROM_NAME',
        'LaundryQ'
    );

    if (
        $smtpUsername === '' ||
        $smtpPassword === '' ||
        $senderEmail === ''
    ) {
        throw new RuntimeException(
            'SMTP environment variables are missing.'
        );
    }

    if (
        !filter_var(
            $senderEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            'MAIL_FROM_ADDRESS is invalid.'
        );
    }

    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;

    $mail->SMTPSecure =
        PHPMailer::ENCRYPTION_STARTTLS;

    $mail->Port = 587;
    $mail->Timeout = 30;
    $mail->CharSet = 'UTF-8';
    $mail->SMTPDebug = 0;

    $mail->setFrom(
        $senderEmail,
        $senderName
    );
}

function laundryqBaseUrl(): string
{
    return rtrim(
        environmentValue(
            'APP_URL',
            'http://localhost:8000'
        ),
        '/'
    );
}

function sendVerificationEmail(
    string $email,
    string $fullName,
    string $verificationToken
): bool {
    if (
        $email === '' ||
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        error_log(
            'Verification email skipped: invalid email.'
        );

        return false;
    }

    $mail = new PHPMailer(true);

    try {
        configureLaundryQMailer($mail);

        $mail->addAddress(
            $email,
            $fullName
        );

        $mail->isHTML(true);

        $mail->Subject =
            'Verify your LaundryQ account';

        $verificationUrl =
            laundryqBaseUrl() .
            '/customer/verify_email.php?token=' .
            urlencode($verificationToken);

        $safeName = htmlspecialchars(
            $fullName,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeUrl = htmlspecialchars(
            $verificationUrl,
            ENT_QUOTES,
            'UTF-8'
        );

        $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <body style='
                font-family: Arial, sans-serif;
                line-height: 1.6;
            >
                <h2>
                    Welcome to LaundryQ,
                    {$safeName}
                </h2>

                <p>
                    Please verify your email address.
                </p>

                <p>
                    <a href='{$safeUrl}' style='
                        display:inline-block;
                        padding:12px 20px;
                        color:#ffffff;
                        background:#0d6efd;
                        text-decoration:none;
                        border-radius:5px;
                    '>
                        Verify Email Address
                    </a>
                </p>

                <p>
                    This link expires in 24 hours.
                </p>
            </body>
            </html>
        ";

        $mail->AltBody =
            "Welcome to LaundryQ.\n\n" .
            "Verify your email address here:\n" .
            $verificationUrl . "\n\n" .
            "This link expires in 24 hours.";

        $mail->send();

        return true;
    } catch (Throwable $e) {
        error_log(
            'Verification email error: ' .
            $e->getMessage()
        );

        return false;
    }
}

function sendReservationStatusEmail(
    string $email,
    string $fullName,
    string $reservationId,
    string $status
): bool {
    if (
        $email === '' ||
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        error_log(
            'Reservation status email skipped: invalid email.'
        );

        return false;
    }

    $mail = new PHPMailer(true);

    try {
        configureLaundryQMailer($mail);

        $mail->addAddress(
            $email,
            $fullName
        );

        $mail->isHTML(true);

        $mail->Subject =
            'LaundryQ reservation status update';

        $safeName = htmlspecialchars(
            $fullName,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeReservationId = htmlspecialchars(
            $reservationId,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeStatus = htmlspecialchars(
            $status,
            ENT_QUOTES,
            'UTF-8'
        );

        $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <body style='
                margin:0;
                padding:24px;
                background:#f5f7fb;
                font-family:Arial,sans-serif;
                color:#212529;
            >
                <div style='
                    max-width:560px;
                    margin:auto;
                    background:#ffffff;
                    border-radius:12px;
                    padding:28px;
                    box-shadow:0 2px 12px
                        rgba(0,0,0,.08);
                >
                    <h2 style='color:#0d6efd;'>
                        LaundryQ Reservation Update
                    </h2>

                    <p>
                        Hello
                        <strong>{$safeName}</strong>,
                    </p>

                    <p>
                        Your reservation status has been
                        updated by the LaundryQ administrator.
                    </p>

                    <div style='
                        margin:24px 0;
                        padding:18px;
                        border-left:5px solid #0d6efd;
                        background:#f0f6ff;
                        border-radius:6px;
                    >
                        <p>
                            <strong>Reservation:</strong>
                            #{$safeReservationId}
                        </p>

                        <p>
                            <strong>New status:</strong>
                            <span style='color:#0d6efd;'>
                                {$safeStatus}
                            </span>
                        </p>
                    </div>

                    <p>
                        Please log in to LaundryQ
                        for more details.
                    </p>

                    <p>
                        Thank you,<br>
                        <strong>LaundryQ Team</strong>
                    </p>
                </div>
            </body>
            </html>
        ";

        $mail->AltBody =
            "Hello {$fullName},\n\n" .
            "Your LaundryQ reservation " .
            "#{$reservationId} status is now: " .
            "{$status}.\n\n" .
            "LaundryQ Team";

        $mail->send();

        return true;
    } catch (Throwable $e) {
        error_log(
            'Reservation status email error: ' .
            $mail->ErrorInfo
        );

        return false;
    }
}