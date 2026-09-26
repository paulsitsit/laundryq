<?php
// File: laundryq/admin/reservations.php

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

function objectIdString(mixed $value): string
{
    return $value instanceof ObjectId
        ? (string) $value
        : (string) ($value ?? '');
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

function dateValue(mixed $value): string
{
    if ($value instanceof UTCDateTime) {
        return $value
            ->toDateTime()
            ->format('Y-m-d H:i:s');
    }

    return (string) ($value ?? '');
}

function formatDateTime(mixed $date, mixed $time): string
{
    if (!$date || !$time) {
        return '<span class="text-muted">—</span>';
    }

    $timestamp = strtotime(
        (string) $date . ' ' . (string) $time
    );

    if ($timestamp === false) {
        return h($date) . '<br>' . h($time);
    }

    return date('M j, Y', $timestamp) .
        '<br><small class="text-muted">' .
        date('g:i A', $timestamp) .
        '</small>';
}

function badge(string $status): string
{
    $colors = [
        'Pending' => 'warning',
        'Accepted' => 'success',
        'Arrived' => 'info',
        'Washing' => 'primary',
        'Drying' => 'primary',
        'Folding' => 'primary',
        'In Progress' => 'primary',
        'Ready for Pick-Up' => 'primary',
        'Picked Up' => 'success',
        'Completed' => 'success',
        'No-Show' => 'danger',
        'Reschedule Requested' => 'info',
        'Rescheduled' => 'info',
        'Cancelled' => 'secondary',
        'Rejected' => 'danger',
        'Declined' => 'danger',
        'Service Change Requested' => 'info'
    ];

    $color = $colors[$status] ?? 'secondary';

    return '<span class="badge bg-' .
        $color .
        '">' .
        h($status) .
        '</span>';
}

function sortLink(
    string $column,
    string $label,
    string $sort,
    string $order
): string {
    $nextOrder =
        $sort === $column && $order === 'asc'
            ? 'desc'
            : 'asc';

    $arrow = '';

    if ($sort === $column) {
        $arrow = $order === 'asc' ? ' ▲' : ' ▼';
    }

    return '<a class="text-decoration-none text-reset" href="?sort=' .
        urlencode($column) .
        '&order=' .
        urlencode($nextOrder) .
        '">' .
        h($label . $arrow) .
        '</a>';
}

$sort = $_GET['sort'] ?? 'latest';
$order = $_GET['order'] ?? 'desc';
$allowedSorts = [
    'id',
    'customer',
    'date',
    'time',
    'status',
    'latest'
];

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

if (!in_array($order, ['asc', 'desc'], true)) {
    $order = 'desc';
}

$message = '';

foreach (
    [
        'message' => 'success',
        'error' => 'danger'
    ]
    as $sessionKey => $alertType
) {
    if (isset($_SESSION[$sessionKey])) {
        $message .= '<div class="alert alert-' .
            $alertType .
            ' alert-dismissible fade show" role="alert">' .
            h($_SESSION[$sessionKey]) .
            '<button type="button" class="btn-close" ' .
            'data-bs-dismiss="alert" aria-label="Close"></button>' .
            '</div>';

        unset($_SESSION[$sessionKey]);
    }
}

$reservations = [];
$loadError = '';

try {
    foreach ($db->reservations->find([]) as $document) {
        $reservation = asArray($document);

        if ($reservation === null) {
            continue;
        }

        $user = findById(
            $db->users,
            $reservation['user_id'] ?? null
        );

        $service = findById(
            $db->services,
            $reservation['service_id'] ?? null
        );

        $requestedService = findById(
            $db->services,
            $reservation['requested_service_id'] ?? null
        );

        $reservation['customer_name'] =
            $user['full_name'] ??
            $user['name'] ??
            'Unknown customer';

        $reservation['service_name'] =
            $service['service_name'] ?? '—';

        $reservation['requested_service_name'] =
            $requestedService['service_name'] ?? '—';

        $reservation['reservation_id'] =
            objectIdString($reservation['_id'] ?? null);

        $reservation['created_sort'] =
            dateValue($reservation['created_at'] ?? null);

        $reservations[] = $reservation;
    }

    usort(
        $reservations,
        function (array $a, array $b) use ($sort, $order): int {
            if ($sort === 'id') {
                $left = $a['reservation_id'];
                $right = $b['reservation_id'];
            } elseif ($sort === 'customer') {
                $left = strtolower(
                    (string) $a['customer_name']
                );
                $right = strtolower(
                    (string) $b['customer_name']
                );
            } elseif ($sort === 'date') {
                $left = (string) (
                    $a['reservation_date'] ?? ''
                );
                $right = (string) (
                    $b['reservation_date'] ?? ''
                );
            } elseif ($sort === 'time') {
                $left = (string) (
                    $a['reservation_time'] ?? ''
                );
                $right = (string) (
                    $b['reservation_time'] ?? ''
                );
            } elseif ($sort === 'status') {
                $left = strtolower((string) (
                    $a['status'] ?? ''
                ));
                $right = strtolower((string) (
                    $b['status'] ?? ''
                ));
            } else {
                $left = $a['created_sort'];
                $right = $b['created_sort'];
            }

            $comparison =
                is_numeric($left) && is_numeric($right)
                    ? ((float) $left <=> (float) $right)
                    : strnatcasecmp(
                        (string) $left,
                        (string) $right
                    );

            return $order === 'asc'
                ? $comparison
                : -$comparison;
        }
    );
} catch (Throwable $e) {
    error_log(
        'Admin reservations loading error: ' .
        $e->getMessage()
    );

    $loadError = 'Reservations could not be loaded.';
}

try {
    $serviceChangeCount =
        $db->reservations->countDocuments([
            'status' => 'Service Change Requested'
        ]);
} catch (Throwable $e) {
    error_log(
        'Reservation count error: ' .
        $e->getMessage()
    );

    $serviceChangeCount = 0;
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
    <title>Reservations - LaundryQ Admin</title>
    <link
        href="../assets/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <style>
        .action-form {
            display: flex;
            gap: .5rem;
            align-items: center;
            min-width: 260px;
        }

        @media (max-width: 992px) {
            .action-form {
                min-width: 220px;
            }
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <div class="d-flex align-items-center gap-3">
            <a
                href="dashboard.php"
                class="btn btn-outline-light btn-sm"
            >
                ← Back to Dashboard
            </a>

            <span class="navbar-brand mb-0 h1">
                📅 Reservations
            </span>
        </div>

        <div class="d-flex align-items-center">
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
    <?= $message ?>

    <?php if ($loadError !== ''): ?>
        <div class="alert alert-danger">
            <?= h($loadError) ?>
        </div>
    <?php endif; ?>

    <?php if ($serviceChangeCount > 0): ?>
        <div class="alert alert-info">
            <strong>
                🔄 <?= h($serviceChangeCount) ?>
                service change request(s)
            </strong>
            waiting for review.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div
            class="card-header bg-white d-flex
            justify-content-between align-items-center"
        >
            <h5 class="mb-0">All Reservations</h5>

            <small class="text-muted">
                Sorted by:
                <?= h(
                    $sort === 'latest'
                        ? 'Newest'
                        : ucfirst($sort)
                ) ?>
                (<?= h(strtoupper($order)) ?>)
            </small>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table
                    class="table table-hover align-middle mb-0 table-sm"
                >
                    <thead class="table-light">
                    <tr>
                        <th>
                            <?= sortLink(
                                'id',
                                'ID',
                                $sort,
                                $order
                            ) ?>
                        </th>
                        <th>
                            <?= sortLink(
                                'customer',
                                'Customer',
                                $sort,
                                $order
                            ) ?>
                        </th>
                        <th>Service</th>
                        <th>Est. Wt</th>
                        <th>
                            <?= sortLink(
                                'date',
                                'Drop-Off',
                                $sort,
                                $order
                            ) ?>
                        </th>
                        <th>Pick-Up</th>
                        <th>
                            <?= sortLink(
                                'status',
                                'Status',
                                $sort,
                                $order
                            ) ?>
                        </th>
                        <th>Actions</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (!$reservations): ?>
                        <tr>
                            <td
                                colspan="8"
                                class="text-center text-muted py-4"
                            >
                                No reservations found.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($reservations as $reservation): ?>
                        <?php
                        $id = $reservation['reservation_id'];
                        $status = (string) (
                            $reservation['status'] ?? 'Pending'
                        );

                        $rowClass = $status === 'Pending'
                            ? 'table-warning'
                            : (
                                in_array(
                                    $status,
                                    [
                                        'No-Show',
                                        'Rejected',
                                        'Declined'
                                    ],
                                    true
                                )
                                    ? 'table-danger'
                                    : (
                                        $status ===
                                        'Service Change Requested'
                                            ? 'table-info'
                                            : ''
                                    )
                            );
                        ?>

                        <tr class="<?= h($rowClass) ?>">
                            <td>
                                <strong>
                                    #<?= h($id) ?>
                                </strong>
                            </td>
                            <td>
                                <?= h(
                                    $reservation[
                                        'customer_name'
                                    ] ?? null
                                ) ?>
                            </td>
                            <td>
                                <?= h(
                                    $reservation[
                                        'service_name'
                                    ] ?? null
                                ) ?>
                            </td>
                            <td>
                                <?= h(
                                    $reservation['weight_kg']
                                    ?? null
                                ) ?> kg
                            </td>
                            <td>
                                <?= formatDateTime(
                                    $reservation[
                                        'reservation_date'
                                    ] ?? null,
                                    $reservation[
                                        'reservation_time'
                                    ] ?? null
                                ) ?>
                            </td>
                            <td>
                                <?= formatDateTime(
                                    $reservation[
                                        'pickup_date'
                                    ] ?? null,
                                    $reservation[
                                        'pickup_time'
                                    ] ?? null
                                ) ?>
                            </td>
                            <td>
                                <?= badge($status) ?>
                            </td>
                            <td>
                                <form
                                    method="POST"
                                    action="handle_reservation_action.php"
                                    class="action-form"
                                >
                                    <input
                                        type="hidden"
                                        name="reservation_id"
                                        value="<?= h($id) ?>"
                                    >

                                    <select
                                        name="action"
                                        class="form-select form-select-sm"
                                        required
                                    >
                                        <option value="">
                                            Select action
                                        </option>
                                        <option value="accept_pending">
                                            Accept
                                        </option>
                                        <option value="reject_pending">
                                            Reject
                                        </option>
                                        <option value="mark_arrived">
                                            Arrived
                                        </option>
                                        <option value="start_processing">
                                            Start
                                        </option>
                                        <option value="mark_ready_pickup">
                                            Ready for Pick-Up
                                        </option>
                                        <option value="mark_picked_up">
                                            Picked Up
                                        </option>
                                        <option value="complete">
                                            Complete
                                        </option>
                                        <option value="accept_reschedule">
                                            Accept Reschedule
                                        </option>
                                        <option value="reject_reschedule">
                                            Reject Reschedule
                                        </option>
                                        <option value="cancel">
                                            Cancel
                                        </option>
                                    </select>

                                    <button
                                        type="submit"
                                        class="btn btn-primary btn-sm"
                                    >
                                        Update
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
