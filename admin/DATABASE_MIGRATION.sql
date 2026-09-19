-- LaundryQ Database Migration for No-Show Feature
-- This script ensures the reservations table supports all new statuses

-- First, check the current structure
-- Run this query to see the current status column definition:
-- DESCRIBE reservations;
-- or
-- SHOW CREATE TABLE reservations;

-- If your status column is ENUM type, you may need to update it to include all new statuses:
-- Option 1: If status is ENUM, update it to include new values
ALTER TABLE reservations MODIFY COLUMN status ENUM(
    'Pending',
    'Accepted', 
    'Arrived',
    'In Progress',
    'Completed',
    'No-Show',
    'Declined',
    'Cancelled'
) DEFAULT 'Pending';

-- Option 2: If you prefer VARCHAR instead of ENUM for more flexibility
-- ALTER TABLE reservations MODIFY COLUMN status VARCHAR(50) DEFAULT 'Pending';

-- Add any necessary indexes for better query performance
-- Check if index already exists before adding
ALTER TABLE reservations ADD INDEX idx_status (status);
ALTER TABLE reservations ADD INDEX idx_reservation_date (reservation_date);
ALTER TABLE reservations ADD INDEX idx_user_id (user_id);

-- Verify the changes
-- DESCRIBE reservations;

-- Optional: View all existing reservations and their current statuses
-- SELECT DISTINCT status FROM reservations;

-- Optional: Update any reservations with old status values if needed
-- (Replace 'OldStatus' with whatever status you want to migrate from)
-- UPDATE reservations SET status = 'Pending' WHERE status = 'OldStatus';
