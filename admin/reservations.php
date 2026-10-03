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
    string $order,
    string $statusFilter
): string {
    $nextOrder =
        $sort === $column && $order === 'asc'
            ? 'desc'
            : 'asc';

    $arrow = '';

    if ($sort === $column) {
        $arrow = $order === 'asc' ? ' ▲' : ' ▼';
    }

    $query = [
        'sort' => $column,
        'order' => $nextOrder
    ];

    if ($statusFilter !== '') {
        $query['status'] = $statusFilter;
    }

    return '<a class="text-decoration-none text-reset" href="?' .
        http_build_query($query) .
        '">' .
        h($label . $arrow) .
        '</a>';
}

/*
|--------------------------------------------------------------------------
| Reservation Actions
|--------------------------------------------------------------------------
|
| Every action remains visible in the dropdown. The enabled value is
| true only for valid forward workflow actions.
|
| Workflow:
| Pending → Accepted → Arrived → Washing → Drying → Folding
| → Ready for Pick-Up → Picked Up → Completed
|
*/

function allActionsForStatus(string $status): array
{
    $allActions = [
        'accept_pending' => 'Accept',
        'reject_pending' => 'Reject',
        'mark_arrived' => 'Arrived',
        'mark_no_show' => 'No-Show',
        'start_washing' => 'Start Washing',
        'start_drying' => 'Start Drying',
        'start_folding' => 'Start Folding',
        'mark_ready_pickup' => 'Ready for Pick-Up',
        'mark_picked_up' => 'Picked Up',
        'complete' => 'Complete',
        'cancel' => 'Cancel'
    ];

    $enabledActions = [
        'Pending' => [
            'accept_pending',
            'reject_pending',
            'cancel'
        ],
        'Accepted' => [
            'mark_arrived',
            'mark_no_show',
            'cancel'
        ],
        'Rescheduled' => [
            'mark_arrived',
            'mark_no_show',
            'cancel'
        ],
        'Arrived' => [
            'start_washing',
            'cancel'
        ],
        'Washing' => [
            'start_drying',
            'cancel'
        ],
        'Drying' => [
            'start_folding',
            'cancel'
        ],
        'Folding' => [
            'mark_ready_pickup',
            'cancel'
        ],
        'Ready for Pick-Up' => [
            'mark_picked_up'
        ],
        'Picked Up' => [
            'complete'
        ]
    ];

    $enabled = $enabledActions[$status] ?? [];
    $result = [];

    foreach ($allActions as $value => $label) {
        $result[$value] = [
            'label' => $label,
            'enabled' => in_array(
                $value,
                $enabled,
                true
            )
        ];
    }

    return $result;
}

$statuses = [
    'Pending',
    'Accepted',
    'Arrived',
    'Washing',
    'Drying',
    'Folding',
    'In Progress',
    'Ready for Pick-Up',
    'Picked Up',
    'Completed',
    'No-Show',
    'Reschedule Requested',
    'Rescheduled',
    'Cancelled',
    'Rejected',
    'Declined',
    'Service Change Requested'
];

$sort = trim((string) (
    $_GET['sort'] ?? 'latest'
));

$order = trim((string) (
    $_GET['order'] ?? 'desc'
));

$statusFilter = trim((string) (
    $_GET['status'] ?? ''
));

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

if (
    $statusFilter !== '' &&
    !in_array($statusFilter, $statuses, true)
) {
    $statusFilter = '';
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

/*
|--------------------------------------------------------------------------
| MongoDB Filter
|--------------------------------------------------------------------------
|
| Default dashboard mode:
| Show only active or unfinished reservations.
|
| Hidden by default:
| - Completed
| - Cancelled
|
| They can still be viewed by selecting their specific status from the
| status filter.
|
*/

$mongoFilter = [];

if ($statusFilter !== '') {
    $mongoFilter['status'] = $statusFilter;
} else {
    $mongoFilter['status'] = [
        '$nin' => [
            'Completed',
            'Cancelled'
        ]
    ];
}

try {
    foreach ($db->reservations->find($mongoFilter) as $document) {
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
            min-width: 280px;
        }

        .action-form select option:disabled {
            color: #6c757d;
        }

        @media (max-width: 992px) {
            .action-form {
                min-width: 235px;
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
            class="card-header bg-white d-flex flex-wrap
            justify-content-between align-items-center gap-2"
        >
            <div>
                <h5 class="mb-0">Reservations</h5>

                <small class="text-muted">
                    <?php if ($statusFilter === ''): ?>
                        Showing active/uncompleted reservations.
                        Completed and cancelled records are hidden.
                    <?php else: ?>
                        Showing reservations with status:
                        <?= h($statusFilter) ?>
                    <?php endif; ?>
                </small>
            </div>

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

        <div class="card-body border-bottom">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4">
                    <label
                        for="statusFilter"
                        class="form-label small mb-1"
                    >
                        Filter by Status
                    </label>

                    <select
                        name="status"
                        id="statusFilter"
                        class="form-select form-select-sm"
                    >
                        <option value="">
                            Active / Uncompleted Reservations
                        </option>

                        <?php foreach ($statuses as $filterStatus): ?>
                            <option
                                value="<?= h($filterStatus) ?>"
                                <?= $statusFilter === $filterStatus
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= h($filterStatus) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <input
                    type="hidden"
                    name="sort"
                    value="<?= h($sort) ?>"
                >

                <input
                    type="hidden"
                    name="order"
                    value="<?= h($order) ?>"
                >

                <div class="col-auto">
                    <button
                        type="submit"
                        class="btn btn-primary btn-sm"
                    >
                        Filter
                    </button>

                    <a
                        href="reservations.php"
                        class="btn btn-outline-secondary btn-sm"
                    >
                        Reset
                    </a>
                </div>
            </form>
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
                                $order,
                                $statusFilter
                            ) ?>
                        </th>

                        <th>
                            <?= sortLink(
                                'customer',
                                'Customer',
                                $sort,
                                $order,
                                $statusFilter
                            ) ?>
                        </th>

                        <th>Service</th>
                        <th>Est. Wt</th>

                        <th>
                            <?= sortLink(
                                'date',
                                'Drop-Off',
                                $sort,
                                $order,
                                $statusFilter
                            ) ?>
                        </th>

                        <th>Pick-Up</th>

                        <th>
                            <?= sortLink(
                                'status',
                                'Status',
                                $sort,
                                $order,
                                $statusFilter
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
                                No reservations found for this filter.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($reservations as $reservation): ?>
                        <?php
                        $id = $reservation['reservation_id'];

                        $status = (string) (
                            $reservation['status'] ?? 'Pending'
                        );

                        $actions = allActionsForStatus($status);

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
                                    $reservation['weight_kg'] ?? null
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

                                        <?php foreach (
                                            $actions as
                                            $actionValue => $actionInfo
                                        ): ?>
                                            <option
                                                value="<?= h(
                                                    $actionValue
                                                ) ?>"
                                                <?= !$actionInfo['enabled']
                                                    ? 'disabled'
                                                    : '' ?>
                                            >
                                                <?= h(
                                                    $actionInfo['label']
                                                ) ?>
                                                <?= !$actionInfo['enabled']
                                                    ? ' — Unavailable'
                                                    : '' ?>
                                            </option>
                                        <?php endforeach; ?>
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

<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>