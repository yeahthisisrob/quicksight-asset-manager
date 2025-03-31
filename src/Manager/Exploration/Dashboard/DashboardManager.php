<?php

namespace QSAssetManager\Manager\Exploration\Dashboard;

use QSAssetManager\Manager\Exploration\ExplorationAssetManager;
use Aws\CloudTrail\CloudTrailClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\CloudTrailHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Manager for QuickSight Dashboard operations
 */
class DashboardManager extends ExplorationAssetManager
{
    /**
     * Get the dashboard definition
     *
     * @param string $dashboardId The dashboard ID
     * @return array|null The dashboard definition or null if an error occurred
     */
    protected function getAssetDefinition(string $dashboardId): ?array
    {
        try {
            $definitionResponse = QuickSightHelper::executeWithRetry($this->quickSight, 'describeDashboardDefinition', [
                'AwsAccountId' => $this->awsAccountId,
                'DashboardId' => $dashboardId,
            ]);
        } catch (AwsException $e) {
            echo "Error describing dashboard definition: " . $e->getMessage() . "\n";
            return null;
        }

        $responseArray = $definitionResponse->toArray();

        if (!isset($responseArray['Definition'])) {
            echo "Dashboard definition missing 'Definition' member.\n";
            return null;
        }

        return $responseArray['Definition'];
    }

