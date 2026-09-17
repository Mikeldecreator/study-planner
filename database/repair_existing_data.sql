-- Repair legacy seed/demo data in an existing Study Planner database.
--
-- SAFETY: this only moves data from the bundled demo account
-- (michael@example.com) to the selected target account, and only if the
-- target account is currently empty of planner data.
--
-- Replace the value below with the email of the account you actually use.
SET @target_email = 'YOUR-EMAIL-HERE';

SET @demo_user_id = (SELECT id FROM users WHERE email = 'michael@example.com' LIMIT 1);
SET @target_user_id = (SELECT id FROM users WHERE email = @target_email LIMIT 1);

SELECT @demo_user_id AS demo_user_id, @target_user_id AS target_user_id;

-- The PHP application now performs this repair automatically for an empty
-- account. This SQL file is kept as a manual fallback/diagnostic tool.
-- Do not run the UPDATE statements below blindly because course/task IDs must
-- be remapped. Use the repaired application login flow instead.
