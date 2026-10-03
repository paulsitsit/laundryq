<?php
// File: laundryq/customer/review_reservation.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

if (!isset($_SESSION['user_id'])) {
    die(
        "You must be logged in. " .
        "<a href='login.php'>Login here</a>"
    );
}

$userIdString = trim((string) $_SESSION['user_id']);

$reservationIdString = trim((string) (
    $_GET['id'] ??
    $_POST['reservation_id'] ??
    ''
));

$idPattern = '/^[a-fA-F0-9]{24}$/';

if (
    !preg_match($idPattern, $userIdString) ||
    !preg_match($idPattern, $reservationIdString)
) {
    die(
        "Invalid request. " .
        "<a href='my_reservations.php'>Back to reservations</a>"
    );
}

$userId = new ObjectId($userIdString);
$reservationId = new ObjectId($reservationIdString);

$message = '';
$reservation = null;
$existingReview = null;

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
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

try {
    $reservation = asArray(
        $db->reservations->findOne([
            '_id' => $reservationId,
            'user_id' => $userId
        ])
    );

    if ($reservation === null) {
        die(
            "Reservation not found. " .
            "<a href='my_reservations.php'>Back to reservations</a>"
        );
    }

    if (($reservation['status'] ?? '') !== 'Completed') {
        die(
            "You can review only completed reservations. " .
            "<a href='view_reservation.php?id=" .
            urlencode($reservationIdString) .
            "'>Back to reservation</a>"
        );
    }

    $existingReview = asArray(
        $db->reviews->findOne([
            'reservation_id' => $reservationId,
            'user_id' => $userId
        ])
    );
} catch (Throwable $e) {
    error_log(
        'Review page loading error: ' .
        $e->getMessage()
    );

    die(
        "The review page could not be loaded. " .
        "<a href='my_reservations.php'>Back to reservations</a>"
    );
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'submit_review'
) {
    $rating = filter_var(
        $_POST['rating'] ?? null,
        FILTER_VALIDATE_INT
    );

    $note = trim((string) (
        $_POST['note'] ?? ''
    ));

    if (
        $rating === false ||
        $rating < 1 ||
        $rating > 5
    ) {
        $message = "
            <div class='alert alert-danger'>
                Please choose a rating from 1 to 5 stars.
            </div>
        ";
    } elseif (mb_strlen($note) > 1000) {
        $message = "
            <div class='alert alert-danger'>
                Your review note cannot exceed 1,000 characters.
            </div>
        ";
    } elseif ($existingReview !== null) {
        $message = "
            <div class='alert alert-warning'>
                You have already reviewed this reservation.
            </div>
        ";
    } else {
        try {
            $db->reviews->insertOne([
                'reservation_id' => $reservationId,
                'user_id' => $userId,
                'rating' => $rating,
                'note' => $note,
                'created_at' => new UTCDateTime(),
                'updated_at' => new UTCDateTime()
            ]);

            $existingReview = [
                'rating' => $rating,
                'note' => $note
            ];

            $message = "
                <div class='alert alert-success'>
                    Thank you! Your review has been submitted.
                </div>
            ";
        } catch (Throwable $e) {
            error_log(
                'Review submission error: ' .
                $e->getMessage()
            );

            $message = "
                <div class='alert alert-danger'>
                    Your review could not be saved. Please try again.
                </div>
            ";
        }
    }
}

$serviceName = 'Laundry Service';

