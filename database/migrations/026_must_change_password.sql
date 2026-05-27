-- Migration 026: Add must_change_password flag to users table
-- When 1, user is forced to change password on next login before accessing any page.
-- Defaults to 1 so all newly created accounts start with forced change ON.

ALTER TABLE `users`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 1
  AFTER `is_active`;

-- Existing accounts that are already in use should NOT be forced to change.
-- Set them all to 0 now; new accounts will use the admin toggle.
UPDATE `users` SET `must_change_password` = 0;
