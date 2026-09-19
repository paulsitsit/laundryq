<?php
// File: laundryq/customer/change_reservation_service.php

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

$userIdString = trim(
    (string) $_SESSION['user_id']
);

$reservationIdString = trim(
    (string) (
        $_GET['reservation_id'] ??
        $_POST['reservation_id'] ??
        ''
    )
);

if (
    !preg_match(
        '/^[a-fA-F0-9]{24}$/',
        $userIdString
    ) ||
    !preg_match(
        '/^[a-fA-F0-9]{24}$/',
        $reservationIdString
    )
) {
    die('Invalid user or reservation ID.');
}

$userId = new ObjectId($userIdString);

$reservationId = new ObjectId(
    $reservationIdString
);

$message = '';

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

try {
    $reservation = $db->reservations->findOne([
        '_id' => $reservationId,
        'user_id' => $userId
    ]);

    if (!$reservation) {
        die(
            "Reservation not found. " .
            "<a href='track_order.php'>Back to My Orders</a>"
        );
    }

    $currentService = null;

    if (
        isset($reservation['service_id']) &&
        $reservation['service_id'] instanceof ObjectId
    ) {
        $currentService = $db->services->findOne([
            '_id' => $reservation['service_id']
        ]);
    }

    $currentStatus = (string) (
        $reservation['status'] ?? ''
    );

    if (
        !in_array(
            $currentStatus,
            [
                'Pending',
                'Accepted'
            ],
            true
        )
    ) {
        die(
            "This reservation can no longer be changed. " .
            "<a href='track_order.php'>Back to My Orders</a>"
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $newServiceIdString = trim(
            $_POST['service_id'] ?? ''
        );

        $newWeightKg = filter_var(
            $_POST['weight_kg'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        if (
            !preg_match(
                '/^[a-fA-F0-9]{24}$/',
                $newServiceIdString
            )
        ) {
            $message = "
                <div class='alert alert-danger'>
                    Invalid service selected.
                </div>
            ";
        } elseif (
            $newWeightKg === false ||
            $newWeightKg <= 0
        ) {
            $message = "
                <div class='alert alert-danger'>
                    Please enter a valid weight.
                </div>
            ";
        } else {
            $newServiceId = new ObjectId(
                $newServiceIdString
            );

            $newService = $db->services->findOne([
                '_id' => $newServiceId,
                'is_active' => [
                    '$ne' => false
                ]
            ]);

            if (!$newService) {
                $message = "
                    <div class='alert alert-danger'>
                        The selected service was not found.
                    </div>
                ";
            } else {
                $minimumKg = (float) (
                    $newService['min_kg'] ?? 0
                );

                if ($newWeightKg < $minimumKg) {
                    $message = "
                        <div class='alert alert-danger'>
                            Minimum weight for this service is " .
                            h($minimumKg) .
                            "kg.
                        </div>
                    ";
                } else {
                    $result =
                        $db->reservations->updateOne(
                            [
                                '_id' => $reservationId,
                                'user_id' => $userId,
                                'status' => [
                                    '$in' => [
                                        'Pending',
                                        'Accepted'
                                    ]
                                ]
                            ],
                            [
                                '$set' => [
                                    'requested_service_id' =>
                                        $newServiceId,

                                    'requested_weight_kg' =>
                                        (float) $newWeightKg,

                                    'status' =>
                                        'Service Change Requested',

                                    'service_change_result' =>
                                        'none',

                                    'notified' => false,

                                    'updated_at' =>
                                        new UTCDateTime()
                                ]
                            ]
                        );

                    if (
                        $result->getModifiedCount() === 1
                    ) {
                        header(
                            'Location: track_order.php'
                        );

                        exit;
                    }

                    $message = "
                        <div class='alert alert-danger'>
                            Could not submit the service change request.
                        </div>
                    ";
                }
            }
        }
    }

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
} catch (Throwable $e) {
    error_log(
        'Change reservation service error: ' .
        $e->getMessage()
    );

    die(
        "The reservation could not be loaded. " .
        "<a href='track_order.php'>Back to My Orders</a>"
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

    <title>Change Reservation Service - LaundryQ</title>

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
                    href="track_order.php"
                    class="text-decoration-none d-block mb-2"
                >
                    ← Back to My Orders
                </a>

                <h3 class="text-primary mb-4">
                    🔄 Change Reservation Service
                </h3>

                <?php if ($message !== ''): ?>
                    <?= $message ?>
                <?php endif; ?>

                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <p class="mb-1">
                            <strong>Current Service:</strong>
                            <?= h(
                                $currentService[
                                    'service_name'
                                ] ?? '—'
                            ) ?>
                        </p>

                        <p class="mb-0">
                            <strong>Current Weight:</strong>
                            <?= h(
                                $reservation['weight_kg']
                                ?? '—'
                            ) ?>
                            kg
                        </p>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form
                            method="POST"
                            action="change_reservation_service.php"
                        >
                            <input
                                type="hidden"
                                name="reservation_id"
                                value="<?= h(
                                    $reservationIdString
                                ) ?>"
                            >

                            <div class="mb-3">
                                <label
                                    for="serviceSelect"
                                    class="form-label"
                                >
                                    New Service
                                </label>

                                <select
                                    name="service_id"
                                    id="serviceSelect"
                                    class="form-select"
                                    required
                                >
                                    <?php foreach (
                                        $services
                                        as $service
                                    ): ?>
                                        <?php
                                        $serviceId =
                                            (string) $service['_id'];

                                        $serviceName =
                                            (string) (
                                                $service[
                                                    'service_name'
                                                ]
                                                ?? 'Unnamed'
                                            );

                                        $minKg =
                                            (float) (
                                                $service[
                                                    'min_kg'
                                                ] ?? 0
                                            );

                                        $maxKg =
                                            (float) (
                                                $service[
                                                    'max_kg'
                                                ] ?? 0
                                            );

                                        $selected =
                                            isset(
                                                $reservation[
                                                    'service_id'
                                                ]
                                            ) &&
                                            (string) (
                                                $reservation[
                                                    'service_id'
                                                ]
                                            ) === $serviceId;
                                        ?>
                                        <option
                                            value="<?= h(
                                                $serviceId
                                            ) ?>"
                                            data-min="<?= $minKg ?>"
                                            <?= $selected
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= h($serviceName) ?>
                                            (<?= $minKg ?>–<?= $maxKg ?>kg)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label
                                    for="weightInput"
                                    class="form-label"
                                >
                                    New Weight (kg)
                                </label>

                                <input
                                    type="number"
                                    name="weight_kg"
                                    id="weightInput"
                                    class="form-control"
                                    min="0.1"
                                    step="0.1"
                                    value="<?= h(
                                        $reservation['weight_kg']
                                        ?? ''
                                    ) ?>"
                                    required
                                >

                                <small
                                    id="weightHint"
                                    class="text-muted"
                                ></small>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >
                                Submit Change Request
                            </button>
                        </form>
                    </div>
                </div>
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

        function updateHint() {
            if (
                !select ||
                select.options.length === 0
            ) {
                return;
            }

            const option =
                select.options[select.selectedIndex];

            const min =
                parseFloat(option.dataset.min || '0');

            weightInput.min = min;

            hint.textContent =
                `Minimum ${min}kg for this service.`;
        }

        select.addEventListener(
            'change',
            updateHint
        );

        updateHint();
    </script>
</body>
</html>