<?php
// File: laundryq/config/email.php

declare(strict_types=1);

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

function sendBrevoEmail(
    string $recipientEmail,
    string $recipientName,
    string $subject,
    string $htmlContent,
    string $textContent
): bool {
    if (
        $recipientEmail === '' ||
        !filter_var(
            $recipientEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        error_log(
            'Brevo email skipped: invalid recipient email.'
        );

        return false;
    }

    $apiKey = environmentValue(
        'BREVO_API_KEY'
    );

    $senderEmail = environmentValue(
        'MAIL_FROM_ADDRESS'
    );

    $senderName = environmentValue(
        'MAIL_FROM_NAME',
        'LaundryQ'
    );

    if ($apiKey === '') {
        error_log(
            'Brevo email failed: BREVO_API_KEY is missing.'
        );

        return false;
    }

    if (
        $senderEmail === '' ||
        !filter_var(
            $senderEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        error_log(
            'Brevo email failed: sender email is invalid.'
        );

        return false;
    }

    $payload = json_encode(
        [
            'sender' => [
                'name' => $senderName,
                'email' => $senderEmail
            ],
            'to' => [
                [
                    'email' => $recipientEmail,
                    'name' => $recipientName
                ]
            ],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
            'textContent' => $textContent
        ],
        JSON_UNESCAPED_UNICODE
    );

    if ($payload === false) {
        error_log(
            'Brevo email failed: payload encoding error.'
        );

        return false;
    }

    $curl = curl_init(
        'https://api.brevo.com/v3/smtp/email'
    );

    if ($curl === false) {
        error_log(
            'Brevo email failed: cURL could not initialize.'
        );

        return false;
    }

    curl_setopt_array(
        $curl,
        [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json'
            ],
            CURLOPT_POSTFIELDS => $payload
        ]
    );

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    curl_close($curl);

    if ($response === false) {
        error_log(
            'Brevo email cURL error: ' . $curlError
        );

        return false;
    }

    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {
        error_log(
            'Brevo email API error. HTTP ' .
            $httpCode .
            ': ' .
            $response
        );

        return false;
    }

    return true;
}

function sendVerificationEmail(
    string $email,
    string $fullName,
    string $verificationToken
): bool {
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

    $htmlContent = "
        <!DOCTYPE html>
        <html lang='en'>
        <body style='
            font-family:Arial,sans-serif;
            line-height:1.6;
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
                    color:#fff;
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

    $textContent =
        "Welcome to LaundryQ.\n\n" .
        "Verify your email here:\n" .
        $verificationUrl . "\n\n" .
        "This link expires in 24 hours.";

    return sendBrevoEmail(
        $email,
        $fullName,
        'Verify your LaundryQ account',
        $htmlContent,
        $textContent
    );
}

function sendReservationStatusEmail(
    string $email,
    string $fullName,
    string $reservationId,
    string $status
): bool {
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

    $htmlContent = "
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
                background:#fff;
                border-radius:12px;
                padding:28px;
            >
                <h2 style='color:#0d6efd;'>
                    LaundryQ Reservation Update
                </h2>

                <p>
                    Hello <strong>{$safeName}</strong>,
                </p>

                <p>
                    Your reservation status was updated.
                </p>

                <div style='
                    margin:24px 0;
                    padding:18px;
                    border-left:5px solid #0d6efd;
                    background:#f0f6ff;
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
                    Please log in to LaundryQ for more details.
                </p>

                <p>
                    Thank you,<br>
                    <strong>LaundryQ Team</strong>
                </p>
            </div>
        </body>
        </html>
    ";

    $textContent =
        "Hello {$fullName},\n\n" .
        "Your LaundryQ reservation " .
        "#{$reservationId} status is now: " .
        "{$status}.\n\n" .
        "LaundryQ Team";

    return sendBrevoEmail(
        $email,
        $fullName,
        'LaundryQ reservation status update',
        $htmlContent,
        $textContent
    );
}