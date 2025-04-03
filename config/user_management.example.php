<?php
// config/user_management.example.php
//
// Copy this file to config/user_management.php and update the values accordingly.
// This file contains example settings for your project.

return [
    // Number of days of inactivity after which a user is considered inactive
    'inactive_days_threshold' => 90,
    
    // Identity types to check for inactivity (IAM, QUICKSIGHT)
    'identity_types_to_check' => ['QUICKSIGHT'],
    
    // User roles to check for inactivity (ADMIN, AUTHOR, READER, PRO_USER, ADMIN_PRO_USER)
    'user_roles_to_check' => ['READER'],
    
    // CloudTrail event names to consider as user activity
    'activity_event_names' => ['GetDashboard', 'GetDashboardEmbedUrl'],
    
    // Whether to run in dry-run mode by default (no actual deletions)
    'default_dry_run' => true,
    
    // Whether to include IAM users in the scan (alternative to using identity_types_to_check)
    'include_iam_users' => false,
    
    // List of usernames that should never be deleted, regardless of activity
    'protected_users' => [
        'admin',
        'quicksight-admin',
        // Add any other users you want to protect
    ],
];