<?php

namespace QSAssetManager\Manager\Reporting;

use Aws\QuickSight\QuickSightClient;
use Aws\CloudTrail\CloudTrailClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\CloudTrailHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use GuzzleHttp\Promise;

class UserReportingManager
{
    protected array $config;
    protected QuickSightClient $quickSight;
    protected string $awsAccountId;
    protected string $awsRegion;
    protected ?SymfonyStyle $io;
    protected int $maxConcurrent = 5;

    public function __construct(
        array $config,
        QuickSightClient $quickSight,
        string $awsAccountId,
        string $awsRegion,
        ?SymfonyStyle $io = null
    ) {
        $this->config       = $config;
        $this->quickSight   = $quickSight;
        $this->awsAccountId = $awsAccountId;
        $this->awsRegion    = $awsRegion;
        $this->io           = $io;
    }

    /**
     * Export a CSV report of QuickSight users.
     *
     * The CSV columns are:
     * Active, Arn, CustomPermissionsName, Email,
     * ExternalLoginFederationProviderType, ExternalLoginFederationProviderUrl,
     * ExternalLoginId, IdentityType, PrincipalId, Role, UserName, Tags,
     * [Groups,] Embed Calls, Last Embed, Unique Days.
     *
     * @param  string|null  $outputPath    Directory for CSV.
     * @param  bool         $withGroups    If true, fetch and output user groups.
     * @param  bool         $withTags      If true, fetch and output user tags.
     * @return string|false Full path to CSV or false on failure.
     */
    public function exportUserReport(
        ?string $outputPath = null,
        bool $withGroups = false,
        bool $withTags = true
    ): bool|string {
        $startTime = microtime(true);
        $this->write("Starting user report export...");

        // Prepare export path and filename
        $timestamp   = date('Ymd_His');
        $defaultPath = $this->config['paths']['report_export_path'] ?? (getcwd() . '/exports');
        $exportDir   = rtrim($outputPath ?? $defaultPath, DIRECTORY_SEPARATOR);

        if (!is_dir($exportDir)) {
            mkdir(directory: $exportDir, permissions: 0777, recursive: true);
            $this->write("Created export directory: $exportDir");
        }

        $filename = "{$exportDir}/quicksight_users_report_{$timestamp}.csv";
        $fh = fopen(filename: $filename, mode: 'w');

        if (!$fh) {
            $this->write("Error: Unable to create output file", 'error');
            return false;
        }

        // Build header: include 'Groups' column only if $withGroups is true
        $header = [
            'Active', 'Arn', 'CustomPermissionsName', 'Email',
            'ExternalLoginFederationProviderType', 'ExternalLoginFederationProviderUrl',
            'ExternalLoginId', 'IdentityType', 'PrincipalId', 'Role', 'UserName', 'Tags',
            'Embed Calls', 'Last Embed', 'Unique Days'
        ];

        if ($withGroups) {
            // Insert 'Groups' before the last three columns
            array_splice($header, 12, 0, ['Groups']);
        }

        fputcsv($fh, $header, ',', '"', '\\');

        // Fetch users and CloudTrail events (only once, not per user)
        $fetchUsersStart = microtime(true);
        $this->write("Fetching QuickSight users...");
        $users = $this->getAllUsers();
        $userCount = count($users);
        $fetchUsersEnd = microtime(true);
        $this->write(sprintf(
            "Fetched %d users in %.2f seconds",
            $userCount,
            $fetchUsersEnd - $fetchUsersStart
        ));

        // Initialize CloudTrail client
        $fetchEventsStart = microtime(true);
        $this->write("Fetching embed events from CloudTrail...");
        $cloudTrailClient = new CloudTrailClient([
            'version' => '2013-11-01',
            'region'  => $this->awsRegion,
        ]);

        // Fetch all embed events at once
        $endTime   = new \DateTimeImmutable('now', new \DateTimeZone("UTC"));
        $startTime = $endTime->sub(new \DateInterval("P90D"));

        $events = CloudTrailHelper::lookupDashboardEvents(
            client: $cloudTrailClient,
            startTime: $startTime,
            endTime: $endTime,
            progressCallback: function ($page) use ($fetchEventsStart): void {
                $elapsed = microtime(true) - $fetchEventsStart;
                $this->write(sprintf(
                    "CloudTrail lookup: Fetched page %d (elapsed: %.2f seconds)",
                    $page,
                    $elapsed
                ));
            }
        );

        $fetchEventsEnd = microtime(true);
        $this->write(sprintf(
            "Fetched %d CloudTrail events in %.2f seconds",
            count($events),
            $fetchEventsEnd - $fetchEventsStart
        ));

        // Process all events to get user stats in one go
        $processStatsStart = microtime(true);
        $this->write("Processing user view statistics...");
        $viewStats = $this->getUserViewStats($events);
        $processStatsEnd = microtime(true);
        $this->write(sprintf(
            "Processed user statistics in %.2f seconds",
            $processStatsEnd - $processStatsStart
        ));

        // Pre-fetch tags for all users in parallel (if enabled)
        $userTags = [];
        if ($withTags) {
            $tagsStart = microtime(true);
            $this->write("Fetching tags for all users in parallel...");
            $userTags = $this->fetchUserTagsInParallel($users);
            $tagsEnd = microtime(true);
            $this->write(sprintf(
                "Fetched tags for %d users in %.2f seconds",
                count($userTags),
                $tagsEnd - $tagsStart
            ));
        }

        // Pre-fetch groups for all users in parallel (if enabled)
        $userGroups = [];
        if ($withGroups) {
            $groupsStart = microtime(true);
            $this->write("Fetching groups for all users in parallel...");
            $userGroups = $this->fetchUserGroupsInParallel($users);
            $groupsEnd = microtime(true);
            $this->write(sprintf(
                "Fetched groups for %d users in %.2f seconds",
                count($userGroups),
                $groupsEnd - $groupsStart
            ));
        }

        // Process each user
        $processUsersStart = microtime(true);
        $this->write("Processing $userCount users...");
        $processed = 0;

        foreach ($users as $userSummary) {
            $processed++;
            if ($processed % 100 === 0 || $processed === $userCount) {
                $elapsed = microtime(true) - $processUsersStart;
                $this->write(sprintf(
                    "Processed %d of %d users (%.1f%%, elapsed: %.2f seconds)",
                    $processed,
                    $userCount,
                    ($processed / $userCount) * 100,
                    $elapsed
                ));
            }

            // Use the summary data from listUsers (no describeUser call)
            $user = $userSummary;
            $userName = $user['UserName'] ?? 'Unknown';

            // Get pre-fetched tags
            $tags = $withTags ? ($userTags[$userName] ?? []) : [];

            // Get pre-fetched groups
            $groupField = '';
            if ($withGroups) {
                $groupNames = $userGroups[$userName] ?? [];
                $groupField = implode('|', $groupNames);
            }

            // Get user stats
            $stats = $viewStats[$userName] ?? ['views' => 0, 'last_view' => '', 'unique_days' => 0];

            // Build the row
            $row = [
                isset($user['Active']) && $user['Active'] ? 1 : 0,
                $user['Arn'] ?? '',
                $user['CustomPermissionsName'] ?? '',
                $user['Email'] ?? '',
                $user['ExternalLoginFederationProviderType'] ?? '',
                $user['ExternalLoginFederationProviderUrl'] ?? '',
                $user['ExternalLoginId'] ?? '',
                $user['IdentityType'] ?? '',
                $user['PrincipalId'] ?? '',
                $user['Role'] ?? '',
                $userName,
                json_encode($tags)
            ];

            if ($withGroups) {
                $row[] = $groupField;
            }

            $row[] = $stats['views'];
            $row[] = $stats['last_view'];
            $row[] = $stats['unique_days'];

            fputcsv($fh, $row, ',', '"', '\\');
        }

        $processUsersEnd = microtime(true);
        $this->write(sprintf(
            "Processed all users in %.2f seconds",
            $processUsersEnd - $processUsersStart
        ));

        fclose($fh);
        $totalTime = is_object($startTime) && $startTime instanceof \DateTimeImmutable
            ? time() - $startTime->getTimestamp()
            : microtime(true) - $startTime;
        $this->write(sprintf(
            "User report generated successfully at: %s (Total time: %.2f seconds)",
            $filename,
            $totalTime
        ), 'success');

        return $filename;
    }

