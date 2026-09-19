<?php
// File: laundryq/customer/place_order.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$message = '';
$notifHtml = '';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    die(
        "You must be logged in to place an order. " .
        "<a href='login.php'>Login here</a>"
    );
}

$userIdString = trim(
    (string) $_SESSION['user_id']
);

if (
    !preg_match(
        '/^[a-fA-F0-9]{24}$/',
        $userIdString
    )
) {
    session_destroy();

    die(
        "Invalid user session. " .
        "<a href='login.php'>Please login again</a>"
    );
}

$userId = new ObjectId($userIdString);

/*
|--------------------------------------------------------------------------
| Place order
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = trim(
        $_POST['service_id'] ?? ''
    );

    $weightKg = filter_var(
        $_POST['weight_kg'] ?? null,
        FILTER_VALIDATE_FLOAT
    );

    if (
        !preg_match(
            '/^[a-fA-F0-9]{24}$/',
            $serviceId
        )
    ) {
        $message = "
            <div class='alert alert-danger'>
                Invalid service selected.
            </div>
        ";
    } elseif (
        $weightKg === false ||
        $weightKg <= 0
    ) {
        $message = "
            <div class='alert alert-danger'>
                Please enter a valid laundry weight.
            </div>
        ";
    } else {
        try {
            $service = $db->services->findOne([
                '_id' => new ObjectId($serviceId),
                'is_active' => [
                    '$ne' => false
                ]
            ]);

            if (!$service) {
                $message = "
                    <div class='alert alert-danger'>
                        The selected service was not found or is inactive.
                    </div>
                ";
            } else {
                $minKg = (float) (
                    $service['min_kg'] ?? 0
                );

                $maxKg = (float) (
                    $service['max_kg'] ?? 0
                );

                $basePrice = (float) (
                    $service['base_price'] ?? 0
                );

                $extraPerKg = (float) (
                    $service['extra_per_kg'] ?? 0
                );

                if ($weightKg < $minKg) {
                    $message = "
                        <div class='alert alert-danger'>
                            Minimum weight for this service is " .
                            htmlspecialchars(
                                (string) $minKg,
                                ENT_QUOTES,
                                'UTF-8'
                            ) .
                            "kg.
                        </div>
                    ";
                } else {
                    $totalAmount = $basePrice;

                    if ($weightKg > $maxKg) {
                        $totalAmount +=
                            ($weightKg - $maxKg) * $extraPerKg;
                    }

                    $db->orders->insertOne([
                        'user_id' => $userId,
                        'service_id' =>
                            new ObjectId($serviceId),
                        'weight_kg' =>
                            (float) $weightKg,
                        'total_amount' =>
                            round($totalAmount, 2),
                        'status' => 'Pending',
                        'notified' => false,
                        'service_change_result' => null,
                        'created_at' =>
                            new UTCDateTime(),
                        'updated_at' =>
                            new UTCDateTime()
                    ]);

                    $message = "
                        <div class='alert alert-success'>
                            Order placed successfully!
                            Total: ₱" .
                            number_format($totalAmount, 2) .
                            "
                        </div>
                    ";
                }
            }
        } catch (Throwable $e) {
            error_log(
                'Order creation error: ' .
                $e->getMessage()
            );

            $message = "
                <div class='alert alert-danger'>
                    The order could not be saved.
                    Please try again.
                </div>
            ";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Reservation notifications
|--------------------------------------------------------------------------
*/

