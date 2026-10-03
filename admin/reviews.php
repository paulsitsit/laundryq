<?php
// File: laundryq/admin/reviews.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

if (!isset($_SESSION['admin_id'])) {
    die(
        "You must be logged in as admin. " .
        "<a href='login.php'>Login here</a>"
    );
}

function h(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function asArray(mixed $document): ?array
{
    if ($document === null) {
        return null;
    }

    if (is_array($document)) {
        return $document;
    }

    if (method_exists($document, 'getArrayCopy')) {
        return $document->getArrayCopy();
    }

    return null;
}

function findById($collection, mixed $id): ?array
{
    if (!$id instanceof ObjectId) {
        return null;
    }

    return asArray(
        $collection->findOne([
            '_id' => $id
        ])
    );
}

function formatDateValue(mixed $value): string
{
    if ($value instanceof UTCDateTime) {
        return $value
            ->toDateTime()
            ->setTimezone(
                new DateTimeZone('Asia/Manila')
            )
            ->format('M j, Y g:i A');
    }

    return h($value);
}

$reviews = [];
$loadError = '';

try {
    foreach (
        $db->reviews->find(
            [],
            [
                'sort' => [
                    'created_at' => -1
                ]
            ]
        ) as $document
    ) {
        $review = asArray($document);

        if ($review === null) {
            continue;
        }

        $customer = findById(
            $db->users,
            $review['user_id'] ?? null
        );

        $reservation = findById(
            $db->reservations,
            $review['reservation_id'] ?? null
        );

        $review['customer_name'] =
            $customer['full_name'] ??
            $customer['name'] ??
            'Unknown customer';

        $review['reservation_status'] =
            $reservation['status'] ??
            '—';

        $review['reservation_id_text'] =
            (string) (
                $review['reservation_id'] ?? ''
            );

        $reviews[] = $review;
    }
} catch (Throwable $e) {
    error_log(
        'Admin reviews loading error: ' .
        $e->getMessage()
    );

    $loadError = 'Reviews could not be loaded.';
}

$totalReviews = count($reviews);
$totalRating = 0;

foreach ($reviews as $review) {
    $totalRating += (int) (
        $review['rating'] ?? 0
    );
}

$averageRating = $totalReviews > 0
    ? $totalRating / $totalReviews
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Customer Reviews - LaundryQ Admin</title>
    <link
        href="../assets/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-3">
            <a
                href="dashboard.php"
                class="btn btn-outline-light btn-sm"
            >
                ← Dashboard
            </a>

            <span class="navbar-brand mb-0 h1">
                ⭐ Customer Reviews
            </span>
        </div>

        <div>
            <span class="text-white me-3">
                Logged in as:
                <?= h($_SESSION['admin_name'] ?? 'Admin') ?>
            </span>

            <a
                href="logout.php"
                class="btn btn-outline-light btn-sm"
            >
                Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid pb-5">
    <?php if ($loadError !== ''): ?>
        <div class="alert alert-danger">
            <?= h($loadError) ?>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body">
                    <h6 class="text-muted">Total Reviews</h6>
                    <h3><?= h($totalReviews) ?></h3>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-3">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body">
                    <h6 class="text-muted">Average Rating</h6>
                    <h3 class="text-warning">
                        <?= number_format($averageRating, 1) ?>/5
                    </h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Submitted Reviews</h5>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Customer</th>
                        <th>Reservation ID</th>
                        <th>Rating</th>
                        <th>Review</th>
                        <th>Status</th>
                        <th>Submitted</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (!$reviews): ?>
                        <tr>
                            <td
                                colspan="6"
                                class="text-center text-muted py-4"
                            >
                                No reviews have been submitted yet.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($reviews as $review): ?>
                        <?php
                        $rating = max(
                            0,
                            min(
                                5,
                                (int) (
                                    $review['rating'] ?? 0
                                )
                            )
                        );
                        ?>
                        <tr>
                            <td>
                                <?= h(
                                    $review['customer_name']
                                ) ?>
                            </td>

                            <td>
                                <small>
                                    <?= h(
                                        $review[
                                            'reservation_id_text'
                                        ]
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <span class="text-warning fs-5">
                                    <?= str_repeat('★', $rating) ?>
                                    <?= str_repeat(
                                        '☆',
                                        5 - $rating
                                    ) ?>
                                </span>
                                <br>
                                <small class="text-muted">
                                    <?= h($rating) ?>/5
                                </small>
                            </td>

                            <td style="max-width: 360px;">
                                <?= nl2br(
                                    h($review['note'] ?? '')
                                ) ?>
                            </td>

                            <td>
                                <span class="badge bg-success">
                                    <?= h(
                                        $review[
                                            'reservation_status'
                                        ]
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?= formatDateValue(
                                    $review['created_at'] ?? null
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>