    /**
     * Fetch tags for multiple users in parallel with retry strategy for throttling exceptions.
     *
     * @param  array  $users  List of user objects.
     * @return array Associative array mapping usernames to their tags.
     */
    protected function fetchUserTagsInParallel(array $users): array
    {
        $startTime = microtime(true);
        $userTags = [];
        $promises = [];
        $errorCount = 0;
        $throttledCount = 0;
        $totalUsers = count($users);
        $completedCount = 0; // Initialize here to fix undefined variable issue

        // Prepare a list of user tasks to process
        $userTasks = [];
        foreach ($users as $user) {
            if (!isset($user['Arn']) || !isset($user['UserName'])) {
                continue;
            }

            $userTasks[] = [
                'userName' => $user['UserName'],
                'userArn' => $user['Arn'],
                'retryCount' => 0,
                'maxRetries' => 5
            ];
        }

        // Process user tasks in batches with retries for throttled requests
        while (!empty($userTasks)) {
            $batchTasks = array_splice($userTasks, 0, $this->maxConcurrent);
            $promises = [];

            foreach ($batchTasks as $index => $task) {
                $userName = $task['userName'];
                $userArn = $task['userArn'];
                $retryCount = $task['retryCount'];

                try {
                    $promises[$userName] = $this->quickSight->listTagsForResourceAsync([
                        'ResourceArn' => $userArn
                    ])->then(
                        function ($result) use ($userName, &$userTags, &$completedCount, $startTime, $totalUsers) {
                            $userTags[$userName] = $result['Tags'] ?? [];
                            $completedCount++;

                            if ($completedCount % 50 === 0 || $completedCount === $totalUsers) {
                                $this->write(sprintf(
                                    "Tag fetching: Completed %d of %d (%.1f%%, elapsed: %.2f seconds)",
                                    $completedCount,
                                    $totalUsers,
                                    ($completedCount / $totalUsers) * 100,
                                    microtime(true) - $startTime
                                ));
                            }
                            return $userTags[$userName];
                        },
                        function ($reason) use (
                            $userName,
                            $userArn,
                            $retryCount,
                            &$userTags,
                            &$userTasks,
                            &$errorCount,
                            &$throttledCount,
                            &$completedCount
                        ) {
                            $awsErrorCode = $reason->getAwsErrorCode();
                            $isThrottling = $awsErrorCode === 'ThrottlingException' ||
                                           $awsErrorCode === 'RateExceededException';

                            if ($isThrottling && $retryCount < 5) {
                                $throttledCount++;
                                $delay = (int)((2 ** $retryCount) * 1000 + rand(100, 1000));
                                $this->write(sprintf(
                                    "Throttling detected. Retry #%d scheduled in %.1f seconds",
                                    $userName,
                                    $retryCount + 1,
                                    $delay / 1000
                                ), 'comment');

                                // Add back to the task list with increased retry count
                                usleep($delay * 1000); // Sleep before adding back to queue
                                $userTasks[] = [
                                    'userName' => $userName,
                                    'userArn' => $userArn,
                                    'retryCount' => $retryCount + 1,
                                    'maxRetries' => 5
                                ];
                            } else {
                                $errorCount++;
                                $this->write(
                                    "Warning: Failed to fetch tags for user $userName after " .
                                    ($retryCount > 0 ? "$retryCount retries: " : ": ") .
                                    $reason->getMessage(),
                                    'warning'
                                );
                                // Add empty tags for this user
                                $userTags[$userName] = [];
                                $completedCount++;
                            }
                            return [];
                        }
                    );
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->write("Exception preparing tag fetch for user $userName: " . $e->getMessage(), 'error');
                    $userTags[$userName] = [];
                    $completedCount++;
                }
            }

            // Wait for all promises in this batch
            if (!empty($promises)) {
                try {
                    Promise\Utils::settle($promises)->wait();
                } catch (\Exception $e) {
                    $this->write("Error in promise batch: " . $e->getMessage(), 'error');
                }
            }

            // Throttle between batches to reduce API pressure
            if (!empty($userTasks)) {
                usleep(200000); // 200ms between batches
            }
        }

        $this->write(sprintf(
            "Tag fetching complete: %d of %d users processed, %d throttled requests retried, %d permanent errors",
            $completedCount,
            $totalUsers,
            $throttledCount,
            $errorCount
        ), $errorCount > 0 ? 'warning' : 'info');

        return $userTags;
    }

