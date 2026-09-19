# Laundry Reservation System - No-Show Feature Guide

## Overview
The No-Show feature allows administrators to properly handle customers who don't arrive for accepted reservations. This feature maintains complete reservation history while enabling the admin to manage missed appointments.

## Reservation Status Flow

### Complete Status Lifecycle:
```
Pending → Accepted → Arrived → In Progress → Completed
         ↓
       Declined (if admin rejects)

Accepted → No-Show (if customer doesn't arrive after scheduled time)
        ↓
    Reschedule → Pending (new appointment)
        ↓
    Cancelled
```

## Status Descriptions

| Status | Description |
|--------|-------------|
| **Pending** | Customer has made a reservation, waiting for admin approval |
| **Accepted** | Admin has approved the reservation |
| **Arrived** | Customer has arrived at the shop |
| **In Progress** | Laundry service is currently being processed |
| **Completed** | Laundry service is finished and ready for pickup |
| **No-Show** | Customer did not arrive after the scheduled date/time has passed |
| **Declined** | Admin rejected the reservation |
| **Cancelled** | Reservation was cancelled |

## How to Use the No-Show Feature

### Step 1: Accept a Reservation
1. Go to Admin Reservations Management page
2. Find a pending reservation
3. Click the **"Accept"** button
4. The reservation status changes to "Accepted"

### Step 2: Monitor Accepted Reservations
- Once a reservation is accepted, the admin can track when the customer arrives
- For accepted reservations where the scheduled date/time has **already passed**, the **"Mark as No-Show"** button becomes visible

### Step 3: Customer Arrives On Time
If the customer arrives before the scheduled time passes:
1. Click **"Mark as Arrived"** button
2. The status changes to "Arrived"
3. When ready to start processing, click **"Start Processing"**
4. The status changes to "In Progress"
5. When finished, click **"Complete"**
6. The status changes to "Completed"

### Step 4: Customer Does NOT Arrive (No-Show)
If the customer doesn't arrive after the scheduled date/time:
1. The **"Mark as No-Show"** button appears for that reservation
2. Click **"Mark as No-Show"**
3. Confirm the action in the popup dialog
4. The reservation status changes to "No-Show"
5. The reservation row is highlighted in red to indicate the issue

### Step 5: After Marking as No-Show
Once marked as No-Show, you have two options:

#### Option A: Reschedule
1. Click the **"Reschedule"** button in the Actions column
2. A modal dialog opens asking for:
   - New Date (must be in the future)
   - New Time (must be in the future)
3. Enter the new appointment details
4. Click **"Save Reschedule"**
5. The reservation status automatically reverts to "Pending" for re-approval
6. The customer can be contacted about the new appointment

#### Option B: Cancel
1. Click the **"Cancel"** button in the Actions column
2. Confirm the cancellation in the popup dialog
3. The reservation status changes to "Cancelled"
4. The reservation is permanently closed

## Key Features

### Automatic Overdue Detection
- The system automatically detects when an accepted reservation's scheduled date/time has passed
- The "Mark as No-Show" button only appears for overdue accepted reservations
- Admin must manually confirm the No-Show status (no automatic status change)

### Reservation History
- All reservations are kept in the database, even if marked as No-Show or Cancelled
- You can filter by status to see all No-Shows or cancelled reservations
- Complete audit trail is maintained for business records

### Sorting and Filtering
- Click on table headers (ID, Customer, Date, Status) to sort by that column
- Sort in ascending or descending order for better management

### Responsive Design
- All buttons work on desktop and mobile devices
- On smaller screens, buttons stack vertically for better usability

## Database Considerations

### Supported Statuses
The system supports the following status values in the database:
- `Pending`
- `Accepted`
- `Arrived`
- `In Progress`
- `Completed`
- `No-Show`
- `Declined`
- `Cancelled`

If your database has custom statuses, they will display but may not have specific action buttons.

### Adding New Reservations
New reservations start with status "Pending" and follow the standard workflow.

## Best Practices

1. **Check Overdue Reservations Regularly**: Review the Admin Reservations page regularly to identify no-shows
2. **Document No-Shows**: Keeping a record of no-shows helps identify problematic customers
3. **Prompt Rescheduling**: If rescheduling, contact the customer immediately with the new appointment
4. **Cancel Inactive**: If a customer is frequently no-show, consider cancelling the reservation
5. **Use Sorting**: Sort by Date or Status to quickly find reservations that need attention

## Troubleshooting

### "Mark as No-Show" button not appearing
- The button only appears if the reservation is "Accepted" AND the scheduled time has already passed
- Check that your server time is correct
- Verify the reservation date/time in the database

### Can't reschedule to a past date
- The system prevents scheduling appointments in the past
- Make sure the new date/time is at least a few minutes in the future

### Need to undo a No-Show status?
- You can reschedule the reservation, which reverts it to "Pending"
- Or cancel it and create a new reservation if needed

## Support

For additional help or feature requests, contact the development team with:
- Reservation ID
- Current status
- Expected action
- Error messages (if any)
