<?php

namespace QSAssetManager\Manager\User;

use Aws\CloudTrail\CloudTrailClient;
use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\CloudTrailHelper;
use DateTimeImmutable;

/**
 * Manager for QuickSight User operations
 */
class UserManager
{
    /**
     * @var QuickSightClient
     */
    protected $quickSight;

    /**
     * @var CloudTrailClient
     */
    protected $cloudTrail;

    /**
     * @var string
     */
    protected $awsAccountId;

    /**
     * @var string
     */
    protected $awsRegion;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var callable|null
     */
    protected $outputCallback;

    /**
     * UserManager constructor.
     *
     * @param QuickSightClient $quickSight
     * @param CloudTrailClient $cloudTrail
     * @param string $awsAccountId
     * @param string $awsRegion
     * @param array $config
     * @param callable|null $outputCallback
     */
    public function __construct(
        QuickSightClient $quickSight,
        CloudTrailClient $cloudTrail,
        string $awsAccountId,
        string $awsRegion,
        array $config = [],
        ?callable $outputCallback = null
    ) {
        $this->quickSight = $quickSight;
        $this->cloudTrail = $cloudTrail;
        $this->awsAccountId = $awsAccountId;
        $this->awsRegion = $awsRegion;
        $this->config = $config;
        $this->outputCallback = $outputCallback ?? function ($message, $type = 'info') {
            echo $message . PHP_EOL;
        };
    }

    /**
     * Output message using the callback function
     *
     * @param string $message The message to output
     * @param string $type The type of message (info, success, warning, error)
     */
    protected function output(string $message, string $type = 'info'): void
    {
        call_user_func($this->outputCallback, $message, $type);
    }

    /**
     * Find inactive users based on configuration settings
     *
     * @param int $inactiveDays Number of days of inactivity to consider a user inactive
     * @param array $identityTypesToCheck Identity types to check (QUICKSIGHT, IAM)
     * @param array $userRolesToCheck User roles to check (ADMIN, AUTHOR, READER, etc.)
     * @param bool $dryRun If true, will not delete users, just report them
     * @return array Statistics about the inactive user scan
     */
    public function findAndDeleteInactiveUsers(
        int $inactiveDays = 90,
        array $identityTypesToCheck = ['QUICKSIGHT'],
        array $userRolesToCheck = ['READER'],
        bool $dryRun = true
    ): array {
        $this->output("Starting inactive user scan...");

        // Validate we have configured event names
        if (empty($this->config['activity_event_names'])) {
            $this->output(
                "Error: No activity event names configured in 'activity_event_names' setting. " .
                "Please configure this in your user_management.php file.",
                'error'
            );
            throw new \InvalidArgumentException(
                "Missing required configuration: 'activity_event_names' must be defined with at " .
                "least one event name."
            );
        }

        $this->output("Looking for users inactive for {$inactiveDays} days or more");

        // Set up the time range for CloudTrail lookup
        $endTime = new DateTimeImmutable();
        $startTime = $endTime->modify("-{$inactiveDays} days");

        $this->output("Scanning activity from {$startTime->format('Y-m-d')} to {$endTime->format('Y-m-d')}");

        // Get all QuickSight users
        $users = $this->listAllUsers();
        $totalUsers = count($users);
        $this->output("Found {$totalUsers} QuickSight users total");

        // Filter users by identity type and role if specified
        if (!empty($identityTypesToCheck) || !empty($userRolesToCheck)) {
            $users = array_filter($users, function ($user) use ($identityTypesToCheck, $userRolesToCheck) {
                $matchIdentityType = empty($identityTypesToCheck) ||
                    (isset($user['IdentityType']) && in_array($user['IdentityType'], $identityTypesToCheck));

                $matchRole = empty($userRolesToCheck) ||
                    (isset($user['Role']) && in_array($user['Role'], $userRolesToCheck));

                return $matchIdentityType && $matchRole;
            });

            $this->output(
                "Filtered to " . count($users) . " users with identity types: " .
                implode(", ", $identityTypesToCheck) . " and roles: " . implode(", ", $userRolesToCheck)
            );
        }

        // Get active users based on CloudTrail events
        $activeUserArns = $this->getActiveQuickSightUserArns($startTime, $endTime);

        $this->output("Found " . count($activeUserArns) . " users with recent QuickSight activity");

        // Identify inactive users
        $inactiveUsers = [];
        foreach ($users as $user) {
            if (!in_array($user['Arn'], $activeUserArns)) {
                $inactiveUsers[] = $user;
            }
        }

        $inactiveCount = count($inactiveUsers);
        $this->output(
            "Identified {$inactiveCount} inactive users",
            $inactiveCount > 0 ? 'warning' : 'info'
        );

        // Process inactive users
        $deletedCount = 0;
        $failedCount = 0;
        $protectedCount = 0;

        foreach ($inactiveUsers as $user) {
            $username = CloudTrailHelper::extractUsernameFromArn($user['Arn']);
            $identityType = $user['IdentityType'] ?? 'Unknown';
            $role = $user['Role'] ?? 'Unknown';
            $lastActive = $user['ActiveSince'] ?? 'Unknown';

            if ($lastActive !== 'Unknown') {
                $lastActiveDate = new DateTimeImmutable($lastActive);
                $lastActive = $lastActiveDate->format('Y-m-d');
            }

            // Check if user is protected
            if ($this->isProtectedUser($username)) {
                $this->output(
                    "⚠ Protected user: {$username} (Identity: {$identityType}, Role: {$role}, 
                        Last active: {$lastActive})",
                    'comment'
                );
                $protectedCount++;
                continue;
            }

            $this->output(
                "⚠ Inactive user: {$username} (Identity: {$identityType}, Role: {$role}, Last active: {$lastActive})",
                'warning'
            );

            if (!$dryRun) {
                $result = $this->deleteUser($username, $user['Arn']);

                if ($result) {
                    $deletedCount++;
                } else {
                    $failedCount++;
                }
            }
        }

