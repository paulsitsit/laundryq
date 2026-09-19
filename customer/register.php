<?php
// File: laundryq/customer/register.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/email.php';

use MongoDB\BSON\UTCDateTime;

$message = '';

$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim(
        $_POST['full_name'] ?? ''
    );

    $email = strtolower(
        trim($_POST['email'] ?? '')
    );

    $passwordInput = (string) (
        $_POST['password'] ?? ''
    );

    $blockedDomains = [
        'example.com',
        'example.org',
        'example.net',
        'test.com',
        'fake.com',
        'dummy.com',
        'mailinator.com',
        '10minutemail.com'
    ];

    $emailDomain = '';

    if (str_contains($email, '@')) {
        $emailDomain = strtolower(
            substr(strrchr($email, '@'), 1)
        );
    }

    if (
        $fullName === '' ||
        $email === '' ||
        $passwordInput === ''
    ) {
        $message = "
            <div class='alert alert-danger'>
                Please complete all fields.
            </div>
        ";
    } elseif (strlen($fullName) > 100) {
        $message = "
            <div class='alert alert-danger'>
                Full name must not exceed 100 characters.
            </div>
        ";
    } elseif (strlen($passwordInput) < 8) {
        $message = "
            <div class='alert alert-danger'>
                Password must be at least 8 characters.
            </div>
        ";
    } elseif (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        in_array($emailDomain, $blockedDomains, true) ||
        (
            !checkdnsrr($emailDomain, 'MX') &&
            !checkdnsrr($emailDomain, 'A')
        )
    ) {
        $message = "
            <div class='alert alert-danger'>
                Please use a real email address with a valid mail domain.
            </div>
        ";
    } else {
        try {
            $users = $db->users;

            $existingUser = $users->findOne([
                'email' => $email
            ]);

            $passwordHash = password_hash(
                $passwordInput,
                PASSWORD_DEFAULT
            );

            $verificationToken =
                bin2hex(random_bytes(32));

            $expiresAt = new UTCDateTime(
                (time() + 86400) * 1000
            );

            if ($existingUser) {
                $emailVerifiedAt =
                    $existingUser['email_verified_at'] ?? null;

                if ($emailVerifiedAt !== null) {
                    $message = "
                        <div class='alert alert-danger'>
                            This email is already registered.
                            Please login.
                        </div>
                    ";
                } else {
                    $users->updateOne(
                        [
                            '_id' => $existingUser['_id']
                        ],
                        [
                            '$set' => [
                                'full_name' => $fullName,
                                'password' => $passwordHash,
                                'role' => 'customer',
                                'verification_token' =>
                                    $verificationToken,
                                'verification_token_expires_at' =>
                                    $expiresAt,
                                'updated_at' =>
                                    new UTCDateTime()
                            ]
                        ]
                    );

                    if (
                        sendVerificationEmail(
                            $email,
                            $fullName,
                            $verificationToken
                        )
                    ) {
                        $message = "
                            <div class='alert alert-success'>
                                A new verification link was sent.
                                Check your email before logging in.
                            </div>
                        ";
                    } else {
                        $message = "
                            <div class='alert alert-danger'>
                                The verification email could not be sent.
                            </div>
                        ";
                    }
                }
            } else {
                $insertResult = $users->insertOne([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password' => $passwordHash,
                    'role' => 'customer',
                    'email_verified_at' => null,
                    'verification_token' =>
                        $verificationToken,
                    'verification_token_expires_at' =>
                        $expiresAt,
                    'created_at' =>
                        new UTCDateTime(),
                    'updated_at' =>
                        new UTCDateTime()
                ]);

                if (
                    sendVerificationEmail(
                        $email,
                        $fullName,
                        $verificationToken
                    )
                ) {
                    $message = "
                        <div class='alert alert-success'>
                            Registration received.
                            Check your email to verify your account.
                        </div>
                    ";
                } else {
                    $users->deleteOne([
                        '_id' => $insertResult->getInsertedId()
                    ]);

                    $message = "
                        <div class='alert alert-danger'>
                            Registration was not completed because the
                            verification email could not be sent.
                        </div>
                    ";
                }
            }
        } catch (Throwable $e) {
            error_log(
                'Registration error: ' . $e->getMessage()
            );

            $message = "
                <div class='alert alert-danger'>
                    Registration failed. Please try again.
                </div>
            ";
        }
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

    <title>Register - LaundryQ</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="mb-2">
                    <button
                        type="button"
                        class="btn btn-link p-0 me-2"
                        onclick="history.back();"
                    >
                        ← Back
                    </button>

                    <a
                        href="../index.php"
                        class="text-decoration-none"
                    >
                        Home
                    </a>
                </div>

                <div class="card shadow-sm mt-2">
                    <div class="card-body p-4">
                        <h3
                            class="text-center mb-4 text-primary"
                        >
                            Create an Account
                        </h3>

                        <?php if ($message !== ''): ?>
                            <?= $message ?>
                        <?php endif; ?>

                        <form
                            method="POST"
                            action="register.php"
                        >
                            <div class="mb-3">
                                <label
                                    for="full_name"
                                    class="form-label"
                                >
                                    Full Name
                                </label>

                                <input
                                    type="text"
                                    name="full_name"
                                    id="full_name"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= htmlspecialchars(
                                        $fullName,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    autocomplete="name"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label
                                    for="email"
                                    class="form-label"
                                >
                                    Email
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    id="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                        $email,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    autocomplete="email"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label
                                    for="password"
                                    class="form-label"
                                >
                                    Password
                                </label>

                                <div class="input-group">
                                    <input
                                        type="password"
                                        name="password"
                                        id="password"
                                        class="form-control"
                                        minlength="8"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        onclick="togglePassword(this)"
                                        aria-label="Show password"
                                    >
                                        Show
                                    </button>
                                </div>

                                <small class="text-muted">
                                    Minimum 8 characters.
                                </small>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >
                                Register
                            </button>
                        </form>

                        <p class="text-center mt-3 mb-0">
                            Already have an account?
                            <a href="login.php">
                                Login
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(button) {
            const passwordInput =
                document.getElementById('password');

            const isPassword =
                passwordInput.type === 'password';

            passwordInput.type =
                isPassword ? 'text' : 'password';

            button.textContent =
                isPassword ? 'Hide' : 'Show';

            button.setAttribute(
                'aria-label',
                isPassword ? 'Hide password' : 'Show password'
            );
        }
    </script>
</body>
</html>