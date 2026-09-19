-- LaundryQ Complete Reservation Workflow - Database Migration
-- This script updates the reservations table to support the complete workflow

-- ============================================================
-- IMPORTANT: Always backup your database before running migrations!
-- ============================================================

-- Step 1: Check current table structure
-- Run this query to see your current structure:
-- DESCRIBE reservations;

-- Step 2: Update the STATUS column to support all new statuses
-- Choose the appropriate option based on your current setup:

-- Option A: If your status column is ENUM (most common)
-- Replace the ENUM definition with this:
ALTER TABLE reservations MODIFY COLUMN status ENUM(
    'Pending',
    'Accepted',
    'Arrived',
    'Washing',
    'Drying',
    'Folding',
    'In Progress',
    'Completed',
    'No-Show',
    'Reschedule Requested',
    'Rescheduled',
    'Cancelled',
    'Rejected'
) DEFAULT 'Pending' COLLATE utf8mb4_unicode_ci;

-- Option B: If your status column is VARCHAR, it should already work
-- But you can verify with this query:
-- SELECT DISTINCT status FROM reservations;

-- Step 3: Add new columns for reschedule requests (if they don't exist)
-- Check if columns exist first:
-- SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_NAME='reservations' AND COLUMN_NAME='requested_date';

-- Add the columns if they don't exist:
ALTER TABLE reservations ADD COLUMN requested_date DATE NULL DEFAULT NULL AFTER reservation_time;
ALTER TABLE reservations ADD COLUMN requested_time TIME NULL DEFAULT NULL AFTER requested_date;

-- Step 4: (Optional) Add columns to preserve original reservation dates
-- Useful for audit trail:
ALTER TABLE reservations ADD COLUMN original_date DATE NULL DEFAULT NULL AFTER requested_time;
ALTER TABLE reservations ADD COLUMN original_time TIME NULL DEFAULT NULL AFTER original_date;

-- Step 5: Add useful indexes for better performance
ALTER TABLE reservations ADD INDEX idx_status (status);
ALTER TABLE reservations ADD INDEX idx_reservation_date (reservation_date);
ALTER TABLE reservations ADD INDEX idx_user_id (user_id);
ALTER TABLE reservations ADD INDEX idx_created_at (created_at);

-- Step 6: Verify the changes
-- Run this to see the updated structure:
-- DESCRIBE reservations;

-- Step 7: Check for any NULL status values and set them to 'Pending' if needed:
UPDATE reservations SET status = 'Pending' WHERE status IS NULL OR status = '';

-- Step 8: Optional - View all existing statuses to verify migration
SELECT DISTINCT status, COUNT(*) as count FROM reservations GROUP BY status ORDER BY status;

-- ============================================================
-- BACKUP: Here's SQL to save the old statuses before migration
-- Create a backup table:
-- CREATE TABLE reservations_backup AS SELECT * FROM reservations;
-- ============================================================

-- ============================================================
-- COLUMN DESCRIPTIONS:
-- ============================================================
-- reservation_id: Unique identifier for the reservation
-- user_id: Foreign key to users table
-- reservation_date: Original scheduled date
-- reservation_time: Original scheduled time
-- requested_date: New date requested by customer (for reschedules)
-- requested_time: New time requested by customer (for reschedules)
-- original_date: Backup of original date (optional, for audit trail)
-- original_time: Backup of original time (optional, for audit trail)
-- service_type: Type of service (e.g., "Drop-off", "Pickup")
-- service_id: Foreign key to services table
-- weight_kg: Weight of laundry in kilograms
-- notes: Additional notes from customer
-- status: Current status in the workflow
-- created_at: When the reservation was created
-- ============================================================

-- ============================================================
-- STATUS WORKFLOW GUIDE:
-- ============================================================
-- PENDING -> When customer first books reservation
-- ACCEPTED -> When admin approves the reservation
-- ARRIVED -> When customer physically arrives at shop
-- IN PROGRESS -> When laundry processing begins
-- COMPLETED -> When laundry service is finished
-- 
-- NO-SHOW -> When customer doesn't arrive after scheduled time
-- RESCHEDULE REQUESTED -> When customer requests a new date/time
-- RESCHEDULED -> When admin accepts the reschedule request
-- CANCELLED -> When reservation is permanently closed
-- REJECTED -> When admin rejects a pending reservation
-- ============================================================

-- ============================================================
-- EXAMPLE: Creating a backup before migration
-- ============================================================
-- Uncomment and run this if you want to create a backup:
/*
CREATE TABLE IF NOT EXISTS reservations_backup_before_workflow (
    SELECT * FROM reservations
);
*/

-- ============================================================
-- VERIFICATION QUERIES:
-- ============================================================
-- Check table structure:
-- DESCRIBE reservations;

-- Count reservations by status:
-- SELECT status, COUNT(*) FROM reservations GROUP BY status;

-- Find reservations that need attention:
-- SELECT reservation_id, user_id, reservation_date, status 
-- FROM reservations 
-- WHERE status IN ('Pending', 'Reschedule Requested', 'No-Show');

-- Find overdue accepted reservations (potential no-shows):
-- SELECT reservation_id, reservation_date, reservation_time
-- FROM reservations 
-- WHERE status = 'Accepted' AND CONCAT(reservation_date, ' ', reservation_time) < NOW();

-- ============================================================
-- TROUBLESHOOTING:
-- ============================================================
-- If you get error "Duplicate key name", it means the index already exists.
-- This is safe - the migration will skip it.

-- If you get error "Syntax error", check for missing semicolons at end of statements.

-- If requested_date/requested_time already exist, you can skip Step 3.

-- ============================================================
-- ROLLBACK (if something goes wrong):
-- ============================================================
-- Restore from backup:
-- DROP TABLE IF EXISTS reservations;
-- RENAME TABLE reservations_backup TO reservations;
-- OR restore from your database backup file.

-- ============================================================
-- AFTER MIGRATION:
-- ============================================================
-- 1. Test all reservation operations in development first
-- 2. Verify that all status values are valid
-- 3. Test the admin reservation workflow UI
-- 4. Test the customer reservation view
-- 5. Test the reschedule request functionality
-- 6. Monitor for any errors in application logs

-- ============================================================
-- ADDITIONAL ENHANCEMENTS (Optional):
-- ============================================================
-- If you want to track who made changes and when:
ALTER TABLE reservations ADD COLUMN updated_by INT NULL DEFAULT NULL AFTER created_at;
ALTER TABLE reservations ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_by;

-- If you want to add admin notes for no-shows:
ALTER TABLE reservations ADD COLUMN admin_notes TEXT NULL DEFAULT NULL AFTER notes;

-- If you want to track cancellation reasons:
ALTER TABLE reservations ADD COLUMN cancellation_reason VARCHAR(255) NULL DEFAULT NULL AFTER admin_notes;
