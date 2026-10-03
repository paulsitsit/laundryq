<?php
// File: laundryq/customer/reserve.php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db_connect.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$message = '';

if (!isset($_SESSION['user_id'])) {
    die(
        "You must be logged in to make a reservation. " .
        "<a href='login.php'>Login here</a>"
    );
}

$userIdString = trim((string) $_SESSION['user_id']);

if (!preg_match('/^[a-fA-F0-9]{24}$/', $userIdString)) {
    session_destroy();

    die(
        "Invalid user session. " .
        "<a href='login.php'>Please login again</a>"
    );
}

$userId = new ObjectId($userIdString);
$today = date('Y-m-d');

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function isValidDateValue(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed !== false &&
        $parsed->format('Y-m-d') === $date;
}

function isValidTimeValue(string $time): bool
{
    return preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
        $time
    ) === 1;
}

function holidayName(mixed $holiday): string
{
    if ($holiday === null) {
        return 'Holiday';
    }

    if (is_array($holiday)) {
        return (string) (
            $holiday['holiday_name'] ?? 'Holiday'
        );
    }

    if (method_exists($holiday, 'getArrayCopy')) {
        $data = $holiday->getArrayCopy();

        return (string) (
            $data['holiday_name'] ?? 'Holiday'
        );
    }

    return 'Holiday';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) (
        $_POST['reservation_date'] ?? ''
    ));

    $time = trim((string) (
        $_POST['reservation_time'] ?? ''
    ));

    $pickupDate = trim((string) (
        $_POST['pickup_date'] ?? ''
    ));

    $pickupTime = trim((string) (
        $_POST['pickup_time'] ?? ''
    ));

    $serviceType = trim((string) (
        $_POST['service_type'] ?? 'Drop-off'
    ));

    $serviceId = trim((string) (
        $_POST['service_id'] ?? ''
    ));

    $weightKg = filter_var(
        $_POST['weight_kg'] ?? null,
        FILTER_VALIDATE_FLOAT
    );

    $notes = trim((string) (
        $_POST['notes'] ?? ''
    ));

    if (
        !isValidDateValue($date) ||
        !isValidDateValue($pickupDate)
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
        !isValidTimeValue($time) ||
        (
            $pickupTime !== '' &&
            !isValidTimeValue($pickupTime)
        )
    ) {
        $message = "
            <div class='alert alert-danger'>
                Please select valid reservation and pick-up times.
            </div>
        ";
    } elseif (!preg_match('/^[a-fA-F0-9]{24}$/', $serviceId)) {
        $message = "
            <div class='alert alert-danger'>
                Invalid service selected.
            </div>
        ";
    } elseif ($weightKg === false || $weightKg <= 0) {
        $message = "
            <div class='alert alert-danger'>
                Please enter a valid estimated laundry weight.
            </div>
        ";
    } elseif (mb_strlen($notes) > 1000) {
        $message = "
            <div class='alert alert-danger'>
                Notes cannot exceed 1,000 characters.
            </div>
        ";
    } else {
        try {
            $reservationHoliday = $db->holidays->findOne([
                'holiday_date' => $date,
                'is_closed' => [
                    '$ne' => false
                ]
            ]);

            $pickupHoliday = $db->holidays->findOne([
                'holiday_date' => $pickupDate,
                'is_closed' => [
                    '$ne' => false
                ]
            ]);

            if ($reservationHoliday !== null) {
                $message = "
                    <div class='alert alert-danger'>
                        Sorry, " .
                        h($date) .
                        " is a holiday (" .
                        h(holidayName($reservationHoliday)) .
                        "). The shop is closed on this date.
                    </div>
                ";
            } elseif ($pickupHoliday !== null) {
                $message = "
                    <div class='alert alert-danger'>
                        Sorry, " .
                        h($pickupDate) .
                        " is a holiday (" .
                        h(holidayName($pickupHoliday)) .
                        "). Please choose another pick-up date.
                    </div>
                ";
            } else {
                $service = $db->services->findOne([
                    '_id' => new ObjectId($serviceId),
                    'is_active' => [
                        '$ne' => false
                    ]
                ]);

                if ($service === null) {
                    $message = "
                        <div class='alert alert-danger'>
                            The selected service was not found or is inactive.
                        </div>
                    ";
                } else {
                    $minimumKg = (float) (
                        $service['min_kg'] ?? 0
                    );

                    $maximumKg = (float) (
                        $service['max_kg'] ?? 0
                    );

                    if ($weightKg < $minimumKg) {
                        $message = "
                            <div class='alert alert-danger'>
                                Minimum estimated weight for this service is " .
                                h($minimumKg) .
                                " kg.
                            </div>
                        ";
                    } elseif (
                        $maximumKg > 0 &&
                        $weightKg > $maximumKg
                    ) {
                        $message = "
                            <div class='alert alert-danger'>
                                Maximum estimated weight for this service is " .
                                h($maximumKg) .
                                " kg.
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
                            'service_id' => new ObjectId($serviceId),
                            'weight_kg' => (float) $weightKg,
                            'notes' => $notes,
                            'status' => 'Pending',
                            'notified' => false,
                            'service_change_result' => null,
                            'updated_by' => null,
                            'admin_notes' => null,
                            'cancellation_reason' => null,
                            'created_at' => new UTCDateTime(),
                            'updated_at' => new UTCDateTime()
                        ]);

                        $message = "
                            <div class='alert alert-success'>
                                Reservation submitted! Waiting for admin
                                confirmation.
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
                    The reservation could not be saved. Please try again.
                </div>
            ";
        }
    }
}

