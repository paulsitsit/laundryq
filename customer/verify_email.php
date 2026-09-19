<?php
// File: laundryq/customer/verify_email.php

declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\UTCDateTime;

$message = '';

$token = trim(
    $_GET['token'] ?? ''
);

if ($token === '') {
    $message = "
        <div class='alert alert-danger'>
            Invalid verification link.
        </div>
    ";
} else {
    try {
        $user = $db->users->findOne([
            'verification_token' => $token,
            'email_verified_at' => null
        ]);

        if (!$user) {
            $message = "
                <div class='alert alert-danger'>
                    This verification link is invalid or has already been used.
                </div>
            ";
        } else {
            $expiresAt =
                $user['verification_token_expires_at'] ?? null;

            if (!$expiresAt instanceof UTCDateTime) {
                $message = "
                    <div class='alert alert-danger'>
                        This verification link is invalid.
                    </div>
                ";
            } elseif (
                $expiresAt->toDateTime()->getTimestamp() < time()
            ) {
                $message = "
                    <div class='alert alert-danger'>
                        This verification link has expired.
                    </div>
                ";
            } else {
                $result = $db->users->updateOne(
                    [
                        '_id' => $user['_id'],
                        'verification_token' => $token,
                        'email_verified_at' => null
                    ],
                    [
                        '$set' => [
                            'email_verified_at' =>
                                new UTCDateTime(),
                            'updated_at' =>
                                new UTCDateTime()
                        ],
                        '$unset' => [
                            'verification_token' => '',
                            'verification_token_expires_at' => ''
                        ]
                    ]
                );

                if ($result->getModifiedCount() === 1) {
                    $message = "
                        <div class='alert alert-success'>
                            Your email has been verified.
                            You can now login.
                        </div>
                    ";
                } else {
                    $message = "
                        <div class='alert alert-danger'>
                            Verification could not be completed.
                        </div>
                    ";
                }
            }
        }
    } catch (Throwable $e) {
        error_log(
            'Email verification error: ' . $e->getMessage()
        );

        $message = "
            <div class='alert alert-danger'>
                A database error occurred.
                Please try again later.
            </div>
        ";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Verify Email - LaundryQ</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body p-4 text-center">
                        <h3 class="text-primary mb-4">
                            Email Verification
                        </h3>

                        <?= $message ?>

                        <a
                            href="login.php"
                            class="btn btn-primary"
                        >
                            Go to Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>