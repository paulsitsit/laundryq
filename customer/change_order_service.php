<?php
// File: laundryq/customer/change_order_service.php

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

$orderIdString = trim(
    (string) (
        $_GET['order_id'] ??
        $_POST['order_id'] ??
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
        $orderIdString
    )
) {
    die('Invalid user or order ID.');
}

$userId = new ObjectId($userIdString);
$orderId = new ObjectId($orderIdString);

$message = '';

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function validObjectIdString(string $id): bool
{
    return (bool) preg_match(
        '/^[a-fA-F0-9]{24}$/',
        $id
    );
}

try {
    $order = $db->orders->findOne([
        '_id' => $orderId,
        'user_id' => $userId
    ]);

    if (!$order) {
        die(
            "Order not found. " .
            "<a href='track_order.php'>Back to My Orders</a>"
        );
    }

    $currentService = null;

    if (
        isset($order['service_id']) &&
        $order['service_id'] instanceof ObjectId
    ) {
        $currentService = $db->services->findOne([
            '_id' => $order['service_id']
        ]);
    }

    if (($order['status'] ?? '') !== 'Pending') {
        die(
            "This order can no longer be changed. " .
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
            !validObjectIdString(
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
                $minKg = (float) (
                    $newService['min_kg'] ?? 0
                );

                $maxKg = (float) (
                    $newService['max_kg'] ?? 0
                );

                $basePrice = (float) (
                    $newService['base_price'] ?? 0
                );

                $extraPerKg = (float) (
                    $newService['extra_per_kg'] ?? 0
                );

                if ($newWeightKg < $minKg) {
                    $message = "
                        <div class='alert alert-danger'>
                            Minimum weight for this service is " .
                            h($minKg) .
                            "kg.
                        </div>
                    ";
                } else {
                    $newTotal = $basePrice;

                    if ($newWeightKg > $maxKg) {
                        $newTotal +=
                            ($newWeightKg - $maxKg)
                            * $extraPerKg;
                    }

                    $result = $db->orders->updateOne(
                        [
                            '_id' => $orderId,
                            'user_id' => $userId,
                            'status' => 'Pending'
                        ],
                        [
                            '$set' => [
                                'requested_service_id' =>
                                    $newServiceId,

                                'requested_weight_kg' =>
                                    (float) $newWeightKg,

                                'requested_total_amount' =>
                                    round($newTotal, 2),

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
                            The order may have already changed.
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
        'Change order service error: ' .
        $e->getMessage()
    );

    die(
        "The order could not be loaded. " .
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

    <title>Change Service - LaundryQ</title>

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
                    🔄 Change Service
                </h3>

                <p class="text-muted">
                    Order ID:
                    <?= h($orderIdString) ?>
                </p>

                <?php if ($message !== ''): ?>
                    <?= $message ?>
                <?php endif; ?>

                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <p class="mb-1">
                            <strong>Current Service:</strong>
                            <?= h(
                                $currentService['service_name']
                                ?? '—'
                            ) ?>
                        </p>

                        <p class="mb-0">
                            <strong>Current Weight:</strong>
                            <?= h(
                                $order['weight_kg'] ?? '—'
                            ) ?>
                            kg

                            —
                            <strong>Total:</strong>
                            ₱<?= number_format(
                                (float) (
                                    $order['total_amount']
                                    ?? 0
                                ),
                                2
                            ) ?>
                        </p>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form
                            method="POST"
                            action="change_order_service.php"
                        >
                            <input
                                type="hidden"
                                name="order_id"
                                value="<?= h($orderIdString) ?>"
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

                                        $base =
                                            (float) (
                                                $service[
                                                    'base_price'
                                                ] ?? 0
                                            );

                                        $extra =
                                            (float) (
                                                $service[
                                                    'extra_per_kg'
                                                ] ?? 0
                                            );

                                        $min =
                                            (float) (
                                                $service[
                                                    'min_kg'
                                                ] ?? 0
                                            );

                                        $max =
                                            (float) (
                                                $service[
                                                    'max_kg'
                                                ] ?? 0
                                            );

                                        $isSelected =
                                            isset(
                                                $order['service_id']
                                            ) &&
                                            (string) (
                                                $order[
                                                    'service_id'
                                                ]
                                            ) === $serviceId;
                                        ?>
                                        <option
                                            value="<?= h(
                                                $serviceId
                                            ) ?>"
                                            data-base="<?= $base ?>"
                                            data-extra="<?= $extra ?>"
                                            data-min="<?= $min ?>"
                                            data-max="<?= $max ?>"
                                            <?= $isSelected
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= h($serviceName) ?>
                                            -
                                            ₱<?= number_format(
                                                $base,
                                                2
                                            ) ?>
                                            (<?= $min ?>–<?= $max ?>kg),
                                            +₱<?= number_format(
                                                $extra,
                                                2
                                            ) ?>/kg after
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
                                        $order['weight_kg']
                                        ?? ''
                                    ) ?>"
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
                                New Estimated Total:
                                <strong>₱0.00</strong>
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

        const estimate =
            document.getElementById('priceEstimate');

        function updateEstimate() {
            if (
                !select ||
                select.options.length === 0
            ) {
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
                    `New Estimated Total: ` +
                    `<strong>₱${total.toFixed(2)}</strong>`;
            } else {
                estimate.innerHTML =
                    'New Estimated Total: ' +
                    '<strong>₱0.00</strong>';
            }
        }

        select.addEventListener(
            'change',
            updateEstimate
        );

        weightInput.addEventListener(
            'input',
            updateEstimate
        );

        updateEstimate();
    </script>
</body>
</html>