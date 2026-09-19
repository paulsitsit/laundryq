<?php
// File: laundryq/customer/login.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/email.php';

use MongoDB\BSON\UTCDateTime;

$message = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(
        trim($_POST['email'] ?? '')
    );

    $password = (string) (
        $_POST['password'] ?? ''
    );

    if ($email === '' || $password === '') {
        $message = "
            <div class='alert alert-danger'>
                Please enter your email and password.
            </div>
        ";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "
            <div class='alert alert-danger'>
                Please enter a valid email address.
            </div>
        ";
    } else {
        try {
            $user = $db->users->findOne([
                'email' => $email
            ]);

            if (!$user) {
                $message = "
                    <div class='alert alert-danger'>
                        No account found with that email.
                    </div>
                ";
            } else {
                $storedPassword = (string) (
                    $user['password'] ??
                    $user['password_hash'] ??
                    ''
                );

                if (
                    $storedPassword === '' ||
                    !password_verify($password, $storedPassword)
                ) {
                    $message = "
                        <div class='alert alert-danger'>
                            Incorrect email or password.
                        </div>
                    ";
                } else {
                    $emailVerifiedAt =
                        $user['email_verified_at'] ?? null;

                    if ($emailVerifiedAt === null) {
                        $verificationToken =
                            bin2hex(random_bytes(32));

                        $db->users->updateOne(
                            [
                                '_id' => $user['_id']
                            ],
                            [
                                '$set' => [
                                    'verification_token' =>
                                        $verificationToken,

                                    'verification_token_expires_at' =>
                                        new UTCDateTime(
                                            (time() + 86400) * 1000
                                        ),

                                    'updated_at' =>
                                        new UTCDateTime()
                                ]
                            ]
                        );

                        $fullName = (string) (
                            $user['full_name'] ?? 'Customer'
                        );

                        if (
                            sendVerificationEmail(
                                $email,
                                $fullName,
                                $verificationToken
                            )
                        ) {
                            $message = "
                                <div class='alert alert-warning'>
                                    Please verify your email address.
                                    A new verification link was sent.
                                    The link expires in 24 hours.
                                </div>
                            ";
                        } else {
                            $message = "
                                <div class='alert alert-danger'>
                                    Your email is not verified and the
                                    verification email could not be sent.
                                </div>
                            ";
                        }
                    } else {
                        session_regenerate_id(true);

                        $_SESSION['user_id'] =
                            (string) $user['_id'];

                        $_SESSION['full_name'] =
                            (string) (
                                $user['full_name'] ?? ''
                            );

                        $_SESSION['role'] =
                            (string) (
                                $user['role'] ?? 'customer'
                            );

                        $_SESSION['login_time'] = time();

                        header(
                            'Location: place_order.php'
                        );

                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log(
                'Login error: ' . $e->getMessage()
            );

            $message = "
                <div class='alert alert-danger'>
                    A database error occurred.
                    Please try again later.
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

    <title>Login - LaundryQ</title>

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
                            Login to LaundryQ
                        </h3>

                        <?php if ($message !== ''): ?>
                            <?= $message ?>
                        <?php endif; ?>

                        <form
                            method="POST"
                            action="login.php"
                        >
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
                                        autocomplete="current-password"
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
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >
                                Login
                            </button>
                        </form>

                        <p class="text-center mt-3 mb-0">
                            Don't have an account?
                            <a href="register.php">
                                Register here
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