try {
    $serviceId = $reservation['service_id'] ?? null;

    if ($serviceId instanceof ObjectId) {
        $service = asArray(
            $db->services->findOne([
                '_id' => $serviceId
            ])
        );

        if ($service !== null) {
            $serviceName = (string) (
                $service['service_name'] ??
                'Laundry Service'
            );
        }
    }
} catch (Throwable $e) {
    error_log(
        'Review service lookup error: ' .
        $e->getMessage()
    );
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
    <title>Rate Your Service - LaundryQ</title>
    <link
        href="../assets/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <style>
        .star-rating {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: .25rem;
        }

        .star-rating input {
            display: none;
        }

        .star-rating label {
            color: #adb5bd;
            cursor: pointer;
            font-size: 2.75rem;
            line-height: 1;
        }

        .star-rating label:hover,
        .star-rating label:hover ~ label,
        .star-rating input:checked ~ label {
            color: #ffc107;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                <div class="mb-3">
                    <a
                        href="view_reservation.php?id=<?= urlencode(
                            $reservationIdString
                        ) ?>"
                        class="btn btn-outline-secondary btn-sm"
                    >
                        ← Back to Reservation
                    </a>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-warning">
                        <h4 class="mb-0">
                            ⭐ Rate Your Laundry Service
                        </h4>
                    </div>

                    <div class="card-body p-4">
                        <?= $message ?>

                        <p class="mb-1">
                            <strong>Service:</strong>
                            <?= h($serviceName) ?>
                        </p>

                        <p class="text-muted mb-4">
                            Reservation:
                            #<?= h($reservationIdString) ?>
                        </p>

                        <?php if ($existingReview !== null): ?>
                            <?php
                            $savedRating = max(
                                0,
                                min(
                                    5,
                                    (int) (
                                        $existingReview['rating'] ?? 0
                                    )
                                )
                            );
                            ?>

                            <div class="alert alert-info">
                                You have already submitted a review for this
                                reservation.
                            </div>

                            <div class="mb-3">
                                <div class="text-warning fs-2">
                                    <?= str_repeat('★', $savedRating) ?>
                                    <?= str_repeat(
                                        '☆',
                                        5 - $savedRating
                                    ) ?>
                                </div>

                                <small class="text-muted">
                                    <?= h($savedRating) ?> out of 5 stars
                                </small>
                            </div>

                            <?php if (
                                trim((string) (
                                    $existingReview['note'] ?? ''
                                )) !== ''
                            ): ?>
                                <div class="border rounded bg-light p-3">
                                    <?= nl2br(
                                        h($existingReview['note'])
                                    ) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST">
                                <input
                                    type="hidden"
                                    name="reservation_id"
                                    value="<?= h($reservationIdString) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="submit_review"
                                >

                                <div class="mb-4">
                                    <label class="form-label fw-bold">
                                        Your Rating
                                    </label>

                                    <div class="star-rating">
                                        <input
                                            type="radio"
                                            id="star5"
                                            name="rating"
                                            value="5"
                                            required
                                        >
                                        <label
                                            for="star5"
                                            title="5 stars"
                                        >
                                            ★
                                        </label>

                                        <input
                                            type="radio"
                                            id="star4"
                                            name="rating"
                                            value="4"
                                        >
                                        <label
                                            for="star4"
                                            title="4 stars"
                                        >
                                            ★
                                        </label>

                                        <input
                                            type="radio"
                                            id="star3"
                                            name="rating"
                                            value="3"
                                        >
                                        <label
                                            for="star3"
                                            title="3 stars"
                                        >
                                            ★
                                        </label>

                                        <input
                                            type="radio"
                                            id="star2"
                                            name="rating"
                                            value="2"
                                        >
                                        <label
                                            for="star2"
                                            title="2 stars"
                                        >
                                            ★
                                        </label>

                                        <input
                                            type="radio"
                                            id="star1"
                                            name="rating"
                                            value="1"
                                        >
                                        <label
                                            for="star1"
                                            title="1 star"
                                        >
                                            ★
                                        </label>
                                    </div>

                                    <small class="text-muted">
                                        Select from 1 to 5 stars.
                                    </small>
                                </div>

                                <div class="mb-4">
                                    <label
                                        for="note"
                                        class="form-label fw-bold"
                                    >
                                        Review Note
                                    </label>

                                    <textarea
                                        name="note"
                                        id="note"
                                        class="form-control"
                                        rows="5"
                                        maxlength="1000"
                                        placeholder="Tell us about your experience..."
                                    ></textarea>

                                    <small class="text-muted">
                                        Optional. Maximum 1,000 characters.
                                    </small>
                                </div>

                                <button
                                    type="submit"
                                    class="btn btn-warning w-100"
                                >
                                    Submit Rating and Review
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>