    /**
     * Update the dashboard with a new definition
     *
     * @param string $dashboardId The dashboard ID
     * @param array $definition The updated definition
     * @param string $name The name of the dashboard
     * @return bool True if successful, false otherwise
     */
    protected function updateAsset(string $dashboardId, array $definition, string $name = ''): bool
    {
        if (empty($name)) {
            $name = $this->getAssetName($dashboardId);
        }

        $updateParams = [
            'AwsAccountId' => $this->awsAccountId,
            'DashboardId' => $dashboardId,
            'Name' => $name,
            'Definition' => $definition,
        ];

        try {
            $updateResponse = QuickSightHelper::executeWithRetry($this->quickSight, 'updateDashboard', $updateParams);
            echo "Dashboard updated successfully.\n";

            // Update the published version using the update response
            QuickSightHelper::updateDashboardPublishedVersion(
                $this->quickSight,
                $this->awsAccountId,
                $dashboardId,
                $updateResponse
            );

            return true;
        } catch (AwsException $e) {
            echo "Error updating dashboard: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Get the dashboard name
     *
     * @param string $dashboardId The dashboard ID
     * @return string The dashboard name or a default name
     */
    protected function getAssetName(string $dashboardId): string
    {
        try {
            $describeResponse = QuickSightHelper::executeWithRetry($this->quickSight, 'describeDashboard', [
                'AwsAccountId' => $this->awsAccountId,
                'DashboardId' => $dashboardId,
            ]);

            if (isset($describeResponse['Dashboard']['Name'])) {
                return $describeResponse['Dashboard']['Name'];
            }
        } catch (AwsException $e) {
            echo "Warning: Could not get dashboard name: " . $e->getMessage() . "\n";
        }

        return "Updated Dashboard";
    }

    /**
     * Get the dashboard ARN
     *
     * @param string $dashboardId The dashboard ID
     * @return string The dashboard ARN
     */
    protected function getAssetArn(string $dashboardId): string
    {
        return "arn:aws:quicksight:" . $this->awsRegion . ":" . $this->awsAccountId . ":dashboard/" . $dashboardId;
    }

    /**
     * Rename a dashboard
     *
     * @param string $dashboardId
     * @param string $newName
     * @return bool
     */
    public function renameDashboard(string $dashboardId, string $newName): bool
    {
        $dashboard = QuickSightHelper::executeWithRetry(
            client: $this->quickSight,
            method: 'describeDashboard',
            params: [
                'AwsAccountId' => $this->awsAccountId,
                'DashboardId'  => $dashboardId,
            ]
        );

        $currentName = $dashboard['Dashboard']['Name'] ?? 'Unknown';

        $definition = $this->getAssetDefinition(dashboardId: $dashboardId);
        if ($definition === null) {
            return false;
        }

        $updateParams = [
            'AwsAccountId' => $this->awsAccountId,
            'DashboardId' => $dashboardId,
            'Name' => $newName,
            'Definition' => $definition,
        ];

        try {
            echo "Dashboard update requested: {$currentName} → {$newName}\n";
            $updateResponse = QuickSightHelper::executeWithRetry(
                client: $this->quickSight,
                method: 'updateDashboard',
                params: $updateParams
            );

            $versionNumber = QuickSightHelper::extractVersionNumber($updateResponse);

            if (!$versionNumber) {
                echo "❌ Could not extract version number from updateDashboard response.\n";
                return false;
            }

            $statusResult = QuickSightHelper::waitForDashboardUpdateSuccess(
                client: $this->quickSight,
                awsAccountId: $this->awsAccountId,
                dashboardId: $dashboardId,           // already available in this scope
                versionNumber: $versionNumber
            );

            if (!$statusResult['success']) {
                echo "❌ Dashboard update failed due to errors:\n";
                QuickSightHelper::printDashboardCreationErrors($statusResult['errors']);
                return false;
            }

            QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'updateDashboardPublishedVersion',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'DashboardId' => $dashboardId,
                    'VersionNumber' => $statusResult['versionNumber']
                ]
            );

            echo "✅ Dashboard published version updated successfully.\n";
            echo "Dashboard ID: $dashboardId\n";
            echo "Dashboard ARN: " . $this->getAssetArn($dashboardId) . "\n";
            return true;
        } catch (AwsException $e) {
            echo "❌ Error updating dashboard: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Removes broken DataSetIdentifiers from the dashboard
     *
     * @param string $dashboardId The dashboard ID
     * @return bool True if successful, false otherwise
     */
    public function removeBrokenDataSetIdentifiers(string $dashboardId): bool
    {
        // Get dashboard definition
        $definition = $this->getAssetDefinition($dashboardId);
        if ($definition === null) {
            return false;
        }

        // Get valid DataSetIdentifiers from declarations
        $validDataSetIds = $this->extractValidDataSetIds($definition);

        echo "Valid DataSetIdentifiers: " . implode(', ', $validDataSetIds) . "\n";

        $modified = false;

        // Check for non-declared DataSetIdentifiers and remove them
        $potentiallyInvalidIds = $this->findAllDataSetIdentifiers($definition);

        foreach ($potentiallyInvalidIds as $datasetId) {
            if (!in_array($datasetId, $validDataSetIds)) {
                echo "Found invalid DataSetIdentifier: $datasetId\n";
                $wasModified = QuickSightHelper::cleanInvalidDataSetIdentifier($definition, $datasetId);
                if ($wasModified) {
                    $modified = true;
                }
            }
        }

        if (!$modified) {
            echo "No broken DataSetIdentifiers found.\n";
            return true;
        }

        // Get asset name
        $name = $this->getAssetName($dashboardId);

        // Update the asset with the cleaned definition
        return $this->updateAsset($dashboardId, $definition, $name);
    }

    /**
     * Find all DataSetIdentifiers used in the definition
     *
     * @param array $definition The dashboard definition
     * @return array Array of all DataSetIdentifier values
     */
    protected function findAllDataSetIdentifiers(array $definition): array
    {
        $identifiers = [];
        $this->recursivelyFindDataSetIdentifiers($definition, $identifiers);
        return array_unique($identifiers);
    }

    /**
     * Recursively search for all DataSetIdentifier values
     *
     * @param array $arr The array to search
     * @param array &$identifiers Array to collect found identifiers
     */
    protected function recursivelyFindDataSetIdentifiers(array $arr, array &$identifiers): void
    {
        foreach ($arr as $key => $value) {
            if ($key === 'DataSetIdentifier' && is_string($value)) {
                $identifiers[] = $value;
            } elseif (is_array($value)) {
                $this->recursivelyFindDataSetIdentifiers($value, $identifiers);
            }
        }
    }

    /**
     * Deploy a dashboard from a combined deployment config.
     *
     * The deployment config must include a "template" key that contains the full dashboard definition,
     * plus any overrides (Name, DashboardId, DestinationAwsAccountId, AwsRegion, DataSetIdentifierDeclarations, etc.).
     *
     * @param array $deploymentConfig
     * @return bool True on success, false otherwise.
     */
    public function deployDashboard(array $deploymentConfig): bool
    {
        if (!isset($deploymentConfig['template'])) {
            echo "Deployment config missing 'template' key.\n";
            return false;
        }

        $dashboard = $deploymentConfig['template'];
        $dashboard['Name'] = $deploymentConfig['Name'] . " (deployed)";
        $newDashboardId = $deploymentConfig['DashboardId'] ?? QuickSightHelper::generateUuid();

        // Handle DataSet Identifier Declarations
        if (isset($deploymentConfig['DataSetIdentifierDeclarations'])) {
            $originalDecls = $dashboard['Definition']['DataSetIdentifierDeclarations']
                ?? $dashboard['DataSetIdentifierDeclarations']
                ?? [];

            $dashboard['Definition']['DataSetIdentifierDeclarations'] =
                $deploymentConfig['DataSetIdentifierDeclarations'];
            $mapping = QuickSightHelper::buildIdentifierMappingFromOrder(
                $originalDecls,
                $deploymentConfig['DataSetIdentifierDeclarations']
            );
            QuickSightHelper::updateAllDataSetIdentifiers($dashboard, $mapping);
        }

        // Update dataset and perform string replacements
        QuickSightHelper::updateDataSetDeclarations($dashboard, $deploymentConfig['DestinationAwsAccountId']);

        if (!empty($deploymentConfig['StringReplacements'])) {
            QuickSightHelper::updateStringReplacements($dashboard, $deploymentConfig['StringReplacements']);
        }

        $dashboardParams = [
            'AwsAccountId' => $deploymentConfig['DestinationAwsAccountId'],
            'DashboardId' => $newDashboardId,
            'Name' => $dashboard['Name'],
            'Definition' => $dashboard['Definition'] ?? $dashboard,
        ];

        try {
            // Try to describe dashboard, update if exists
            $this->quickSight->describeDashboard([
                'AwsAccountId' => $deploymentConfig['DestinationAwsAccountId'],
                'DashboardId' => $newDashboardId,
            ]);

            $response = $this->quickSight->updateDashboard($dashboardParams);
            echo "Dashboard updated successfully with ID: $newDashboardId\n";
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $response = $this->quickSight->createDashboard($dashboardParams);
                echo "Dashboard created successfully with ID: $newDashboardId\n";
            } else {
                echo "Dashboard error: " . $e->getMessage() . "\n";
                return false;
            }
        }

        // Manage dashboard version and permissions
        $this->manageDashboardVersion($response, $deploymentConfig, $newDashboardId);
        $this->manageDashboardPermissions($deploymentConfig, $newDashboardId);
        $this->tagDashboard($deploymentConfig, $newDashboardId);

        return true;
    }

    private function manageDashboardVersion($response, $deploymentConfig, $newDashboardId)
    {
        $versionNumber = QuickSightHelper::extractVersionNumber($response);
        if ($versionNumber) {
            QuickSightHelper::updateDashboardPublishedVersion(
                $this->quickSight,
                $deploymentConfig['DestinationAwsAccountId'],
                $newDashboardId,
                $response
            );
        }
    }

    private function manageDashboardPermissions($deploymentConfig, $newDashboardId)
    {
        if (!empty($deploymentConfig['DefaultPermissions'])) {
            try {
                $this->quickSight->updateDashboardPermissions([
                    'AwsAccountId' => $deploymentConfig['DestinationAwsAccountId'],
                    'DashboardId' => $newDashboardId,
                    'GrantPermissions' => $deploymentConfig['DefaultPermissions'],
                ]);
                echo "Dashboard permissions updated.\n";
            } catch (AwsException $e) {
                echo "Permissions update error: " . $e->getMessage() . "\n";
            }
        }
    }

    private function tagDashboard($deploymentConfig, $newDashboardId)
    {
        if (!empty($deploymentConfig['Tags'])) {
            $tags = array_map(
                fn($key, $value) => ['Key' => $key, 'Value' => $value],
                array_keys($deploymentConfig['Tags']),
                $deploymentConfig['Tags']
            );

            $dashboardArn = sprintf(
                "arn:aws:quicksight:%s:%s:dashboard/%s",
                $deploymentConfig['AwsRegion'],
                $deploymentConfig['DestinationAwsAccountId'],
                $newDashboardId
            );

            try {
                $this->quickSight->tagResource([
                    'ResourceArn' => $dashboardArn,
                    'Tags' => $tags,
                ]);
                echo "Dashboard tagged successfully.\n";
            } catch (AwsException $e) {
                echo "Tagging error: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Export dashboard view counts based on CloudTrail events.
     *
     * @param CloudTrailClient $cloudTrailClient The CloudTrail client.
     * @param string $outputFile The CSV output file path.
     * @return bool True on success, false on failure.
     */
    public function exportDashboardViewCounts(CloudTrailClient $cloudTrailClient, string $outputFile): bool
    {
        $output = new ConsoleOutput();

        // Define time range and log it.
        $endTime = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
        $startTime = $endTime->sub(new \DateInterval("P90D"));
        $output->writeln(sprintf(
            "Looking up CloudTrail events from %s to %s",
            $startTime->format("Y-m-d H:i:s"),
            $endTime->format("Y-m-d H:i:s")
        ));

        // List dashboards.
        $dashboards = $this->listDashboardsForExport();
        $output->writeln("Found " . count($dashboards) . " dashboards.");

        // Create a progress bar for paging CloudTrail events.
        $progressBar = new ProgressBar($output);
        $progressBar->start();

        // Lookup CloudTrail events with a progress callback.
        $allEvents = CloudTrailHelper::lookupDashboardEvents(
            $cloudTrailClient,
            $startTime,
            $endTime,
            function ($page) use ($progressBar) {
                $progressBar->advance();
            }
        );
        $progressBar->finish();
        $output->writeln(""); // New line after progress bar.

        $output->writeln("Retrieved " . count($allEvents) . " events.");

        // Aggregate events by dashboard and user.
        $aggregated = $this->aggregateDashboardEvents($allEvents);

        // Build a map of dashboard IDs to dashboard summary.
        $dashMap = [];
        foreach ($dashboards as $dash) {
            if (isset($dash['DashboardId'])) {
                $dashMap[$dash['DashboardId']] = $dash;
            }
        }

        $reportRows = [];
        $deletedDashboards = 0;
        $deletedViews = 0;

        // Process dashboards that have view events.
        foreach ($aggregated as $dashId => $dashInfo) {
            $dashSummary = $dashMap[$dashId] ?? ['Name' => 'Not Listed / Unknown'];
            try {
                $detail = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'describeDashboard',
                    [
                        'AwsAccountId' => $this->awsAccountId,
                        'DashboardId' => $dashId,
                    ]
                );
                $dashboardDetail = $detail['Dashboard'] ?? [];
                $createdTime = $dashboardDetail['CreatedTime'] ?? null;
                $lastPublishedTime = $dashboardDetail['LastPublishedTime'] ?? null;
                $dashboardArn = $dashboardDetail['Arn'] ?? null;
                $dashboardTags = null;
                if ($dashboardArn) {
                    try {
                        $tagsResponse = QuickSightHelper::executeWithRetry(
                            $this->quickSight,
                            'listTagsForResource',
                            ['ResourceArn' => $dashboardArn]
                        );
                        $dashboardTags = $tagsResponse['Tags'] ?? null;
                    } catch (\Exception $ex) {
                        // Tag retrieval is optional.
                        $dashboardTags = null;
                    }
                }
            } catch (AwsException $ex) {
                // If the dashboard is deleted, skip adding it.
                if ($ex->getAwsErrorCode() === 'ResourceNotFoundException') {
                    $deletedDashboards++;
                    // Sum up the views for this dashboard across all users.
                    foreach ($dashInfo['users'] as $userStats) {
                        $deletedViews += $userStats['count'];
                    }
                    continue; // Skip processing this dashboard.
                } else {
                    // For other exceptions, log a warning and continue.
                    $output->writeln("Warning: Failed to get details for dashboard $dashId: " . $ex->getMessage());
                    continue;
                }
            }

            $dashName = $dashSummary['Name'] ?? 'Not Listed / Unknown';
            foreach ($dashInfo['users'] as $user => $stats) {
                $reportRows[] = [
                    'DashboardId' => $dashId,
                    'DashboardName' => $dashName,
                    'User' => $user,
                    'ViewCount' => $stats['count'],
                    'LastView' => $this->formatDatetime($stats['last_view']),
                    'CreatedTime' => $this->formatDatetime($createdTime),
                    'LastPublishedTime' => $this->formatDatetime($lastPublishedTime),
                    'Tags' => $dashboardTags ? json_encode($dashboardTags) : null,
                ];
            }
        }

        // Also add a blank row for every dashboard that exists but had no view events.
        foreach ($dashboards as $dash) {
            $dashId = $dash['DashboardId'];
            // Only include if we haven't already processed it in aggregated results.
            if (isset($aggregated[$dashId])) {
                continue;
            }
            try {
                $detail = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'describeDashboard',
                    [
                        'AwsAccountId' => $this->awsAccountId,
                        'DashboardId' => $dashId,
                    ]
                );
                $dashboardDetail = $detail['Dashboard'] ?? [];
                $createdTime = $dashboardDetail['CreatedTime'] ?? null;
                $lastPublishedTime = $dashboardDetail['LastPublishedTime'] ?? null;
                $dashboardArn = $dashboardDetail['Arn'] ?? null;
                $dashboardTags = null;
                if ($dashboardArn) {
                    try {
                        $tagsResponse = QuickSightHelper::executeWithRetry(
                            $this->quickSight,
                            'listTagsForResource',
                            ['ResourceArn' => $dashboardArn]
                        );
                        $dashboardTags = $tagsResponse['Tags'] ?? null;
                    } catch (\Exception $ex) {
                        $dashboardTags = null;
                    }
                }
            } catch (\Exception $ex) {
                continue; // Skip if details cannot be fetched.
            }
            $reportRows[] = [
                'DashboardId' => $dashId,
                'DashboardName' => $dash['Name'] ?? 'Not Listed / Unknown',
                'User' => null,
                'ViewCount' => null,
                'LastView' => null,
                'CreatedTime' => $this->formatDatetime($createdTime),
                'LastPublishedTime' => $this->formatDatetime($lastPublishedTime),
                'Tags' => $dashboardTags ? json_encode($dashboardTags) : null,
            ];
        }

        // Attempt CSV export.
        try {
            if (!$this->exportToCsv($reportRows, $outputFile)) {
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

        // If there were any deleted dashboards, output a summary.
        if ($deletedDashboards > 0) {
            $output->writeln(sprintf(
                "<comment>Skipped %d deleted dashboards with a total of %d view(s) " .
                "that were not included in the export.</comment>",
                $deletedDashboards,
                $deletedViews
            ));
        }

        return true;
    }

    /**
     * Aggregate dashboard events by dashboard ID and user.
     *
     * @param array $events The CloudTrail events.
     * @return array The aggregated view counts.
     */
    private function aggregateDashboardEvents(array $events): array
    {
        $aggregated = [];
        foreach ($events as $event) {
            if (!isset($event['EventTime'])) {
                continue;
            }
            $eventTime = $event['EventTime'];
            $rawCtEvent = $event['CloudTrailEvent'] ?? '{}';
            $ctEvent = json_decode($rawCtEvent, true);
            if (!$ctEvent) {
                continue;
            }
            $userArn = $ctEvent['requestParameters']['userArn'] ?? null;
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
     * List all dashboards using pagination.
     *
     * @return array
     */
    private function listDashboardsForExport(): array
    {
        $dashboards = [];
        $nextToken = null;
        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }
            $response = QuickSightHelper::executeWithRetry($this->quickSight, 'listDashboards', $params);
            if (isset($response['DashboardSummaryList'])) {
                $dashboards = array_merge($dashboards, $response['DashboardSummaryList']);
            }
            $nextToken = $response['NextToken'] ?? null;
        } while ($nextToken);
        return $dashboards;
    }

    /**
     * Format a datetime value to 'Y-m-d H:i:s'. Accepts a DateTimeInterface object or a string.
     *
     * @param mixed $dt
     * @return string|null
     */
    private function formatDatetime($dt): ?string
    {
        if (!$dt) {
            return null;
        }

        $utc = new \DateTimeZone("UTC");

        if ($dt instanceof \DateTimeImmutable) {
            // DateTimeImmutable returns a new instance.
            return $dt->setTimezone($utc)->format("Y-m-d H:i:s");
        }

        if ($dt instanceof \DateTime) {
            // DateTime modifies the instance in place.
            $dt->setTimezone($utc);
            return $dt->format("Y-m-d H:i:s");
        }

        // Fallback for string dates.
        $timestamp = strtotime($dt);
        return $timestamp ? gmdate("Y-m-d H:i:s", $timestamp) : $dt;
    }

    /**
     * Export the given rows to a CSV file.
     *
     * @param array $rows
     * @param string $filename
     * @return bool
     */
    private function exportToCsv(array $rows, string $filename): bool
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
        // Write CSV header.
        fputcsv($fh, $fieldnames, ',', '"', '\\');
        // Write each row.
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
