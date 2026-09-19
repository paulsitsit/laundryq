<?php
// File: laundryq/customer/reserve.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$message = '';

/*
|--------------------------------------------------------------------------
| Check login session
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    die(
        "You must be logged in to make a reservation. " .
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
$today = date('Y-m-d');

/*
|--------------------------------------------------------------------------
| Create reservation
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim(
        $_POST['reservation_date'] ?? ''
    );

    $time = trim(
        $_POST['reservation_time'] ?? ''
    );

    $pickupDate = trim(
        $_POST['pickup_date'] ?? ''
    );

    $pickupTime = trim(
        $_POST['pickup_time'] ?? ''
    );

    $serviceType = trim(
        $_POST['service_type'] ?? 'Drop-off'
    );

    $serviceId = trim(
        $_POST['service_id'] ?? ''
    );

    $weightKg = filter_var(
        $_POST['weight_kg'] ?? null,
        FILTER_VALIDATE_FLOAT
    );

    $notes = trim(
        $_POST['notes'] ?? ''
    );

    $validDate = DateTime::createFromFormat(
        '!Y-m-d',
        $date
    );

    $validPickupDate = DateTime::createFromFormat(
        '!Y-m-d',
        $pickupDate
    );

    $dateIsValid =
        $validDate &&
        $validDate->format('Y-m-d') === $date;

    $pickupDateIsValid =
        $validPickupDate &&
        $validPickupDate->format('Y-m-d') === $pickupDate;

    $timeIsValid = preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
        $time
    );

    $pickupTimeIsValid =
        $pickupTime === '' ||
        preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            $pickupTime
        );

    if (
        !$dateIsValid ||
        !$pickupDateIsValid
    ) {
        $message = "
            <div class='alert alert-danger'>
                Please select valid reservation and pick-up dates.
            </div>
        ";
    } elseif ($date < $today) {
        $message = "
            <div class='alert alert-danger'>
                Reservation date cannot be in the past.
            </div>
        ";
    } elseif ($pickupDate < $date) {
        $message = "
            <div class='alert alert-danger'>
                Pick-up date must be on or after the reservation date.
            </div>
        ";
    } elseif (
        !$timeIsValid ||
        !$pickupTimeIsValid
    ) {
        $message = "
            <div class='alert alert-danger'>
                Please select valid reservation and pick-up times.
            </div>
        ";
    } elseif (
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
            $holiday = $db->holidays->findOne([
                'holiday_date' => $date
            ]);

            if ($holiday) {
                $safeDate = htmlspecialchars(
                    $date,
                    ENT_QUOTES,
                    'UTF-8'
                );

                $holidayName = htmlspecialchars(
                    (string) (
                        $holiday['holiday_name']
                        ?? 'Holiday'
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                );

                $message = "
                    <div class='alert alert-danger'>
                        Sorry, {$safeDate} is a holiday
                        ({$holidayName}).
                        The shop is closed on this date.
                    </div>
                ";
            } else {
                $service = $db->services->findOne([
                    '_id' => new ObjectId($serviceId),
                    'is_active' => [
                        '$ne' => false
                    ]
                ]);

                if (!$service) {
                    $message = "
                        <div class='alert alert-danger'>
                            The selected service was not found
                            or is inactive.
                        </div>
                    ";
                } else {
                    $minimumKg = (float) (
                        $service['min_kg'] ?? 0
                    );

                    if ($weightKg < $minimumKg) {
                        $message = "
                            <div class='alert alert-danger'>
                                Minimum weight for this service is " .
                                htmlspecialchars(
                                    (string) $minimumKg,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) .
                                "kg.
                            </div>
                        ";
                    } else {
                        $db->reservations->insertOne([
                            'user_id' => $userId,

                            'reservation_date' => $date,
                            'reservation_time' => $time,

                            'requested_date' => null,
                            'requested_time' => null,

                            'original_date' => null,
                            'original_time' => null,

                            'pickup_date' => $pickupDate,
                            'pickup_time' => $pickupTime,

                            'service_type' => $serviceType,
                            'service_id' =>
                                new ObjectId($serviceId),

                            'weight_kg' =>
                                (float) $weightKg,

                            'notes' => $notes,

                            'status' => 'Pending',
                            'notified' => false,
                            'service_change_result' => null,

                            'updated_by' => null,
                            'admin_notes' => null,
                            'cancellation_reason' => null,

                            'created_at' =>
                                new UTCDateTime(),

                            'updated_at' =>
                                new UTCDateTime()
                        ]);

                        $message = "
                            <div class='alert alert-success'>
                                Reservation submitted!
                                Waiting for admin confirmation.
                            </div>
                        ";
                    }
                }
            }
        } catch (Throwable $e) {
            error_log(
                'Reservation error: ' .
                $e->getMessage()
            );

            $message = "
                <div class='alert alert-danger'>
                    The reservation could not be saved.
                    Please try again.
                </div>
            ";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load active services
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

/*
|--------------------------------------------------------------------------
| Load holidays
|--------------------------------------------------------------------------
*/