try {
    $reservationNotifications = $db->reservations->find(
        [
            'user_id' => $userId,
            'notified' => false
        ],
        [
            'sort' => [
                'created_at' => -1
            ]
        ]
    );

    $reservationIdsToMark = [];

    foreach ($reservationNotifications as $notification) {
        $reservationIdsToMark[] =
            $notification['_id'];

        $reservationId = htmlspecialchars(
            (string) $notification['_id'],
            ENT_QUOTES,
            'UTF-8'
        );

        $serviceType = htmlspecialchars(
            (string) (
                $notification['service_type'] ?? ''
            ),
            ENT_QUOTES,
            'UTF-8'
        );

        $reservationDate = htmlspecialchars(
            (string) (
                $notification['reservation_date'] ?? ''
            ),
            ENT_QUOTES,
            'UTF-8'
        );

        $reservationTime = htmlspecialchars(
            (string) (
                $notification['reservation_time'] ?? ''
            ),
            ENT_QUOTES,
            'UTF-8'
        );

        $status = (string) (
            $notification['status'] ?? ''
        );

        $changeResult =
            $notification['service_change_result']
            ?? null;

        if ($changeResult === 'approved') {
            $notifHtml .= "
                <div class='alert alert-success'>
                    ✅ Your service change request for reservation
                    #{$reservationId} was
                    <strong>approved</strong>.
                </div>
            ";
        } elseif ($changeResult === 'declined') {
            $notifHtml .= "
                <div class='alert alert-danger'>
                    ❌ Your service change request for reservation
                    #{$reservationId} was
                    <strong>declined</strong>.
                </div>
            ";
        } elseif ($status === 'Accepted') {
            $notifHtml .= "
                <div class='alert alert-success'>
                    ✅ Your {$serviceType} reservation for
                    {$reservationDate} at {$reservationTime}
                    was <strong>accepted</strong>.
                </div>
            ";
        } elseif (
            $status === 'Declined' ||
            $status === 'Rejected'
        ) {
            $notifHtml .= "
                <div class='alert alert-danger'>
                    ❌ Your reservation for
                    {$reservationDate} at {$reservationTime}
                    was <strong>declined</strong>.
                </div>
            ";
        } elseif ($status === 'Ready for Pick-Up') {
            $notifHtml .= "
                <div class='alert alert-info'>
                    📦 Your laundry is
                    <strong>ready for pick-up</strong>.
                </div>
            ";
        } elseif ($status === 'No-Show') {
            $notifHtml .= "
                <div class='alert alert-warning'>
                    ⚠️ You were marked as a
                    <strong>No-Show</strong>.
                </div>
            ";
        }
    }

    if (!empty($reservationIdsToMark)) {
        $db->reservations->updateMany(
            [
                '_id' => [
                    '$in' => $reservationIdsToMark
                ],
                'user_id' => $userId,
                'notified' => false
            ],
            [
                '$set' => [
                    'notified' => true,
                    'updated_at' =>
                        new UTCDateTime()
                ]
            ]
        );
    }
} catch (Throwable $e) {
    error_log(
        'Reservation notification error: ' .
        $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Order notifications
|--------------------------------------------------------------------------
*/

try {
    $orderNotifications = $db->orders->find(
        [
            'user_id' => $userId,
            'notified' => false,
            'service_change_result' => [
                '$in' => [
                    'approved',
                    'declined'
                ]
            ]
        ],
        [
            'sort' => [
                'created_at' => -1
            ]
        ]
    );

    $orderIdsToMark = [];

    foreach ($orderNotifications as $notification) {
        $orderIdsToMark[] =
            $notification['_id'];

        $orderId = htmlspecialchars(
            (string) $notification['_id'],
            ENT_QUOTES,
            'UTF-8'
        );

        $changeResult =
            $notification['service_change_result']
            ?? null;

        if ($changeResult === 'approved') {
            $notifHtml .= "
                <div class='alert alert-success'>
                    ✅ Your service change request for order
                    #{$orderId} was <strong>approved</strong>.
                </div>
            ";
        } elseif ($changeResult === 'declined') {
            $notifHtml .= "
                <div class='alert alert-danger'>
                    ❌ Your service change request for order
                    #{$orderId} was <strong>declined</strong>.
                </div>
            ";
        }
    }

    if (!empty($orderIdsToMark)) {
        $db->orders->updateMany(
            [
                '_id' => [
                    '$in' => $orderIdsToMark
                ],
                'user_id' => $userId,
                'notified' => false
            ],
            [
                '$set' => [
                    'notified' => true,
                    'updated_at' =>
                        new UTCDateTime()
                ]
            ]
        );
    }
} catch (Throwable $e) {
    error_log(
        'Order notification error: ' .
        $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Load services
|--------------------------------------------------------------------------
*/

$services = $db->services->find(
    [
        'is_active' => [
            '$ne' => false
        ]
    ],
    [
        'sort' => [
            'service_name' => 1
        ]
    ]
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Place Order - LaundryQ</title>

    <link
        href="../assets/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <a
                    href="../index.php"
                    class="text-decoration-none d-block mb-2"
                >
                    ← Back to Home
                </a>

                <div
                    class="d-flex justify-content-between align-items-center mb-4"
                >
                    <h3 class="text-primary mb-0">
                        🧺 Place a Laundry Order
                    </h3>

                    <a
                        href="logout.php"
                        class="btn btn-outline-secondary btn-sm"
                    >
                        Logout
                    </a>
                </div>

                <p class="text-muted">
                    Logged in as:
                    <strong>
                        <?= htmlspecialchars(
                            $_SESSION['full_name'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </strong>
                </p>

                <?= $notifHtml ?>

                <?php if ($message !== ''): ?>
                    <?= $message ?>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form
                            method="POST"
                            action="place_order.php"
                        >
                            <div class="mb-3">
                                <label
                                    for="serviceSelect"
                                    class="form-label"
                                >
                                    Service
                                </label>

                                <select
                                    name="service_id"
                                    id="serviceSelect"
                                    class="form-select"
                                    required
                                >
                                    <?php
                                    $serviceCount = 0;

                                    foreach ($services as $service):
                                        $serviceCount++;

                                        $serviceIdValue =
                                            (string) $service['_id'];

                                        $serviceName =
                                            (string) (
                                                $service['service_name']
                                                ?? 'Unnamed service'
                                            );

                                        $basePrice =
                                            (float) (
                                                $service['base_price'] ?? 0
                                            );

                                        $extraPerKg =
                                            (float) (
                                                $service['extra_per_kg'] ?? 0
                                            );

                                        $minKg =
                                            (float) (
                                                $service['min_kg'] ?? 0
                                            );

                                        $maxKg =
                                            (float) (
                                                $service['max_kg'] ?? 0
                                            );
                                    ?>
                                        <option
                                            value="<?= htmlspecialchars(
                                                $serviceIdValue,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            data-base="<?= $basePrice ?>"
                                            data-extra="<?= $extraPerKg ?>"
                                            data-min="<?= $minKg ?>"
                                            data-max="<?= $maxKg ?>"
                                        >
                                            <?= htmlspecialchars(
                                                $serviceName,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                            -
                                            ₱<?= number_format(
                                                $basePrice,
                                                2
                                            ) ?>
                                            (<?= $minKg ?>–<?= $maxKg ?>kg),
                                            +₱<?= number_format(
                                                $extraPerKg,
                                                2
                                            ) ?>/kg after
                                        </option>
                                    <?php endforeach; ?>

                                    <?php if ($serviceCount === 0): ?>
                                        <option
                                            value=""
                                            disabled
                                            selected
                                        >
                                            No active services available
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label
                                    for="weightInput"
                                    class="form-label"
                                >
                                    Weight (kg)
                                </label>

                                <input
                                    type="number"
                                    name="weight_kg"
                                    id="weightInput"
                                    class="form-control"
                                    min="0.1"
                                    step="0.1"
                                    required
                                >

                                <small
                                    id="weightHint"
                                    class="text-muted"
                                ></small>
                            </div>

                            <div
                                id="priceEstimate"
                                class="alert alert-light border"
                            >
                                Estimated Total:
                                <strong>₱0.00</strong>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                                <?= $serviceCount === 0
                                    ? 'disabled'
                                    : '' ?>
                            >
                                Place Order
                            </button>
                        </form>
                    </div>
                </div>

                <p class="text-center mt-3">
                    <a href="track_order.php">
                        📦 View My Orders
                    </a>
                    ·
                    <a href="reserve.php">
                        📅 Book a Reservation
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script>
        const select =
            document.getElementById('serviceSelect');

        const weightInput =
            document.getElementById('weightInput');

        const hint =
            document.getElementById('weightHint');

        const estimate =
            document.getElementById('priceEstimate');

        function updateEstimate() {
            if (
                !select ||
                select.options.length === 0 ||
                select.value === ''
            ) {
                estimate.innerHTML =
                    'Estimated Total: <strong>₱0.00</strong>';

                return;
            }

            const option =
                select.options[select.selectedIndex];

            const base =
                parseFloat(option.dataset.base || '0');

            const extra =
                parseFloat(option.dataset.extra || '0');

            const min =
                parseFloat(option.dataset.min || '0');

            const max =
                parseFloat(option.dataset.max || '0');

            const weight =
                parseFloat(weightInput.value || '0');

            weightInput.min = min;

            hint.textContent =
                `Minimum ${min}kg. Flat ₱${base.toFixed(2)} ` +
                `covers up to ${max}kg, then ` +
                `+₱${extra.toFixed(2)}/kg after.`;

            if (
                !Number.isNaN(weight) &&
                weight >= min
            ) {
                let total = base;

                if (weight > max) {
                    total += (weight - max) * extra;
                }

                estimate.innerHTML =
                    `Estimated Total: ` +
                    `<strong>₱${total.toFixed(2)}</strong>`;
            } else {
                estimate.innerHTML =
                    'Estimated Total: <strong>₱0.00</strong>';
            }
        }

        if (select) {
            select.addEventListener(
                'change',
                updateEstimate
            );
        }

        if (weightInput) {
            weightInput.addEventListener(
                'input',
                updateEstimate
            );
        }

        updateEstimate();
    </script>
</body>
</html>