    /**
     * Fetch group memberships for multiple users in parallel with retry strategy for throttling.
     *
     * @param  array  $users  List of user objects.
     * @return array Associative array mapping usernames to their group names.
     */
    protected function fetchUserGroupsInParallel(array $users): array
    {
        $startTime = microtime(true);
        $userGroups = [];
        $completedCount = 0; // Initialize here to fix undefined variable issue
        $errorCount = 0;
        $throttledCount = 0;
        $totalUsers = count($users);

        // Prepare a list of user tasks to process
        $userTasks = [];
        foreach ($users as $user) {
            if (!isset($user['UserName'])) {
                continue;
            }

            $userTasks[] = [
                'userName' => $user['UserName'],
                'retryCount' => 0,
                'maxRetries' => 5
            ];
        }

        // Process user tasks in batches with retries for throttled requests
        while (!empty($userTasks)) {
            $batchTasks = array_splice($userTasks, 0, $this->maxConcurrent);
            $promises = [];

            foreach ($batchTasks as $index => $task) {
                $userName = $task['userName'];
                $retryCount = $task['retryCount'];

                try {
                    $promises[$userName] = $this->quickSight->listUserGroupsAsync([
                        'AwsAccountId' => $this->awsAccountId,
                        'Namespace'    => 'default',
                        'UserName'     => $userName
                    ])->then(
                        function ($result) use ($userName, &$userGroups, &$completedCount, $startTime, $totalUsers) {
                            $groupsList = $result['GroupList'] ?? [];
                            $groupNames = [];

                            foreach ($groupsList as $group) {
                                $groupNames[] = $group['GroupName'] ?? '';
                            }

                            $userGroups[$userName] = $groupNames;
                            $completedCount++;

                            if ($completedCount % 50 === 0 || $completedCount === $totalUsers) {
                                $this->write(sprintf(
                                    "Group fetching: Completed %d of %d (%.1f%%, elapsed: %.2f seconds)",
                                    $completedCount,
                                    $totalUsers,
                                    ($completedCount / $totalUsers) * 100,
                                    microtime(true) - $startTime
                                ));
                            }

                            return $groupNames;
                        },
                        function ($reason) use (
                            $userName,
                            $retryCount,
                            &$userGroups,
                            &$userTasks,
                            &$errorCount,
                            &$throttledCount,
                            &$completedCount
                        ) {
                            $awsErrorCode = $reason->getAwsErrorCode();
                            $isThrottling = $awsErrorCode === 'ThrottlingException' ||
                                           $awsErrorCode === 'RateExceededException';

                            if ($isThrottling && $retryCount < 5) {
                                $throttledCount++;
                                $delay = (int)((2 ** $retryCount) * 1000 + rand(100, 1000));
                                $this->write(sprintf(
                                    "Throttling detected for user groups %s. Retry #%d scheduled in %.1f seconds",
                                    $userName,
                                    $retryCount + 1,
                                    $delay / 1000
                                ), 'comment');

                                // Add back to the task list with increased retry count
                                usleep($delay * 1000); // Sleep before adding back to queue
                                $userTasks[] = [
                                    'userName' => $userName,
                                    'retryCount' => $retryCount + 1,
                                    'maxRetries' => 5
                                ];
                            } else {
                                $errorCount++;
                                $this->write(
                                    "Warning: Failed to fetch groups for user $userName after " .
                                    ($retryCount > 0 ? "$retryCount retries: " : ": ") .
                                    $reason->getMessage(),
                                    'warning'
                                );
                                // Add empty groups for this user
                                $userGroups[$userName] = [];
                                $completedCount++;
                            }
                            return [];
                        }
                    );
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->write("Exception preparing group fetch for user $userName: " . $e->getMessage(), 'error');
                    $userGroups[$userName] = [];
                    $completedCount++;
                }
            }

            // Wait for all promises in this batch
            if (!empty($promises)) {
                try {
                    Promise\Utils::settle($promises)->wait();
                } catch (\Exception $e) {
                    $this->write("Error in promise batch: " . $e->getMessage(), 'error');
                }
            }

            // Throttle between batches to reduce API pressure
            if (!empty($userTasks)) {
                usleep(200000); // 200ms between batches
            }
        }

        $this->write(sprintf(
            "Group fetching complete: %d of %d users processed, %d throttled requests retried, %d permanent errors",
            $completedCount,
            $totalUsers,
            $throttledCount,
            $errorCount
        ), $errorCount > 0 ? 'warning' : 'info');

        return $userGroups;
    }

