<?php
// File: laundryq/index.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>LaundryQ</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="text-end mb-3">
            <a
                href="admin/login.php"
                class="text-muted text-decoration-none small"
            >
                Staff Login
            </a>
        </div>

        <div class="text-center mb-5">
            <h1 class="display-4 fw-bold text-primary">
                🧺 LaundryQ
            </h1>

            <p class="lead text-muted">
                Laundry Shop Order and Pickup Status Tracking System
            </p>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-5 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body text-center">
                        <h4 class="card-title mb-3">
                            Customer
                        </h4>

                        <a
                            href="customer/login.php"
                            class="btn btn-primary w-100 mb-2"
                        >
                            Login
                        </a>

                        <a
                            href="customer/register.php"
                            class="btn btn-outline-primary w-100 mb-2"
                        >
                            Register
                        </a><a
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>