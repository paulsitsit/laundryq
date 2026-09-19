# No-Show Feature - Quick Start Guide for Admins

## What's New?

Your reservation system now supports a complete workflow including a "No-Show" status for customers who don't arrive for their appointments.

## Key Status Values

- **Pending** - Waiting for your approval
- **Accepted** - You approved, waiting for customer
- **Arrived** - Customer showed up
- **In Progress** - Laundry being processed
- **Completed** - Laundry done
- **No-Show** - Customer didn't arrive (NEW!)
- **Declined** - You rejected the reservation
- **Cancelled** - Reservation closed

## Quick Workflow

### 1. Customer Makes Reservation
→ Status: **Pending**

### 2. You Review & Accept
Click **"Accept"** button
→ Status: **Accepted**

### 3. On Scheduled Date - Customer Arrives
Click **"Mark as Arrived"** button
→ Status: **Arrived**

### 4. Start Processing
Click **"Start Processing"** button
→ Status: **In Progress**

### 5. Service Complete
Click **"Complete"** button
→ Status: **Completed** ✓

---

## What If Customer Doesn't Arrive?

### Before Scheduled Time
- Reservation is **Accepted**
- No special buttons yet

### After Scheduled Time (No Customer)
- Reservation is still **Accepted**
- **"Mark as No-Show"** button appears automatically (only after time passes)
- Click **"Mark as No-Show"**
→ Status: **No-Show** ⚠️

### After Marked as No-Show
You have two options:

#### Option A: Reschedule
1. Click **"Reschedule"** button
2. Enter new date and time (must be in future)
3. Click **"Save Reschedule"**
4. Reservation goes back to **Pending** for your review
5. Contact customer about new time

#### Option B: Cancel
1. Click **"Cancel"** button
2. Confirm cancellation
3. Reservation status: **Cancelled**

---

## How to Know When to Mark as No-Show

✓ Reservation status is **"Accepted"**
✓ Current time is **AFTER** the scheduled date/time
✓ Customer has **NOT** arrived
✓ **"Mark as No-Show"** button **IS** visible

If the button isn't visible, either:
- The scheduled time hasn't passed yet, OR
- The reservation isn't in "Accepted" status

---

## Common Scenarios

### Scenario 1: Customer Arrives Late
1. Reservation scheduled for 2:00 PM
2. Customer arrives at 2:15 PM
3. You click "Mark as Arrived" (even though late)
4. Continue normal workflow (Start Processing → Complete)

### Scenario 2: Customer Never Shows
1. Reservation scheduled for 2:00 PM
2. Now 3:00 PM, customer hasn't arrived
3. "Mark as No-Show" button appears
4. Click it to confirm no-show
5. Click "Reschedule" and offer new time
6. OR click "Cancel" if customer unreliable

### Scenario 3: Customer Calls to Reschedule
1. Customer calls saying they can't make 2:00 PM
2. Check if reservation is "Accepted" (not yet no-show)
3. Click "Reschedule" button
4. Enter new date/time
5. Save reschedule
6. Call customer back to confirm

### Scenario 4: Need to Cancel Reservation
1. At any stage, can click "Cancel"
2. Cancels the reservation
3. Useful if customer says they don't want it

---

## Important Rules

⚠️ **Don't Click "Mark as No-Show" Too Early**
- Wait until AFTER the scheduled time has passed
- System helps by only showing button after time passes
- Button appears automatically when time passes

✓ **Always Confirm Actions**
- A popup asks "Are you sure?"
- This prevents accidental changes
- Click OK to confirm

✓ **Rescheduled Reservations Need New Approval**
- When you reschedule, status goes back to "Pending"
- You'll need to "Accept" it again
- This gives customer a chance to confirm

✓ **Keep Complete History**
- No-show reservations stay in system
- Helps track problem customers
- Useful for business analytics

---

## Tips & Tricks

### Tip 1: Use Sorting
Click column headers to sort by:
- ID (reservation number)
- Customer name
- Date
- Time  
- Status

### Tip 2: Check Table Row Colors
- **Yellow rows** = Pending reservations (need your action)
- **Red rows** = No-show or Declined (attention needed)
- **White rows** = Normal reservations

### Tip 3: Find Overdue Reservations
1. Sort by "Date" (click Date header)
2. Look for "Accepted" reservations with past dates
3. "Mark as No-Show" button will be visible

### Tip 4: Quick Actions
- All actions show confirmation dialog
- Easy to undo mistakes (just refresh, no action taken)
- Forms prevent you from entering invalid dates

---

## Troubleshooting

### Q: Why doesn't "Mark as No-Show" button show?
**A:** The button only appears if:
1. Reservation status is "Accepted"
2. Current time is AFTER scheduled time
3. Current date/time on your server is correct

Check admin dashboard to verify date/time.

### Q: Can I mark customer as no-show BEFORE scheduled time?
**A:** No, the button only appears after scheduled time passes. This is intentional - gives customer a grace period.

### Q: What if I accidentally marked as no-show?
**A:** You can reschedule it back to "Pending" status. This effectively "undoes" the no-show.

### Q: Can customers see the no-show status?
**A:** Yes! When they log in to "My Reservations", they see the "No-Show" badge in red.

### Q: How do I prevent no-shows in future?
**A:** Future enhancements will include:
- Customer notifications/reminders
- Track repeat no-shows
- Apply fees or restrictions to problem customers

---

## Getting Help

📖 **See Full Guide:** Read `NO_SHOW_FEATURE_GUIDE.md` for complete details

🔧 **Database Setup:** See `DATABASE_MIGRATION.sql` if you had to update database

📋 **Technical Details:** Read `IMPLEMENTATION_SUMMARY.md` for developer info

---

**Remember:** The No-Show feature helps you manage your business better by keeping accurate records of customer attendance!

