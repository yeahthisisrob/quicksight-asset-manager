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
}
