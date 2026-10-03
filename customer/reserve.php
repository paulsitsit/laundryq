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

function holidayName(mixed $holiday): string
{
    $data = asArray($holiday);

    return (string) (
        $data['holiday_name'] ?? 'Holiday'
    );
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
            $reservationHoliday = asArray(
                $db->holidays->findOne([
                    'holiday_date' => $date,
                    'is_closed' => [
                        '$ne' => false
                    ]
                ])
            );

            $pickupHoliday = asArray(
                $db->holidays->findOne([
                    'holiday_date' => $pickupDate,
                    'is_closed' => [
                        '$ne' => false
                    ]
                ])
            );

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
                $service = asArray(
                    $db->services->findOne([
                        '_id' => new ObjectId($serviceId),
                        'is_active' => [
                            '$ne' => false
                        ]
                    ])
                );

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

                    if ($weightKg < $minimumKg) {
                        $message = "
                            <div class='alert alert-danger'>
                                Minimum estimated weight for this service is " .
                                h($minimumKg) .
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

    foreach ($holidays as $document) {
        $holiday = asArray($document);

        if ($holiday === null) {
            continue;
        }

        $holidayDate = trim((string) (
            $holiday['holiday_date'] ?? ''
        ));

        $holidayNameValue = trim((string) (
            $holiday['holiday_name'] ?? 'Holiday'
        ));

        $holidayType = trim((string) (
            $holiday['holiday_type'] ?? ''
        ));

        if (
            $holidayDate === '' ||
            !isValidDateValue($holidayDate)
        ) {
            continue;
        }

        $holidayDates[] = $holidayDate;

        $holidayNames[$holidayDate] =
            $holidayType !== ''
                ? $holidayNameValue .
                    ' (' .
                    $holidayType .
                    ')'
                : $holidayNameValue;

        $holidayListHtml .= '<li>' .
            h(date('F j, Y', strtotime($holidayDate))) .
            ' — ' .
            h($holidayNames[$holidayDate]) .
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
    array_values(array_unique($holidayDates)),
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

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"
    >

    <style>
        .flatpickr-day.holiday-date,
        .flatpickr-day.holiday-date:hover {
            background: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
            cursor: not-allowed;
            text-decoration: line-through;
        }

        .flatpickr-day.holiday-date.flatpickr-disabled {
            color: #842029;
            opacity: .85;
        }

        .holiday-info {
            display: none;
            margin-top: .5rem;
            padding: .75rem;
            border: 1px solid #f5c2c7;
            border-radius: .375rem;
            background: #f8d7da;
            color: #842029;
            font-size: .875rem;
        }

        .holiday-info.show {
            display: block;
        }

        .flatpickr-day[title] {
            position: relative;
        }
    </style>
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
                                        $service = asArray($row) ?? [];

                                        $serviceObjectId = (string) (
                                            $service['_id'] ?? ''
                                        );

                                        $serviceName = (string) (
                                            $service['service_name'] ??
                                            'Unnamed service'
                                        );

                                        $basePrice = (float) (
                                            $service['base_price'] ?? 0
                                        );

                                        $extraPerKg = (float) (
                                            $service['extra_per_kg'] ?? 0
                                        );

                                        $minKg = (float) (
                                            $service['min_kg'] ?? 0
                                        );

                                        $maxKg = (float) (
                                            $service['max_kg'] ?? 0
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
                                    type="text"
                                    name="reservation_date"
                                    id="dateInput"
                                    class="form-control"
                                    placeholder="Select a drop-off date"
                                    autocomplete="off"
                                    required
                                >

                                <div
                                    id="holidayWarning"
                                    class="holiday-info"
                                ></div>
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
                                    type="text"
                                    name="pickup_date"
                                    id="pickupDateInput"
                                    class="form-control"
                                    placeholder="Select a pick-up date"
                                    autocomplete="off"
                                    required
                                >

                                <div
                                    id="pickupHolidayWarning"
                                    class="holiday-info"
                                ></div>
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


                <p class="text-center mt-3">
                    <a href="my_reservations.php">
                        📋 View My Reservations
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script
        src="https://cdn.jsdelivr.net/npm/flatpickr"
    ></script>

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

    const serviceSelect = document.getElementById(
        'serviceSelect'
    );

    const weightInput = document.getElementById(
        'weightInput'
    );

    const weightHint = document.getElementById(
        'weightHint'
    );

    const priceEstimate = document.getElementById(
        'priceEstimate'
    );

    function isHoliday(dateValue) {
        return holidayDates.includes(dateValue);
    }

    function showHolidayWarning(element, dateValue) {
        if (dateValue !== '' && isHoliday(dateValue)) {
            const name =
                holidayNames[dateValue] || 'Holiday';

            element.textContent =
                '⚠️ ' +
                name +
                ' — the shop is closed on this date. ' +
                'Please choose another date.';

            element.classList.add('show');
            return;
        }

        element.textContent = '';
        element.classList.remove('show');
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

    function updateEstimate() {
        if (
            !serviceSelect ||
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

        /*
         * max_kg is the limit covered by the base price.
         * It is not a maximum allowed reservation weight.
         */
        weightInput.removeAttribute('max');

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
            Number.isNaN(weight) ||
            weight <= 0 ||
            weight < min
        ) {
            priceEstimate.innerHTML =
                'Estimated Total: <strong>₱0.00</strong>';

            updateSubmitButton();
            return;
        }

        let total = base;

        if (max > 0 && weight > max) {
            total += (weight - max) * extra;
        }

        priceEstimate.innerHTML =
            'Estimated Total: <strong>₱' +
            total.toFixed(2) +
            '</strong>';

        updateSubmitButton();
    }

    function toYmd(date) {
        const year = date.getFullYear();

        const month = String(
            date.getMonth() + 1
        ).padStart(2, '0');

        const day = String(
            date.getDate()
        ).padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    const calendarSettings = {
        dateFormat: 'Y-m-d',
        minDate: '<?= h($today) ?>',
        disable: holidayDates,

        onDayCreate: function (
            _dateObject,
            _dateString,
            _instance,
            dayElement
        ) {
            const calendarDate = toYmd(
                dayElement.dateObj
            );

            if (isHoliday(calendarDate)) {
                const name =
                    holidayNames[calendarDate] ||
                    'Holiday';

                dayElement.classList.add(
                    'holiday-date'
                );

                dayElement.title =
                    name + ' — Shop closed';

                dayElement.setAttribute(
                    'aria-label',
                    name + '. Shop closed.'
                );
            }
        }
    };

    const pickupPicker = flatpickr(
        pickupDateInput,
        {
            ...calendarSettings,

            onChange: function (
                _selectedDates,
                dateString
            ) {
                showHolidayWarning(
                    pickupHolidayWarning,
                    dateString
                );

                updateSubmitButton();
            }
        }
    );

    const dropoffPicker = flatpickr(
        dateInput,
        {
            ...calendarSettings,

            onChange: function (
                _selectedDates,
                dateString
            ) {
                showHolidayWarning(
                    holidayWarning,
                    dateString
                );

                if (dateString !== '') {
                    pickupPicker.set(
                        'minDate',
                        dateString
                    );

                    if (
                        pickupDateInput.value !== '' &&
                        pickupDateInput.value < dateString
                    ) {
                        pickupPicker.setDate(
                            dateString,
                            true
                        );
                    }
                }

                showHolidayWarning(
                    pickupHolidayWarning,
                    pickupDateInput.value
                );

                updateSubmitButton();
            }
        }
    );

    serviceSelect.addEventListener(
        'change',
        updateEstimate
    );

    weightInput.addEventListener(
        'input',
        updateEstimate
    );

    updateEstimate();
    updateSubmitButton();
    </script>
</body>
</html>