    /**
     * Retrieves all QuickSight users across all pages.
     *
     * @return array
     */
    protected function getAllUsers(): array
    {
        $allUsers = [];
        $nextToken = null;
        $page = 0;

        do {
            $page++;
            $params = [
                'AwsAccountId' => $this->awsAccountId,
                'Namespace' => 'default'
            ];

            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            $resp = QuickSightHelper::executeWithRetry(
                client: $this->quickSight,
                method: 'listUsers',
                params: $params
            );

            $users = $resp['UserList'] ?? [];
            $allUsers = array_merge($allUsers, $users);
            $nextToken = $resp['NextToken'] ?? null;

            $this->write(sprintf(
                "Users: Fetched page %d with %d users (running total: %d)",
                $page,
                count($users),
                count($allUsers)
            ));
        } while ($nextToken);

        return $allUsers;
    }

    /**
     * Get view statistics for users based on QuickSight embed events.
     *
     * Returns an array mapping user names to:
     *   'views'       => total embed call count,
     *   'last_view'   => last embed datetime (Y-m-d H:i:s),
     *   'unique_days' => number of unique days with embed activity
     *
     * @param array $events The CloudTrail events to process
     * @return array
     */
    protected function getUserViewStats(array $events): array
    {
        $stats = [];
        $userDays = []; // Track unique days per user
        $processedCount = 0;
        $totalEvents = count($events);
        $startTime = microtime(true);

        foreach ($events as $event) {
            $processedCount++;

            if ($processedCount % 500 === 0 || $processedCount === $totalEvents) {
                $elapsed = microtime(true) - $startTime;
                $this->write(sprintf(
                    "Stats processing: %d of %d events (%.1f%%, elapsed: %.2f seconds)",
                    $processedCount,
                    $totalEvents,
                    ($processedCount / $totalEvents) * 100,
                    $elapsed
                ));
            }

            $ct = json_decode($event['CloudTrailEvent'], true);
            $user = $ct['userIdentity']['userName'] ??
                   (isset($ct['requestParameters']['userArn'])
                        ? CloudTrailHelper::extractUsernameFromArn($ct['requestParameters']['userArn'])
                        : 'Unknown');

            $eventTime = strtotime($event['EventTime']);
            $eventDay = date('Y-m-d', $eventTime);

            if (!isset($stats[$user])) {
                $stats[$user] = ['views' => 0, 'last_view' => $eventTime, 'unique_days' => 0];
                $userDays[$user] = [];
            }

            $stats[$user]['views']++;

            // Add this day to the user's unique days if not already counted
            if (!in_array($eventDay, $userDays[$user])) {
                $userDays[$user][] = $eventDay;
                $stats[$user]['unique_days'] = count($userDays[$user]);
            }

            if ($eventTime > $stats[$user]['last_view']) {
                $stats[$user]['last_view'] = $eventTime;
            }
        }

        // Format dates
        foreach ($stats as $user => &$data) {
            $data['last_view'] = date("Y-m-d H:i:s", $data['last_view']);
        }

        return $stats;
    }

    /**
     * Helper method to write messages with optional styling.
     *
     * @param  string  $message  The message to write.
     * @param  string  $type     Message type (info, error, warning, success).
     * @return void
     */
    protected function write(string $message, string $type = 'info'): void
    {
        if ($this->io) {
            $this->io->writeln("<$type>$message</$type>");
        } else {
            echo $message . "\n";
        }
    }
}