$services = [];

try {
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
    )->toArray();
} catch (Throwable $e) {
    error_log(
        'Services loading error: ' .
        $e->getMessage()
    );

    $message .= "
        <div class='alert alert-danger'>
            Services could not be loaded. Please try again later.
        </div>
    ";
}

$holidayDates = [];
$holidayNames = [];
$holidayListHtml = '';

try {
    $holidays = $db->holidays->find(
        [
            'holiday_date' => [
                '$gte' => $today
            ],
            'is_closed' => [
                '$ne' => false
            ]
        ],
        [
            'sort' => [
                'holiday_date' => 1
            ]
        ]
    );

    foreach ($holidays as $holiday) {
        $holidayDate = (string) (
            $holiday['holiday_date'] ?? ''
        );

        $holidayNameValue = (string) (
            $holiday['holiday_name'] ?? 'Holiday'
        );

        if (
            $holidayDate === '' ||
            !isValidDateValue($holidayDate)
        ) {
            continue;
        }

        $holidayDates[] = $holidayDate;
        $holidayNames[$holidayDate] = $holidayNameValue;

        $holidayListHtml .= '<li>' .
            h(date('F j, Y', strtotime($holidayDate))) .
            ' — ' .
            h($holidayNameValue) .
            '</li>';
    }
} catch (Throwable $e) {
    error_log(
        'Holiday loading error: ' .
        $e->getMessage()
    );

    $message .= "
        <div class='alert alert-warning'>
            Holiday dates could not be loaded. Please verify your selected
            dates with the laundry shop.
        </div>
    ";
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

$serviceCount = count($services);
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
            <div class="col-md-7 col-lg-6">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        onclick="goBack()"
                    >
                        ← Back
                    </button>

                    <a
                        href="../index.php"
                        class="btn btn-outline-primary btn-sm"
                    >
                        Home
                    </a>
                </div>

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
                        <?= h($_SESSION['full_name'] ?? '') ?>
                    </strong>
                </p>

                <?= $message ?>

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
                                    <?php if ($serviceCount > 0): ?>
                                        <option value="">
                                            Select a laundry service
                                        </option>
                                    <?php endif; ?>

                                    <?php foreach ($services as $row): ?>
                                        <?php
                                        $serviceObjectId = (string) (
                                            $row['_id'] ?? ''
                                        );

                                        $serviceName = (string) (
                                            $row['service_name'] ??
                                            'Unnamed service'
                                        );

                                        $basePrice = (float) (
                                            $row['base_price'] ?? 0
                                        );

                                        $extraPerKg = (float) (
                                            $row['extra_per_kg'] ?? 0
                                        );

                                        $minKg = (float) (
                                            $row['min_kg'] ?? 0
                                        );

                                        $maxKg = (float) (
                                            $row['max_kg'] ?? 0
                                        );
                                        ?>

                                        <option
                                            value="<?= h($serviceObjectId) ?>"
                                            data-base="<?= h($basePrice) ?>"
                                            data-extra="<?= h($extraPerKg) ?>"
                                            data-min="<?= h($minKg) ?>"
                                            data-max="<?= h($maxKg) ?>"
                                        >
                                            <?= h($serviceName) ?>
                                            — ₱<?= number_format(
                                                $basePrice,
                                                2
                                            ) ?>
                                            (<?= h($minKg) ?>–<?= h($maxKg) ?> kg),
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
                                    Estimated Weight (kg)
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
                                    class="d-block text-muted mt-1"
                                ></small>

                                <small
                                    class="d-block text-muted mt-1"
                                >
                                    Enter your estimated laundry weight. The
                                    actual weight and final total may be
                                    confirmed by laundry staff.
                                </small>
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
                                    Drop-Off Date
                                </label>

                                <input
                                    type="date"
                                    name="reservation_date"
                                    id="dateInput"
                                    class="form-control"
                                    min="<?= h($today) ?>"
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
                                    Drop-Off Time
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
                                    Pick-Up Date
                                </label>

                                <input
                                    type="date"
                                    name="pickup_date"
                                    id="pickupDateInput"
                                    class="form-control"
                                    min="<?= h($today) ?>"
                                    required
                                >

                                <small
                                    id="pickupHolidayWarning"
                                    class="text-danger d-none"
                                ></small>
                            </div>

                            <div class="mb-3">
                                <label
                                    for="pickupTimeInput"
                                    class="form-label"
                                >
                                    Pick-Up Time (optional)
                                </label>

                                <input
                                    type="time"
                                    name="pickup_time"
                                    id="pickupTimeInput"
                                    class="form-control"
                                >
                            </div>

                            <div class="mb-4">
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
    function goBack() {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }

        window.location.href = '../index.php';
    }

    const holidayDates = <?= $holidayDatesJson ?>;
    const holidayNames = <?= $holidayNamesJson ?>;

    const dateInput = document.getElementById('dateInput');
    const pickupDateInput = document.getElementById(
        'pickupDateInput'
    );

    const holidayWarning = document.getElementById(
        'holidayWarning'
    );

    const pickupHolidayWarning = document.getElementById(
        'pickupHolidayWarning'
    );

    const submitBtn = document.getElementById('submitBtn');
    const serviceSelect = document.getElementById('serviceSelect');
    const weightInput = document.getElementById('weightInput');
    const weightHint = document.getElementById('weightHint');
    const priceEstimate = document.getElementById('priceEstimate');

    function isHoliday(dateValue) {
        return holidayDates.includes(dateValue);
    }

    function updateSubmitButton() {
        const hasService =
            serviceSelect.value !== '';

        const reservationDateIsHoliday =
            dateInput.value !== '' &&
            isHoliday(dateInput.value);

        const pickupDateIsHoliday =
            pickupDateInput.value !== '' &&
            isHoliday(pickupDateInput.value);

        submitBtn.disabled =
            !hasService ||
            reservationDateIsHoliday ||
            pickupDateIsHoliday;
    }

    function checkReservationHoliday() {
        const selectedDate = dateInput.value;

        if (selectedDate !== '' && isHoliday(selectedDate)) {
            const name =
                holidayNames[selectedDate] || 'Holiday';

            holidayWarning.textContent =
                '⚠️ The shop is closed on this date (' +
                name +
                '). Please choose another date.';

            holidayWarning.classList.remove('d-none');
        } else {
            holidayWarning.textContent = '';
            holidayWarning.classList.add('d-none');
        }

        updateSubmitButton();
    }

    function checkPickupHoliday() {
        const selectedDate = pickupDateInput.value;

        if (selectedDate !== '' && isHoliday(selectedDate)) {
            const name =
                holidayNames[selectedDate] || 'Holiday';

            pickupHolidayWarning.textContent =
                '⚠️ The shop is closed on this date (' +
                name +
                '). Please choose another pick-up date.';

            pickupHolidayWarning.classList.remove('d-none');
        } else {
            pickupHolidayWarning.textContent = '';
            pickupHolidayWarning.classList.add('d-none');
        }

        updateSubmitButton();
    }

    function syncPickupDate() {
        if (
            pickupDateInput.value !== '' &&
            dateInput.value !== '' &&
            pickupDateInput.value < dateInput.value
        ) {
            pickupDateInput.value = dateInput.value;
        }

        pickupDateInput.min = dateInput.value || '<?= h($today) ?>';

        checkPickupHoliday();
    }

    function updateEstimate() {
        if (
            serviceSelect.value === '' ||
            serviceSelect.selectedIndex < 0
        ) {
            weightHint.textContent = '';
            priceEstimate.innerHTML =
                'Estimated Total: <strong>₱0.00</strong>';
            updateSubmitButton();
            return;
        }

        const option =
            serviceSelect.options[
                serviceSelect.selectedIndex
            ];

        const base = parseFloat(
            option.dataset.base || '0'
        );

        const extra = parseFloat(
            option.dataset.extra || '0'
        );

        const min = parseFloat(
            option.dataset.min || '0'
        );

        const max = parseFloat(
            option.dataset.max || '0'
        );

        const weight = parseFloat(
            weightInput.value || '0'
        );

        weightInput.min = min > 0 ? min : 0.1;

        if (max > 0) {
            weightInput.max = max;
        } else {
            weightInput.removeAttribute('max');
        }

        let hintText =
            'Minimum ' +
            min +
            ' kg. Flat ₱' +
            base.toFixed(2);

        if (max > 0) {
            hintText +=
                ' covers up to ' +
                max +
                ' kg, then +₱' +
                extra.toFixed(2) +
                '/kg after.';
        }

        weightHint.textContent = hintText;

        if (
            !Number.isNaN(weight) &&
            weight >= min &&
            (max <= 0 || weight <= max)
        ) {
            let total = base;

            if (max > 0 && weight > max) {
                total += (weight - max) * extra;
            }

            priceEstimate.innerHTML =
                'Estimated Total: <strong>₱' +
                total.toFixed(2) +
                '</strong>';
        } else {
            priceEstimate.innerHTML =
                'Estimated Total: <strong>₱0.00</strong>';
        }

        updateSubmitButton();
    }

    dateInput.addEventListener('change', function () {
        checkReservationHoliday();
        syncPickupDate();
    });

    pickupDateInput.addEventListener('change', function () {
        syncPickupDate();
        checkPickupHoliday();
    });

    serviceSelect.addEventListener('change', updateEstimate);
    weightInput.addEventListener('input', updateEstimate);

    updateEstimate();
    checkReservationHoliday();
    checkPickupHoliday();
    </script>
</body>
</html>