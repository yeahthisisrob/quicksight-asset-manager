<?php
// config/global.example.php
//
// Copy this file to config/global.php and update the values accordingly.
// This file contains example settings for your project.

return [
    'awsRegion'    => 'us-west-2', // Example AWS region
    'awsAccountId' => '<YOUR_AWS_ACCOUNT_ID>', // Replace with your 12-digit AWS account ID

    'paths' => [
        /*
         * Base export path.
         *
         * This path determines where exported assets will be written.
         *
         * Supports placeholders:
         *   {group}    - Replaced with the group name.
         *   {subgroup} - Replaced with the subgroup name (optional).
         *
         * Example without placeholders (default):
         *   '../exports/groups'
         *
         * Example using placeholders to match alternative structures:
         *   'config/{group}/quicksight-asset-exports'
         */
        'export_base_path'   => __DIR__ . '/../exports/groups',

        // Directory for group-specific configuration files.
        'group_config_path'  => __DIR__,

        // Path for additional report exports (if used).
        'report_export_path' => __DIR__ . '/../exports',
    ],

    'tagging' => [
        'default_key'        => 'customer', // Default tag used if none provided
        'groups_config_file' => __DIR__ . '/groups.php', // Path to the groups configuration file
    ],
    'user_management' => [
        'config_file' => __DIR__ . '/user_management.php', // Path to the user management configuration
    ]
];
