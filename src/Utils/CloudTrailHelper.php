<?php

namespace QSAssetManager\Utils;

use Aws\CloudTrail\CloudTrailClient;
use Aws\Exception\AwsException;

class CloudTrailHelper
{
    /**
     * Executes a CloudTrail API call with retry logic for throttling.
     *
     * @param  CloudTrailClient  $client      The CloudTrail client.
     * @param  string            $method      The API method to call.
     * @param  array             $params      The parameters for the API call.
     * @param  int               $maxRetries  Maximum number of retry attempts.
     * @return mixed The response from the API call.
     * @throws AwsException
     */
    public static function executeWithRetry(
        CloudTrailClient $client,
        string $method,
        array $params,
        int $maxRetries = 5
    ) {
        $attempt = 0;
        while (true) {
            try {
                return $client->$method($params);
            } catch (AwsException $e) {
                $isThrottling = $e->getAwsErrorCode() === 'ThrottlingException' ||
                                $e->getAwsErrorCode() === 'RateExceededException';

                if ($isThrottling && $attempt < $maxRetries) {
                    $attempt++;
                    $delay = (2 ** $attempt) * 1000 + rand(0, 1000);
                    echo "\033[33m⚠ Throttling on $method. Retry #$attempt in " . ($delay / 1000) . "s...\033[0m\n";
                    flush();
                    usleep($delay * 1000);
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Lookup CloudTrail events with pagination.
     *
     * @param  CloudTrailClient  $client           The CloudTrail client.
     * @param  array             $params           The parameters for the lookupEvents call.
     * @param  callable|null     $progressCallback Optional callback for progress updates.
     * @return array The aggregated events.
     */
    public static function lookupEventsPaginated(
        CloudTrailClient $client,
        array $params,
        ?callable $progressCallback = null
    ): array {
        $allEvents = [];
        $nextToken = null;
        $page      = 0;

        do {
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            $response = self::executeWithRetry($client, 'lookupEvents', $params);

            if (isset($response['Events'])) {
                $allEvents = array_merge($allEvents, $response['Events']);
            }

            $page++;
            if ($progressCallback) {
                call_user_func($progressCallback, $page);
            }

            $nextToken = $response['NextToken'] ?? null;
        } while ($nextToken);

        return $allEvents;
    }

    /**
     * Lookup CloudTrail events for QuickSight Dashboard embed URL events within a specified time range.
     *
     * @param  CloudTrailClient     $client           The CloudTrail client.
     * @param  \DateTimeImmutable   $startTime        Start time.
     * @param  \DateTimeImmutable   $endTime          End time.
     * @param  callable|null        $progressCallback Optional callback for progress updates.
     * @return array The events found.
     */
    public static function lookupDashboardEvents(
        CloudTrailClient $client,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        ?callable $progressCallback = null
    ): array {
        $params = [
            'LookupAttributes' => [
                [
                    'AttributeKey'   => 'EventName',
                    'AttributeValue' => 'GetDashboardEmbedUrl'
                ]
            ],
            'StartTime' => $startTime->format(\DateTime::ATOM),
            'EndTime'   => $endTime->format(\DateTime::ATOM),
        ];

        return self::lookupEventsPaginated($client, $params, $progressCallback);
    }

    /**
     * Extracts the dashboard ID from a CloudTrail event.
     *
     * @param  array  $ctEvent  The decoded CloudTrail event.
     * @return string|null The dashboard ID if found.
     */
    public static function extractDashboardIdFromEvent(array $ctEvent): ?string
    {
        if (isset($ctEvent['requestParameters']['dashboardId'])) {
            return $ctEvent['requestParameters']['dashboardId'];
        }

        if (isset($ctEvent['resources']) && is_array($ctEvent['resources'])) {
            foreach ($ctEvent['resources'] as $resource) {
                $isQuickSightDashboard = isset($resource['resourceType']) &&
                                        $resource['resourceType'] === 'AWS::QuickSight::Dashboard';

                if ($isQuickSightDashboard) {
                    if (isset($resource['resourceName']) && strpos($resource['resourceName'], 'dashboard/') !== false) {
                        $parts = explode("dashboard/", $resource['resourceName']);
                        return end($parts);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extracts a username from a user ARN.
     *
     * @param  string  $userArn  The user ARN.
     * @return string The extracted username.
     */
    public static function extractUsernameFromArn(string $userArn): string
    {
        $parts = explode("user/default/", $userArn);
        return count($parts) === 2 ? end($parts) : $userArn;
    }

    /**
 * Lookup CloudTrail events for a specific QuickSight user within a time range.
 *
 * @param  CloudTrailClient     $client           The CloudTrail client.
 * @param  string               $userArn          The user ARN to lookup.
 * @param  \DateTimeImmutable   $startTime        Start time.
 * @param  \DateTimeImmutable   $endTime          End time.
 * @param  callable|null        $progressCallback Optional callback for progress updates.
 * @return array The events found.
 */
    public static function lookupUserEvents(
        CloudTrailClient $client,
        string $userArn,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        ?callable $progressCallback = null
    ): array {
        $params = [
        'LookupAttributes' => [
            [
                'AttributeKey'   => 'Username',
                'AttributeValue' => self::extractUsernameFromArn($userArn)
            ]
        ],
        'StartTime' => $startTime->format(\DateTime::ATOM),
        'EndTime'   => $endTime->format(\DateTime::ATOM),
        ];

        return self::lookupEventsPaginated($client, $params, $progressCallback);
    }

/**
 * Lookup CloudTrail events for QuickSight service within a specified time range.
 *
 * @param  CloudTrailClient     $client           The CloudTrail client.
 * @param  \DateTimeImmutable   $startTime        Start time.
 * @param  \DateTimeImmutable   $endTime          End time.
 * @param  callable|null        $progressCallback Optional callback for progress updates.
 * @return array The events found.
 */
    public static function lookupQuickSightEvents(
        CloudTrailClient $client,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        ?callable $progressCallback = null
    ): array {
        $params = [
        'LookupAttributes' => [
            [
                'AttributeKey'   => 'EventSource',
                'AttributeValue' => 'quicksight.amazonaws.com'
            ]
        ],
        'StartTime' => $startTime->format(\DateTime::ATOM),
        'EndTime'   => $endTime->format(\DateTime::ATOM),
        ];

        return self::lookupEventsPaginated($client, $params, $progressCallback);
    }

    /**
     * Get a list of active users based on QuickSight events in CloudTrail.
     *
     * @param  CloudTrailClient     $client           The CloudTrail client.
     * @param  \DateTimeImmutable   $startTime        Start time.
     * @param  \DateTimeImmutable   $endTime          End time.
     * @param  callable|null        $progressCallback Optional callback for progress updates.
     * @return array Associative array of user ARNs to their last activity timestamp.
     */
    public static function getActiveQuickSightUsers(
        CloudTrailClient $client,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        ?callable $progressCallback = null
    ): array {
        $events = self::lookupQuickSightEvents($client, $startTime, $endTime, $progressCallback);

        $activeUsers = [];
        foreach ($events as $event) {
            if (isset($event['UserIdentity']['Arn']) && strpos($event['UserIdentity']['Arn'], ':user/') !== false) {
                $userArn = $event['UserIdentity']['Arn'];
                $eventTime = $event['EventTime'] ?? null;

                if ($eventTime) {
                    // Track the most recent activity for each user
                    if (!isset($activeUsers[$userArn]) || $eventTime > $activeUsers[$userArn]) {
                        $activeUsers[$userArn] = $eventTime;
                    }
                }
            }
        }

        return $activeUsers;
    }

    /**
     * Lookup CloudTrail events for specified QuickSight event names.
     *
     * @param  CloudTrailClient     $client           The CloudTrail client.
     * @param  array                $eventNames       List of event names to look for.
     * @param  \DateTimeImmutable   $startTime        Start time.
     * @param  \DateTimeImmutable   $endTime          End time.
     * @param  callable|null        $progressCallback Optional callback for progress updates.
     * @return array The events found.
     */
    public static function lookupQuickSightEventsByName(
        CloudTrailClient $client,
        array $eventNames,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        ?callable $progressCallback = null
    ): array {
        $allEvents = [];

        foreach ($eventNames as $eventName) {
            $params = [
                'LookupAttributes' => [
                    [
                        'AttributeKey'   => 'EventName',
                        'AttributeValue' => $eventName
                    ]
                ],
                'StartTime' => $startTime->format(\DateTime::ATOM),
                'EndTime'   => $endTime->format(\DateTime::ATOM),
            ];

            $events = self::lookupEventsPaginated($client, $params, $progressCallback);
            $allEvents = array_merge($allEvents, $events);
        }

        return $allEvents;
    }

    /**
     * Extract user ARNs from CloudTrail events.
     * Extracts user ARNs from both userIdentity and requestParameters.
     *
     * @param array $events The CloudTrail events to process
     * @return array Associative array of unique user ARNs
     */
    public static function extractUserArnsFromEvents(array $events): array
    {
        $userArns = [];

        foreach ($events as $event) {
            // Extract from standard UserIdentity field
            if (isset($event['UserIdentity']['Arn'])) {
                $userArn = $event['UserIdentity']['Arn'];
                if (strpos($userArn, ':user/') !== false) {
                    $userArns[$userArn] = true;
                }
            }

            // Extract from CloudTrailEvent field (used in UserReportingManager)
            if (!empty($event['CloudTrailEvent'])) {
                $ct = json_decode($event['CloudTrailEvent'], true);

                // Check userIdentity in CloudTrailEvent
                if (isset($ct['userIdentity']['arn'])) {
                    $userArn = $ct['userIdentity']['arn'];
                    if (strpos($userArn, ':user/') !== false) {
                        $userArns[$userArn] = true;
                    }
                }

                // Check requestParameters in CloudTrailEvent (used in UserReportingManager)
                if (isset($ct['requestParameters']['userArn'])) {
                    $userArn = $ct['requestParameters']['userArn'];
                    if (strpos($userArn, ':user/') !== false) {
                        $userArns[$userArn] = true;
                    }
                }
            }
        }

        return $userArns;
    }
}
