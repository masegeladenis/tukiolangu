-- Migration: Add permissions column and expand role options
-- Run this in phpMyAdmin or MySQL CLI

-- 1. Extend the role ENUM to support more role labels
ALTER TABLE users
    MODIFY COLUMN role ENUM('super_admin','event_admin','scanner','viewer','custom','admin') DEFAULT 'scanner';

-- 2. Add a permissions JSON column for fine-grained page/feature access
ALTER TABLE users
    ADD COLUMN permissions JSON NULL AFTER role;

-- 3. Back-fill permissions for existing users
--    Old 'admin' → gets all permissions
UPDATE users
SET permissions = JSON_ARRAY(
    'dashboard','events_view','events_manage',
    'batches','participants_view','participants_manage',
    'participants_checkin','scanner','reports','sms','users_manage'
)
WHERE role = 'admin';

--    Old 'scanner' → scanner + view + checkin
UPDATE users
SET permissions = JSON_ARRAY(
    'dashboard','participants_view','participants_checkin','scanner'
)
WHERE role = 'scanner' AND permissions IS NULL;

-- 4. Rename legacy 'admin' role label to 'super_admin'
UPDATE users SET role = 'super_admin' WHERE role = 'admin';