$holidays = $db->holidays->find(
    [
        'holiday_date' => [
            '$gte' => $today
        ]
    ],
    [
        'sort' => [
            'holiday_date' => 1
        ]
    ]
);

$holidayDates = [];
$holidayNames = [];
$holidayListHtml = '';

foreach ($holidays as $holiday) {
    $holidayDate = (string) (
        $holiday['holiday_date'] ?? ''
    );

    $holidayName = (string) (
        $holiday['holiday_name'] ?? ''
    );

    if ($holidayDate === '') {
        continue;
    }

    $holidayDates[] = $holidayDate;
    $holidayNames[$holidayDate] = $holidayName;

    $holidayListHtml .= '<li>' .
        htmlspecialchars(
            date('F j, Y', strtotime($holidayDate)),
            ENT_QUOTES,
            'UTF-8'
        ) .
        ' — ' .
        htmlspecialchars(
            $holidayName,
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</li>';
}

$holidayDatesJson = json_encode(
    $holidayDates,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

$holidayNamesJson = json_encode(
    $holidayNames,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

if ($holidayDatesJson === false) {
    $holidayDatesJson = '[]';
}

if ($holidayNamesJson === false) {
    $holidayNamesJson = '{}';
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

    <title>Book a Reservation - LaundryQ</title>

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
                        📅 Book a Reservation
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

                <?php if ($message !== ''): ?>
                    <?= $message ?>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form
                            method="POST"
                            action="reserve.php"
                            id="reserveForm"
                        >
                            <div class="mb-3">
                                <label
                                    for="serviceType"
                                    class="form-label"
                                >
                                    Type
                                </label>

                                <select
                                    name="service_type"
                                    id="serviceType"
                                    class="form-select"
                                    required
                                >
                                    <option value="Drop-off">
                                        Drop-off
                                    </option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label
                                    for="serviceSelect"
                                    class="form-label"
                                >
                                    Laundry Service
                                </label>

                                <select
                                    name="service_id"
                                    id="serviceSelect"
                                    class="form-select"
                                    required
                                >
                                    <?php
                                    $serviceCount = 0;

                                    foreach ($services as $row):
                                        $serviceCount++;

                                        $serviceObjectId =
                                            (string) (
                                                $row['_id'] ?? ''
                                            );

                                        $serviceName =
                                            (string) (
                                                $row['service_name']
                                                ?? 'Unnamed service'
                                            );

                                        $basePrice =
                                            (float) (
                                                $row['base_price'] ?? 0
                                            );

                                        $extraPerKg =
                                            (float) (
                                                $row['extra_per_kg'] ?? 0
                                            );

                                        $minKg =
                                            (float) (
                                                $row['min_kg'] ?? 0
                                            );

                                        $maxKg =
                                            (float) (
                                                $row['max_kg'] ?? 0
                                            );
                                    ?>
                                        <option
                                            value="<?= htmlspecialchars(
                                                $serviceObjectId,
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

                            <div class="mb-3">
                                <label
                                    for="dateInput"
                                    class="form-label"
                                >
                                    Date
                                </label>

                                <input
                                    type="date"
                                    name="reservation_date"
                                    id="dateInput"
                                    class="form-control"
                                    min="<?= htmlspecialchars(
                                        $today,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    required
                                >

                                <small
                                    id="holidayWarning"
                                    class="text-danger d-none"
                                ></small>
                            </div>

                            <div class="mb-3">
                                <label
                                    for="timeInput"
                                    class="form-label"
                                >
                                    Time
                                </label>

                                <input
                                    type="time"
                                    name="reservation_time"
                                    id="timeInput"
                                    class="form-control"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label
                                    for="pickupDateInput"
                                    class="form-label"
                                >
                                    Pick-up Date
                                </label>

                                <input
                                    type="date"
                                    name="pickup_date"
                                    id="pickupDateInput"
                                    class="form-control"
                                    min="<?= htmlspecialchars(
                                        $today,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label
                                    for="pickupTimeInput"
                                    class="form-label"
                                >
                                    Pick-up Time (optional)
                                </label>

                                <input
                                    type="time"
                                    name="pickup_time"
                                    id="pickupTimeInput"
                                    class="form-control"
                                >
                            </div>

                            <div class="mb-3">
                                <label
                                    for="notesInput"
                                    class="form-label"
                                >
                                    Notes (optional)
                                </label>

                                <textarea
                                    name="notes"
                                    id="notesInput"
                                    class="form-control"
                                    rows="2"
                                    maxlength="1000"
                                    placeholder="e.g. gate code, preferred contact number"
                                ></textarea>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                                id="submitBtn"
                                <?= $serviceCount === 0
                                    ? 'disabled'
                                    : '' ?>
                            >
                                Submit Reservation
                            </button>
                        </form>
                    </div>
                </div>

                <?php if (!empty($holidayDates)): ?>
                    <div class="card shadow-sm mt-3">
                        <div class="card-header bg-white">
                            <strong>
                                📌 Shop Closed on These Dates
                            </strong>
                        </div>

                        <div class="card-body">
                            <ul class="mb-0 small">
                                <?= $holidayListHtml ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <p class="text-center mt-3">
                    <a href="my_reservations.php">
                        📋 View My Reservations
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script>
        const holidayDates = <?= $holidayDatesJson ?>;
        const holidayNames = <?= $holidayNamesJson ?>;

        const dateInput =
            document.getElementById('dateInput');

        const pickupDateInput =
            document.getElementById('pickupDateInput');

        const warning =
            document.getElementById('holidayWarning');

        const submitBtn =
            document.getElementById('submitBtn');

        const select =
            document.getElementById('serviceSelect');

        const weightInput =
            document.getElementById('weightInput');

        const hint =
            document.getElementById('weightHint');

        const estimate =
            document.getElementById('priceEstimate');

        function checkHoliday() {
            const selected = dateInput.value;

            if (holidayDates.includes(selected)) {
                const holidayName =
                    holidayNames[selected] || 'Holiday';

                warning.textContent =
                    `⚠️ The shop is closed on this date ` +
                    `(${holidayName}). Please choose another date.`;

                warning.classList.remove('d-none');
                submitBtn.disabled = true;
            } else {
                warning.textContent = '';
                warning.classList.add('d-none');

                if (
                    select.options.length > 0 &&
                    select.value !== ''
                ) {
                    submitBtn.disabled = false;
                }
            }
        }

        function checkPickupDate() {
            if (
                pickupDateInput.value &&
                dateInput.value &&
                pickupDateInput.value < dateInput.value
            ) {
                pickupDateInput.value = dateInput.value;
            }
        }

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

        dateInput.addEventListener(
            'change',
            function () {
                pickupDateInput.min =
                    dateInput.value || '<?= $today ?>';

                checkHoliday();
                checkPickupDate();
            }
        );

        pickupDateInput.addEventListener(
            'change',
            checkPickupDate
        );

        select.addEventListener(
            'change',
            updateEstimate
        );

        weightInput.addEventListener(
            'input',
            updateEstimate
        );

        updateEstimate();
        checkHoliday();
    </script>
</body>
</html>