        // Summary
        if ($dryRun) {
            $this->output(
                "DRY RUN COMPLETE: {$inactiveCount} inactive users identified " .
                "({$protectedCount} protected, " . ($inactiveCount - $protectedCount) . " would delete if not dry run)",
                'info'
            );
        } else {
            $this->output(
                "Successfully deleted {$deletedCount} inactive users. " .
                "Protected: {$protectedCount}. Failed to delete: {$failedCount}.",
                $deletedCount > 0 ? 'success' : 'info'
            );
        }

        return [
            'total_users' => $totalUsers,
            'inactive_users' => $inactiveCount,
            'protected_users' => $protectedCount,
            'deleted_users' => $deletedCount,
            'failed_deletions' => $failedCount,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * List all QuickSight users in the current AWS account
     *
     * @return array Array of user details
     */
    protected function listAllUsers(): array
    {
        $users = [];
        $nextToken = null;

        do {
            $params = [
                'AwsAccountId' => $this->awsAccountId,
                'Namespace' => 'default'
            ];

            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            try {
                $response = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listUsers',
                    $params
                );

                if (isset($response['UserList'])) {
                    $users = array_merge($users, $response['UserList']);
                }

                $nextToken = $response['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->output(
                    "Error listing users: " . $e->getMessage(),
                    'error'
                );
                break;
            }
        } while ($nextToken);

        return $users;
    }

    /**
     * Get ARNs of all users who have had QuickSight activity in the specified time range
     * Uses only the event names configured in the config
     *
     * @param DateTimeImmutable $startTime Start time for activity search
     * @param DateTimeImmutable $endTime End time for activity search
     * @return array Array of active user ARNs
     */
    protected function getActiveQuickSightUserArns(
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime
    ): array {
        // Only use event names from config, no default fallback
        $eventNames = $this->config['activity_event_names'];

        $this->output("Checking for activity with event types: " . implode(", ", $eventNames));

        $events = CloudTrailHelper::lookupQuickSightEventsByName(
            $this->cloudTrail,
            $eventNames,
            $startTime,
            $endTime,
            function ($page) {
                $this->output("Processing CloudTrail events page {$page}...");
            }
        );

        $this->output("Found " . count($events) . " events in CloudTrail");

        // Extract user ARNs from events using the utility function
        $activeUserArns = CloudTrailHelper::extractUserArnsFromEvents($events);

        $this->output("Found " . count($activeUserArns) . " unique active users");

        return array_keys($activeUserArns);
    }

    /**
     * Delete a QuickSight user
     *
     * @param string $username The username to delete
     * @param string $userArn The user's ARN (for logging)
     * @return bool True if successful, false otherwise
     */
    protected function deleteUser(
        string $username,
        string $userArn
    ): bool {
        try {
            $this->output("Attempting to delete user: {$username}");

            QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'deleteUser',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'Namespace' => 'default',
                    'UserName' => $username
                ]
            );

            $this->output(
                "✅ Successfully deleted user: {$username}",
                'success'
            );

            return true;
        } catch (AwsException $e) {
            $this->output(
                "❌ Failed to delete user {$username}: " . $e->getMessage(),
                'error'
            );

            return false;
        }
    }

    /**
     * Check if a user should be protected from deletion
     *
     * @param string $username The username to check
     * @return bool True if the user should be protected, false otherwise
     */
    protected function isProtectedUser(string $username): bool
    {
        $protectedUsers = $this->config['protected_users'] ?? ['admin', 'quicksight-admin'];
        return in_array($username, $protectedUsers);
    }
}
