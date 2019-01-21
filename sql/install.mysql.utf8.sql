INSERT INTO `#__user_profiles` (`user_id`, `profile_key`, `profile_value`, `ordering`)
SELECT `id`, 'firstlastnames.firstname', SUBSTRING_INDEX(`name`, ' ', 1), 1 FROM `#__users`;
INSERT INTO `#__user_profiles` (`user_id`, `profile_key`, `profile_value`, `ordering`)
SELECT `id`, 'firstlastnames.lastname', SUBSTRING_INDEX(`name`, ' ', -1), 2 FROM `#__users`;