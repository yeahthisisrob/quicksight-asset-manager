<?php

namespace QSAssetManager\Manager\Reporting;

use Aws\CloudTrail\CloudTrailClient;
use Aws\QuickSight\QuickSightClient;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\CloudTrailHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class ReportingManager
{
    protected array $config;
    protected QuickSightClient $quickSight;
    protected string $awsAccountId;
    protected string $awsRegion;

    public function __construct(
        array $config,
        QuickSightClient $quickSight,
        string $awsAccountId,
        string $awsRegion
    ) {
        $this->config       = $config;
        $this->quickSight   = $quickSight;
        $this->awsAccountId = $awsAccountId;
        $this->awsRegion    = $awsRegion;
    }

    /**
     * Export dashboard view counts based on CloudTrail events.
     *
     * @param  CloudTrailClient  $cloudTrailClient  The CloudTrail client.
     * @return bool True on success, false on failure.
     */
    public function exportDashboardViewCounts(CloudTrailClient $cloudTrailClient): bool
    {
        $output = new ConsoleOutput();

        // Build output file path using the report export path from configuration
        $reportExportPath = $this->config['paths']['report_export_path'];
        $fileName         = sprintf("dashboard_view_counts_%s.csv", date("Ymd_His"));
        $outputFile       = rtrim($reportExportPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

        // Define time range
        $endTime   = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
        $startTime = $endTime->sub(new \DateInterval("P90D"));
        $output->writeln(sprintf(
            "Looking up CloudTrail events from %s to %s",
            $startTime->format("Y-m-d H:i:s"),
            $endTime->format("Y-m-d H:i:s")
        ));

        // List dashboards
        $dashboards = $this->listDashboardsForExport();
        $output->writeln("Found " . count($dashboards) . " dashboards.");

        // Create a progress bar for paging CloudTrail events
        $progressBar = new ProgressBar($output);
        $progressBar->start();

        // Lookup CloudTrail events with a progress callback
        $allEvents = CloudTrailHelper::lookupDashboardEvents(
            client: $cloudTrailClient,
            startTime: $startTime,
            endTime: $endTime,
            progressCallback: function ($page) use ($progressBar) {
                $progressBar->advance();
            }
        );
        $progressBar->finish();
        $output->writeln(""); // New line after progress bar

        $output->writeln("Retrieved " . count($allEvents) . " events.");

        // Aggregate events by dashboard and user
        $aggregated = $this->aggregateDashboardEvents($allEvents);

        // Build a map of dashboard IDs to dashboard summary
        $dashMap = [];
        foreach ($dashboards as $dash) {
            if (isset($dash['DashboardId'])) {
                $dashMap[$dash['DashboardId']] = $dash;
            }
        }

        $reportRows       = [];
        $deletedDashboards = 0;
        $deletedViews     = 0;

        // Process dashboards that have view events
        foreach ($aggregated as $dashId => $dashInfo) {
            $dashSummary = $dashMap[$dashId] ?? ['Name' => 'Not Listed / Unknown'];
            try {
                $detail = QuickSightHelper::executeWithRetry(
                    client: $this->quickSight,
                    method: 'describeDashboard',
                    params: [
                        'AwsAccountId' => $this->awsAccountId,
                        'DashboardId'  => $dashId,
                    ]
                );
                $dashboardDetail    = $detail['Dashboard'] ?? [];
                $createdTime        = $dashboardDetail['CreatedTime'] ?? null;
                $lastPublishedTime  = $dashboardDetail['LastPublishedTime'] ?? null;
                $dashboardArn       = $dashboardDetail['Arn'] ?? null;
                $dashboardTags      = null;

                if ($dashboardArn) {
                    try {
                        $tagsResponse = QuickSightHelper::executeWithRetry(
                            client: $this->quickSight,
                            method: 'listTagsForResource',
                            params: ['ResourceArn' => $dashboardArn]
                        );
                        $dashboardTags = $tagsResponse['Tags'] ?? null;
                    } catch (\Exception $ex) {
                        $dashboardTags = null;
                    }
                }
            } catch (\Aws\Exception\AwsException $ex) {
                if ($ex->getAwsErrorCode() === 'ResourceNotFoundException') {
                    $deletedDashboards++;
                    foreach ($dashInfo['users'] as $userStats) {
                        $deletedViews += $userStats['count'];
                    }
                    continue;
                } else {
                    $output->writeln(
                        "Warning: Failed to get details for dashboard $dashId: " . $ex->getMessage()
                    );
                    continue;
                }
            }

            $dashName = $dashSummary['Name'] ?? 'Not Listed / Unknown';
            foreach ($dashInfo['users'] as $user => $stats) {
                $reportRows[] = [
                    'DashboardId'       => $dashId,
                    'DashboardName'     => $dashName,
                    'User'              => $user,
                    'ViewCount'         => $stats['count'],
                    'LastView'          => $this->formatDatetime($stats['last_view']),
                    'CreatedTime'       => $this->formatDatetime($createdTime),
                    'LastPublishedTime' => $this->formatDatetime($lastPublishedTime),
                    'Tags'              => $dashboardTags ? json_encode($dashboardTags) : null,
                ];
            }
        }

        // Also add rows for dashboards with no view events
        foreach ($dashboards as $dash) {
            $dashId = $dash['DashboardId'];
            if (isset($aggregated[$dashId])) {
                continue;
            }

            try {
                $detail = QuickSightHelper::executeWithRetry(
                    client: $this->quickSight,
                    method: 'describeDashboard',
                    params: [
                        'AwsAccountId' => $this->awsAccountId,
                        'DashboardId'  => $dashId,
                    ]
                );
                $dashboardDetail    = $detail['Dashboard'] ?? [];
                $createdTime        = $dashboardDetail['CreatedTime'] ?? null;
                $lastPublishedTime  = $dashboardDetail['LastPublishedTime'] ?? null;
                $dashboardArn       = $dashboardDetail['Arn'] ?? null;
                $dashboardTags      = null;

                if ($dashboardArn) {
                    try {
                        $tagsResponse = QuickSightHelper::executeWithRetry(
                            client: $this->quickSight,
                            method: 'listTagsForResource',
                            params: ['ResourceArn' => $dashboardArn]
                        );
                        $dashboardTags = $tagsResponse['Tags'] ?? null;
                    } catch (\Exception $ex) {
                        $dashboardTags = null;
                    }
                }
            } catch (\Exception $ex) {
                continue;
            }

            $reportRows[] = [
                'DashboardId'       => $dashId,
                'DashboardName'     => $dash['Name'] ?? 'Not Listed / Unknown',
                'User'              => null,
                'ViewCount'         => null,
                'LastView'          => null,
                'CreatedTime'       => $this->formatDatetime($createdTime),
                'LastPublishedTime' => $this->formatDatetime($lastPublishedTime),
                'Tags'              => $dashboardTags ? json_encode($dashboardTags) : null,
            ];
        }

        // Attempt CSV export
        try {
            if (!$this->exportToCsv(rows: $reportRows, filename: $outputFile)) {
                $output->writeln(
                    "<error>Failed to export report to {$outputFile} (file could not be written).</error>"
                );
                return false;
            }
            $output->writeln("<info>Report exported successfully to {$outputFile}</info>");
        } catch (\Exception $ex) {
            $output->writeln("<error>Exception during CSV export: " . $ex->getMessage() . "</error>");
            return false;
        }

        if ($deletedDashboards > 0) {
            $output->writeln(sprintf(
                "<comment>Skipped %d deleted dashboards with a total of %d view(s) that were not included " .
                "in the export.</comment>",
                $deletedDashboards,
                $deletedViews
            ));
        }

        return true;
    }

    /**
     * Aggregate CloudTrail events by dashboard and user.
     *
     * @param  array  $events  The CloudTrail events to process.
     * @return array Associative array with dashboard IDs as keys.
     */
    protected function aggregateDashboardEvents(array $events): array
    {
        $aggregated = [];

        foreach ($events as $event) {
            if (!isset($event['EventTime'])) {
                continue;
            }

            $eventTime   = $event['EventTime'];
            $rawCtEvent  = $event['CloudTrailEvent'] ?? '{}';
            $ctEvent     = json_decode($rawCtEvent, true);

            if (!$ctEvent) {
                continue;
            }

            $userArn  = $ctEvent['requestParameters']['userArn'] ?? null;
            $userName = $userArn
                ? CloudTrailHelper::extractUsernameFromArn($userArn)
                : ($ctEvent['userIdentity']['userName'] ?? ($ctEvent['username'] ?? 'Unknown'));

            $dashId = CloudTrailHelper::extractDashboardIdFromEvent($ctEvent);

            if (!$dashId) {
                continue;
            }

            if (!isset($aggregated[$dashId])) {
                $aggregated[$dashId] = ['users' => []];
            }

            if (!isset($aggregated[$dashId]['users'][$userName])) {
                $aggregated[$dashId]['users'][$userName] = ['count' => 0, 'last_view' => $eventTime];
            }

            $aggregated[$dashId]['users'][$userName]['count']++;

            if (strtotime($eventTime) > strtotime($aggregated[$dashId]['users'][$userName]['last_view'])) {
                $aggregated[$dashId]['users'][$userName]['last_view'] = $eventTime;
            }
        }

        return $aggregated;
    }

    /**
     * Retrieves all dashboards across all pages.
     *
     * @return array List of dashboard summaries.
     */
    protected function listDashboardsForExport(): array
    {
        $dashboards = [];
        $nextToken  = null;

        do {
            $params = ['AwsAccountId' => $this->awsAccountId];

            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            $response = QuickSightHelper::executeWithRetry(
                client: $this->quickSight,
                method: 'listDashboards',
                params: $params
            );

            if (isset($response['DashboardSummaryList'])) {
                $dashboards = array_merge($dashboards, $response['DashboardSummaryList']);
            }

            $nextToken = $response['NextToken'] ?? null;
        } while ($nextToken);

        return $dashboards;
    }

    /**
     * Format datetime values consistently.
     *
     * @param  mixed  $dt  The datetime value to format.
     * @return string|null Formatted datetime string or null.
     */
    protected function formatDatetime($dt): ?string
    {
        if (!$dt) {
            return null;
        }

        $utc = new \DateTimeZone("UTC");

        if ($dt instanceof \DateTimeImmutable) {
            return $dt->setTimezone($utc)->format("Y-m-d H:i:s");
        }

        if ($dt instanceof \DateTime) {
            $dt->setTimezone($utc);
            return $dt->format("Y-m-d H:i:s");
        }

        $timestamp = strtotime($dt);
        return $timestamp ? gmdate("Y-m-d H:i:s", $timestamp) : $dt;
    }

    /**
     * Export data to a CSV file.
     *
     * @param  array   $rows      The data rows to export.
     * @param  string  $filename  The target filename.
     * @return bool True on success, false on failure.
     */
    protected function exportToCsv(array $rows, string $filename): bool
    {
        $fieldnames = [
            'DashboardId',
            'DashboardName',
            'User',
            'ViewCount',
            'LastView',
            'CreatedTime',
            'LastPublishedTime',
            'Tags'
        ];

        $fh = @fopen($filename, 'w');
        if (!$fh) {
            return false;
        }

        // Write CSV header
        fputcsv($fh, $fieldnames, ',', '"', '\\');

        // Write each row
        foreach ($rows as $row) {
            $data = [];
            foreach ($fieldnames as $field) {
                $data[] = $row[$field] ?? null;
            }
            fputcsv($fh, $data, ',', '"', '\\');
        }

        fclose($fh);
        return true;
    }
}
