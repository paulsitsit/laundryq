-- Run once after backing up laundryq_db.
ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL AFTER email,
    ADD COLUMN verification_token VARCHAR(64) NULL DEFAULT NULL AFTER email_verified_at,
    ADD COLUMN verification_token_expires_at DATETIME NULL DEFAULT NULL AFTER verification_token,
    ADD UNIQUE KEY uq_users_verification_token (verification_token);

-- Existing accounts must be verified again. The admin can manually set
-- email_verified_at = NOW() only after confirming the address with the owner.
UPDATE users SET email_verified_at = NULL;