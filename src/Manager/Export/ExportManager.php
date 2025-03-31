<?php

namespace QSAssetManager\Manager\Export;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\TaggingHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class ExportManager
{
    private $config;
    private $quickSight;
    private $awsAccountId;
    private $awsRegion;
    private $io;
    private $tagKey;
    private $baseDir;

    /**
     * Constructor
     *
     * @param array $config Global configuration
     * @param QuickSightClient $quickSight QuickSight client
     * @param string $awsAccountId AWS Account ID
     * @param string $awsRegion AWS Region
     * @param SymfonyStyle|null $io Console output interface
     */
    public function __construct(
        array $config,
        QuickSightClient $quickSight,
        string $awsAccountId,
        string $awsRegion,
        ?SymfonyStyle $io = null
    ) {
        $this->config = $config;
        $this->quickSight = $quickSight;
        $this->awsAccountId = $awsAccountId;
        $this->awsRegion = $awsRegion;
        $this->io = $io;

        // Load tag key from config; default to 'group' if not set.
        $this->tagKey = $config['tagging']['default_key'] ?? 'group';

        // Load base directory for exports.
        // For example, if set in config as "quicksight-asset-exports"
        $this->baseDir = $config['paths']['export_base_path'] ?? 'quicksight-asset-exports';
        $this->baseDir = rtrim($this->baseDir, '/') . '/';
    }

    /**
     * Determine output directory for exported assets based on group tag.
     *
     * @param string $groupTag The tag value from the resource.
     * @param string $assetType The asset type (e.g. dashboards, datasets).
     * @return string Output directory path.
     */
    private function getOutputDirectory(string $groupTag, string $assetType): string
    {
        // Load groups configuration if available.
        $groups           = [];
        $groupsConfigPath = $this->config['tagging']['groups_config_file'] ?? null;
        if ($groupsConfigPath && file_exists($groupsConfigPath)) {
            $groupsConfig = require $groupsConfigPath;
            $groups       = $groupsConfig['groups'] ?? [];
        }

        $matchedKey   = $groupTag; // Fallback if no match is found.
        $matchedConfig = null;
        foreach ($groups as $key => $config) {
            if (isset($config['aliases']) && is_array($config['aliases'])) {
                foreach ($config['aliases'] as $alias) {
                    if (strcasecmp($groupTag, $alias) === 0) {
                        $matchedKey   = $key;
                        $matchedConfig = $config;
                        break 2;
                    }
                }
            }
        }

        // Determine group and subgroup.
        $subgroup  = '';
        if ($matchedConfig && !empty($matchedConfig['parent'])) {
            $parent    = $matchedConfig['parent'];
            $prefix    = $parent . '-';
            $groupName = $parent;
            if (stripos($matchedKey, $prefix) === 0) {
                $subgroup = substr($matchedKey, strlen($prefix));
            }
        } else {
            $groupName = $matchedKey;
        }

        // Start with the baseDir template.
        $path = $this->baseDir;
        // Replace {group} if present.
        if (strpos($path, '{group}') !== false) {
            $path = str_replace('{group}', $groupName, $path);
        } else {
            $path = rtrim($path, '/') . '/' . $groupName;
        }
        // Replace {subgroup} if present.
        if (strpos($path, '{subgroup}') !== false) {
            $path = str_replace('{subgroup}', $subgroup, $path);
        } elseif (!empty($subgroup)) {
            $path = rtrim($path, '/') . '/' . $subgroup;
        }
        return rtrim($path, '/') . '/' . $assetType;
    }

    /**
     * Returns the resolved base directory by stripping out any placeholders.
     */
    private function getResolvedBaseDir(): string
    {
        $template = $this->baseDir;
        if (preg_match('/\{[^}]+\}/', $template, $matches, PREG_OFFSET_CAPTURE)) {
            return rtrim(substr($template, 0, $matches[0][1]), '/');
        }
        return rtrim($template, '/');
    }

    /**
     * Checks if a file already exists in any directory and moves it if the tag has changed.
     *
     * @param string $id The asset ID.
     * @param string $newOutputDir The new output directory.
     * @param string $newGroupTag The new group tag.
     * @param string $type The asset type ('dashboards' or 'datasets').
     * @return string The path to the file (existing or new).
     */
    private function handleExistingFile(
        string $id,
        string $newOutputDir,
        string $newGroupTag,
        string $type
    ): string {
        $filePath = "$newOutputDir/$id.json";
        if (file_exists($filePath)) {
            return $filePath;
        }

        // Use the resolved base directory (without placeholders) for iteration.
        $baseDir  = $this->getResolvedBaseDir();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $baseDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === "$id.json") {
                $oldPath = $file->getPathname();
                $oldDir  = dirname($oldPath);
                if ($oldDir !== $newOutputDir) {
                    if (!is_dir($newOutputDir)) {
                        mkdir($newOutputDir, 0777, true);
                    }
                    if (rename($oldPath, $filePath)) {
                        $this->outputMessage(
                            message: "Moved $type $id from $oldDir to $newOutputDir due to tag change",
                            type: 'warning'
                        );
                    } else {
                        $this->outputMessage(
                            message: "Failed to move $type $id from $oldDir to $newOutputDir",
                            type: 'error'
                        );
                    }
                }
                return $filePath;
            }
        }
        return $filePath;
    }

    /**
     * Checks if a dashboard needs to be exported by comparing its last update time
     * with the existing export file (if any).
     *
     * @param string $dashboardId Dashboard ID to check.
     * @param array $dashboardDetails Dashboard details from the API.
     * @param string $exportPath Path to the export file.
     * @return bool True if the dashboard should be exported, false otherwise.
     */
    private function shouldExportDashboard(string $dashboardId, array $dashboardDetails, string $exportPath): bool
    {
        // If file doesn't exist, we should export
        if (!file_exists($exportPath)) {
            return true;
        }

        $lastUpdatedTime = $dashboardDetails['LastUpdatedTime'] ?? null;
        $lastPublishedTime = $dashboardDetails['LastPublishedTime'] ?? null;
        $dashboardName = $dashboardDetails['Name'] ?? 'Unknown';

        // If no timestamps available, export to be safe
        if (!$lastUpdatedTime && !$lastPublishedTime) {
            return true;
        }

        try {
            // Read the existing export file
            $existingData = json_decode(file_get_contents($exportPath), true);
            $exportedTimestamp = $existingData['@metadata']['headers']['date'] ?? null;

            if (!$exportedTimestamp) {
                return true;
            }

            // Convert timestamps to DateTime objects for comparison
            $exportedTime = new \DateTime($exportedTimestamp);
            $lastUpdateTime = $lastUpdatedTime ? new \DateTime($lastUpdatedTime) : null;
            $lastPublishTime = $lastPublishedTime ? new \DateTime($lastPublishedTime) : null;

            // Get the most recent update time
            $mostRecentTime = 0;
            if ($lastUpdateTime) {
                $mostRecentTime = $lastUpdateTime->getTimestamp();
            }
            if ($lastPublishTime && $lastPublishTime->getTimestamp() > $mostRecentTime) {
                $mostRecentTime = $lastPublishTime->getTimestamp();
            }

            // If dashboard was updated after the export, we should export again
            if ($mostRecentTime > $exportedTime->getTimestamp()) {
                return true;
            }

            $this->outputMessage(
                message: "Skipping dashboard $dashboardId ($dashboardName) - no changes since last export",
                type: 'note'
            );
            return false;
        } catch (\Exception $e) {
            $this->outputMessage("Error checking dashboard update times: " . $e->getMessage(), 'warning');
            return true; // Export to be safe
        }
    }

    /**
     * Checks if a dataset needs to be exported by comparing its last update time
     * with the existing export file and checking if only ConsumedSpiceCapacityInBytes changed.
     *
     * @param string $datasetId Dataset ID to check.
     * @param array $datasetDetails Dataset details from the API.
     * @param string $exportPath Path to the export file.
     * @return bool True if the dataset should be exported, false otherwise.
     */
    private function shouldExportDataset(string $datasetId, array $datasetDetails, string $exportPath): bool
    {
        // If file doesn't exist, we should export
        if (!file_exists($exportPath)) {
            return true;
        }

        $lastUpdatedTime = $datasetDetails['LastUpdatedTime'] ?? null;
        $datasetName = $datasetDetails['Name'] ?? 'Unknown';

        // If no timestamp available, export to be safe
        if (!$lastUpdatedTime) {
            return true;
        }

        try {
            // Read the existing export file
            $existingData = json_decode(file_get_contents($exportPath), true);
            $exportedTimestamp = $existingData['@metadata']['headers']['date'] ?? null;
            $oldDatasetDetails = $existingData['DataSet'] ?? null;

            if (!$exportedTimestamp || !$oldDatasetDetails) {
                return true;
            }

            // Convert timestamps to DateTime objects for comparison
            $exportedTime = new \DateTime($exportedTimestamp);
            $lastUpdateTime = new \DateTime($lastUpdatedTime);

            // If dataset was not updated after the export, no need to export
            if ($lastUpdateTime->getTimestamp() <= $exportedTime->getTimestamp()) {
                $this->outputMessage(
                    message: "Skipping dataset $datasetId ($datasetName) - no changes since last export",
                    type: 'note'
                );
                return false;
            }

            // Check if only ConsumedSpiceCapacityInBytes changed
            if (
                isset(
                    $oldDatasetDetails['ConsumedSpiceCapacityInBytes'],
                    $datasetDetails['ConsumedSpiceCapacityInBytes']
                )
            ) {
                // Create deep copies of the arrays to compare other fields
                $oldDetailsClone = json_decode(json_encode($oldDatasetDetails), true);
                $newDetailsClone = json_decode(json_encode($datasetDetails), true);

                // Remove fields that would naturally be different or that we don't care about
                $fieldsToIgnore = [
                    'ConsumedSpiceCapacityInBytes',
                    'LastUpdatedTime',
                    'CreatedTime',
                    'LastModifiedTime',
                    'ModifiedBy',
                    'OutputColumns' // These can sometimes change in format but not content
                ];

                foreach ($fieldsToIgnore as $field) {
                    unset($oldDetailsClone[$field]);
                    unset($newDetailsClone[$field]);
                }

                // Also unset any nested timestamps
                $this->removeNestedTimestamps($oldDetailsClone);
                $this->removeNestedTimestamps($newDetailsClone);

                // Normalize both arrays for consistent comparison
                $oldJson = json_encode($this->normalizeArray($oldDetailsClone));
                $newJson = json_encode($this->normalizeArray($newDetailsClone));

                // If everything else is the same, only excluded fields changed
                if ($oldJson === $newJson) {
                    $this->outputMessage(
                        message: "Skipping dataset $datasetId ($datasetName) - only ConsumedSpiceCapacityInBytes 
                            or timestamps changed",
                        type: 'note'
                    );
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->outputMessage("Error checking dataset update times: " . $e->getMessage(), 'warning');
            return true; // Export to be safe
        }
    }

    /**
     * Recursively remove timestamp fields from nested arrays
     *
     * @param array &$array Array to process
     */
    private function removeNestedTimestamps(array &$array): void
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->removeNestedTimestamps($value);
            } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
                // This looks like a timestamp, remove it
                unset($array[$key]);
            }
        }
    }

    /**
     * Normalize an array for consistent comparison
     *
     * @param array $array Array to normalize
     * @return array Normalized array
     */
    private function normalizeArray(array $array): array
    {
        // Sort array by keys recursively
        ksort($array);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->normalizeArray($value);
            }
        }

        return $array;
    }

    /**
     * Export a single dashboard.
     *
     * @param string $dashboardId Dashboard ID to export.
     * @param bool $forceExport Whether to force export regardless of update status.
     * @return bool Export success status.
     */
    public function exportDashboard(string $dashboardId, bool $forceExport = false): bool
    {
        try {
            // Fetch dashboard details.
            $dashboardResponse = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'describeDashboard',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'DashboardId' => $dashboardId
                ]
            );

            // Validate dashboard details.
            if (!isset($dashboardResponse['Dashboard'])) {
                $this->outputMessage("No dashboard details found for ID: $dashboardId", 'error');
                return false;
            }

            $dashboardDetails = $dashboardResponse['Dashboard'];
            $dashboardName = $dashboardDetails['Name'] ?? 'Unknown';
            $arn = $dashboardDetails['Arn'] ?? null;

            if (!$arn) {
                $this->outputMessage("No ARN found for dashboard: $dashboardId ($dashboardName)", 'error');
                return false;
            }

            // Get tags and determine the group tag.
            $tags = TaggingHelper::getResourceTags($this->quickSight, $arn);
            $groupTag = TaggingHelper::getGroupTag($tags, $this->tagKey);

            if (!$groupTag) {
                $this->outputMessage("No group tag found for dashboard: $dashboardId ($dashboardName)", 'warning');
                return false;
            }

            // Determine output directory.
            $outputDir = $this->getOutputDirectory($groupTag, 'dashboards');

            // Ensure output directory exists.
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            // Check if file exists elsewhere (moved due to tag change)
            $exportPath = $this->handleExistingFile($dashboardId, $outputDir, $groupTag, 'dashboards');

            // Check if we need to export based on update times (unless forced)
            if (!$forceExport && !$this->shouldExportDashboard($dashboardId, $dashboardDetails, $exportPath)) {
                return true; // Skip export but return true as this is not an error
            }

            // Get dashboard definition.
            $definitionResponse = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'describeDashboardDefinition',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'DashboardId' => $dashboardId
                ]
            );

            // Write dashboard definition to file.
            $exportData = $definitionResponse->toArray();
            $jsonOutput = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $fileSize = strlen($jsonOutput) / 1024; // Size in KB

            if (file_put_contents($exportPath, $jsonOutput) !== false) {
                $this->outputMessage(
                    sprintf(
                        "Exported dashboard %s (%s) to %s (%.2f KB)",
                        $dashboardId,
                        $dashboardName,
                        $exportPath,
                        $fileSize
                    ),
                    'success'
                );
                return true;
            } else {
                $this->outputMessage("Failed to export dashboard $dashboardId ($dashboardName)", 'error');
                return false;
            }
        } catch (AwsException $e) {
            $this->outputMessage("Error exporting dashboard $dashboardId: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Export a single dataset.
     *
     * @param string $datasetId Dataset ID to export.
     * @param bool $forceExport Whether to force export regardless of update status.
     * @return bool Export success status.
     */
    public function exportDataset(string $datasetId, bool $forceExport = false): bool
    {
        try {
            // Fetch dataset details.
            try {
                $datasetResponse = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'describeDataSet',
                    [
                        'AwsAccountId' => $this->awsAccountId,
                        'DataSetId' => $datasetId
                    ]
                );
            } catch (AwsException $e) {
                $errorMessage = $e->getAwsErrorMessage() ?: $e->getMessage();

                // Handle unsupported dataset types gracefully
                if (strpos($errorMessage, 'The data set type is not supported through API yet') !== false) {
                    $this->outputMessage(
                        message: "Skipping dataset $datasetId - flat file dataset not supported by API",
                        type: 'warning'
                    );
                    return false;
                }

                throw $e; // Re-throw other exceptions
            }

            // Validate dataset details.
            if (!isset($datasetResponse['DataSet'])) {
                $this->outputMessage("No dataset details found for ID: $datasetId", 'error');
                return false;
            }

            $datasetDetails = $datasetResponse['DataSet'];
            $datasetName = $datasetDetails['Name'] ?? 'Unknown';
            $arn = $datasetDetails['Arn'] ?? null;

            if (!$arn) {
                $this->outputMessage("No ARN found for dataset: $datasetId ($datasetName)", 'error');
                return false;
            }

            // Get tags and determine the group tag.
            $tags = TaggingHelper::getResourceTags($this->quickSight, $arn);
            $groupTag = TaggingHelper::getGroupTag($tags, $this->tagKey);

            if (!$groupTag) {
                $this->outputMessage("No group tag found for dataset: $datasetId ($datasetName)", 'warning');
                return false;
            }

            // Determine output directory.
            $outputDir = $this->getOutputDirectory($groupTag, 'datasets');

            // Ensure output directory exists.
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            // Check if file exists elsewhere (moved due to tag change)
            $exportPath = $this->handleExistingFile($datasetId, $outputDir, $groupTag, 'datasets');

            // Check if we need to export based on update times (unless forced)
            if (!$forceExport && !$this->shouldExportDataset($datasetId, $datasetDetails, $exportPath)) {
                return true; // Skip export but return true as this is not an error
            }

            // Collect export data.
            $exportData = [
                'DataSet' => $datasetDetails,
            ];

            // For SPICE datasets, get refresh properties.
            if (isset($datasetDetails['ImportMode']) && strtoupper($datasetDetails['ImportMode']) === 'SPICE') {
                try {
                    $refreshPropsResponse = QuickSightHelper::executeWithRetry(
                        $this->quickSight,
                        'describeDataSetRefreshProperties',
                        [
                            'AwsAccountId' => $this->awsAccountId,
                            'DataSetId' => $datasetId
                        ]
                    );
                    $exportData['DataSetRefreshProperties'] = $refreshPropsResponse['DataSetRefreshProperties'] ?? null;
                } catch (AwsException $e) {
                    // Check if this is because properties aren't set (not an error)
                    $errorMessage = $e->getAwsErrorMessage() ?: $e->getMessage();
                    if (strpos($errorMessage, 'Dataset refresh properties are not set') !== false) {
                        $this->outputMessage(
                            "No incremental refresh configuration for dataset $datasetId ($datasetName)",
                            'info'
                        );
                    } else {
                        $this->outputMessage(
                            message: "Could not retrieve refresh properties for dataset $datasetId ($datasetName): " .
                            $e->getMessage(),
                            type: 'warning'
                        );
                    }
                }

                // Get refresh schedules.
                try {
                    $schedulesResponse = QuickSightHelper::executeWithRetry(
                        $this->quickSight,
                        'listRefreshSchedules',
                        [
                            'AwsAccountId' => $this->awsAccountId,
                            'DataSetId' => $datasetId
                        ]
                    );
                    $exportData['RefreshSchedules'] = $schedulesResponse['RefreshSchedules'] ?? [];
                } catch (AwsException $e) {
                    $this->outputMessage(
                        message: "Could not retrieve refresh schedules for dataset $datasetId ($datasetName): " .
                        $e->getMessage(),
                        type: 'warning'
                    );
                }
            }

            // Add pseudo metadata with current timestamp for future comparisons
            if (!isset($exportData['@metadata'])) {
                $exportData['@metadata'] = [
                    'headers' => [
                        'date' => gmdate('D, d M Y H:i:s') . ' GMT' // Format like: "Tue, 01 Apr 2025 19:19:38 GMT"
                    ]
                ];
            }

            // Write dataset details to file.
            $jsonOutput = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $fileSize = strlen($jsonOutput) / 1024; // Size in KB

            if (file_put_contents($exportPath, $jsonOutput) !== false) {
                $this->outputMessage(
                    sprintf(
                        "Exported dataset %s (%s) to %s (%.2f KB)",
                        $datasetId,
                        $datasetName,
                        $exportPath,
                        $fileSize
                    ),
                    'success'
                );
                return true;
            } else {
                $this->outputMessage("Failed to export dataset $datasetId ($datasetName)", 'error');
                return false;
            }
        } catch (AwsException $e) {
            $this->outputMessage("Error exporting dataset $datasetId: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Export all dashboards.
     *
     * @param bool $forceExport Whether to force export regardless of update status.
     * @return array List of exported dashboard IDs.
     */
    public function exportAllDashboards(bool $forceExport = false): array
    {
        $exportedDashboards = [];
        $nextToken = null;
        $count = 0;
        $totalCount = 0;

        // First, count total dashboards for progress reporting
        try {
            $params = ['AwsAccountId' => $this->awsAccountId];
            $countResponse = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'listDashboards',
                $params
            );
            $totalCount = count($countResponse['DashboardSummaryList']);

            // If there's a next token, we have more
            if (isset($countResponse['NextToken'])) {
                // Add some buffer for approximate count (we'll just say "X+" in the output)
                $totalCount .= '+';
            }
        } catch (AwsException $e) {
            // Just continue without the total count
        }

        $this->outputMessage("Starting export of dashboards (total: $totalCount)...", 'info');

        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            try {
                $dashboardsResponse = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listDashboards',
                    $params
                );

                foreach ($dashboardsResponse['DashboardSummaryList'] as $dashboard) {
                    $count++;
                    $dashboardId = $dashboard['DashboardId'];
                    $dashboardName = $dashboard['Name'] ?? 'Unknown';

                    $this->outputMessage(
                        message: "Processing dashboard $count/$totalCount: $dashboardId ($dashboardName)",
                        type: 'info'
                    );

                    if ($this->exportDashboard($dashboardId, $forceExport)) {
                        $exportedDashboards[] = $dashboardId;
                    }
                }

                $nextToken = $dashboardsResponse['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->outputMessage("Error listing dashboards: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);

        $this->outputMessage("Dashboard export complete. Processed $count dashboards.", 'success');
        return $exportedDashboards;
    }

    /**
     * Export all datasets.
     *
     * @param bool $forceExport Whether to force export regardless of update status.
     * @return array List of exported dataset IDs.
     */
    public function exportAllDatasets(bool $forceExport = false): array
    {
        $exportedDatasets = [];
        $nextToken = null;
        $count = 0;
        $totalCount = 0;

        // First, count total datasets for progress reporting
        try {
            $params = ['AwsAccountId' => $this->awsAccountId];
            $countResponse = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'listDataSets',
                $params
            );
            $totalCount = count($countResponse['DataSetSummaries']);

            // If there's a next token, we have more
            if (isset($countResponse['NextToken'])) {
                // Add some buffer for approximate count (we'll just say "X+" in the output)
                $totalCount .= '+';
            }
        } catch (AwsException $e) {
            // Just continue without the total count
        }

        $this->outputMessage("Starting export of datasets (total: $totalCount)...", 'info');

        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            try {
                $datasetsResponse = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listDataSets',
                    $params
                );

                foreach ($datasetsResponse['DataSetSummaries'] as $dataset) {
                    $count++;
                    $datasetId = $dataset['DataSetId'];
                    $datasetName = $dataset['Name'] ?? 'Unknown';

                    $this->outputMessage("Processing dataset $count/$totalCount: $datasetId ($datasetName)", 'info');

                    if ($this->exportDataset($datasetId, $forceExport)) {
                        $exportedDatasets[] = $datasetId;
                    }
                }

                $nextToken = $datasetsResponse['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->outputMessage("Error listing datasets: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);

        $this->outputMessage("Dataset export complete. Processed $count datasets.", 'success');
        return $exportedDatasets;
    }

    /**
     * Export all supported QuickSight asset types (dashboards, datasets, etc.).
     *
     * This method handles:
     *   - Exporting all assets of each type (currently dashboards and datasets)
     *   - Optionally performing cleanup of stale assets
     *   - Displaying a summary grouped by asset type
     *
     * @param bool $forceExport    Force export even if assets are up-to-date.
     * @param bool $performCleanup Whether to clean up stale assets after export.
     *
     * @return array<string, array> Array of exported asset IDs grouped by asset type.
     */
    public function exportAll(
        bool $forceExport = false,
        bool $performCleanup = true
    ): array {
        $this->outputMessage("Starting full export of QuickSight assets...", 'info');

        $results = [
            'dashboards' => $this->exportAllDashboards(forceExport: $forceExport),
            'datasets' => $this->exportAllDatasets(forceExport: $forceExport),
        ];

        if ($performCleanup) {
            foreach ($results as $assetType => $exportedIds) {
                $this->cleanupAssets(
                    assetType: $assetType,
                    validIds: $exportedIds
                );
            }
        }

        $this->displayExportSummary($results);

        return $results;
    }

    /**
     * Cleanup stale assets.
     *
     * @param string $assetType Type of asset to clean up.
     * @param array $validIds Valid asset IDs.
     */
    public function cleanupAssets(string $assetType, array $validIds): void
    {
        $this->outputMessage("Running cleanup scan for $assetType...", 'info');

        $staleFound = false;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            // Only process JSON files in asset type directories.
            // Skip files in 'archived' directories using regex
            if (
                $file->isFile()
                && $file->getExtension() === 'json'
                && strpos($file->getPath(), "/{$assetType}") !== false
                && !preg_match('#/archived($|/)#', $file->getPath())
            ) {
                $assetId = basename($file->getFilename(), '.json');

                // If the asset ID is not in the valid list, handle as a stale asset.
                if (!in_array($assetId, $validIds)) {
                    $this->handleStaleAsset($file);
                    $staleFound = true;
                }
            }
        }

        if (!$staleFound) {
            $this->outputMessage("No stale assets found for $assetType.", 'info');
        }
    }

    /**
     * Handle a stale asset file.
     *
     * @param \SplFileInfo $file File to handle.
     */
    private function handleStaleAsset(\SplFileInfo $file): void
    {
        $this->outputMessage("Stale asset detected: {$file->getPathname()}", 'warning');

        if ($this->io) {
            $action = $this->io->choice(
                'What would you like to do?',
                ['Archive', 'Delete', 'Skip'],
                'Skip'
            );
        } else {
            // Console input fallback.
            echo "Choose action for {$file->getPathname()} (a: Archive, d: Delete, s: Skip): ";
            $input = strtolower(trim(fgets(STDIN)));
            $action = match ($input) {
                'a' => 'Archive',
                'd' => 'Delete',
                default => 'Skip'
            };
        }

        // Support auto-archive mode for automated scripts
        if (isset($this->config['cleanup']['auto_archive']) && $this->config['cleanup']['auto_archive'] === true) {
            $action = 'Archive';
            $this->outputMessage("Auto-archive mode enabled, archiving stale asset", 'info');
        }

        switch ($action) {
            case 'Archive':
                $this->archiveAsset($file);
                break;
            case 'Delete':
                $this->deleteAsset($file);
                break;
            default:
                $this->outputMessage("Skipped asset: {$file->getPathname()}", 'info');
        }
    }

    /**
     * Archive a stale asset.
     *
     * @param \SplFileInfo $file File to archive.
     */
    private function archiveAsset(\SplFileInfo $file): void
    {
        // Ensure we always archive to the immediate parent /archived
        $parentDir   = dirname($file->getPath());
        $archiveDir  = $parentDir . DIRECTORY_SEPARATOR . 'archived';

        // Create archive directory if it doesn't exist
        if (!is_dir(filename: $archiveDir)) {
            mkdir(directory: $archiveDir, permissions: 0777, recursive: true);
        }

        $archivePath = $archiveDir . DIRECTORY_SEPARATOR . $file->getFilename();

        try {
            if (rename(from: $file->getPathname(), to: $archivePath)) {
                $this->outputMessage(message: "Archived: {$file->getPathname()} → $archivePath", type: 'success');
            } else {
                $this->outputMessage(message: "Failed to archive: {$file->getPathname()}", type: 'error');
            }
        } catch (\Exception $e) {
            $this->outputMessage(message: "Error archiving: " . $e->getMessage(), type: 'error');
        }
    }

    /**
     * Delete a stale asset.
     *
     * @param \SplFileInfo $file File to delete.
     */
    private function deleteAsset(\SplFileInfo $file): void
    {
        try {
            if (unlink($file->getPathname())) {
                $this->outputMessage("Deleted: {$file->getPathname()}", 'success');
            } else {
                $this->outputMessage("Failed to delete: {$file->getPathname()}", 'error');
            }
        } catch (\Exception $e) {
            $this->outputMessage("Error deleting: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Displays a summary of export counts by group and subgroup.
     *
     * @param array $exportResults Results from exportAll()
     */
    public function displayExportSummary(array $exportResults): void
    {
        $groupCounts = [];

        // Build group → subgroup → counts map
        foreach ($exportResults as $assetType => $ids) {
            foreach ($ids as $id) {
                $groupTag = $this->getAssetGroupTag(assetType: $assetType, assetId: $id);

                if (!$groupTag) {
                    continue;
                }

                // Split group/subgroup if present
                if (str_contains(haystack: $groupTag, needle: '-')) {
                    [$group, $subgroup] = explode('-', $groupTag, 2);
                } else {
                    $group = $groupTag;
                    $subgroup = null;
                }

                if (!isset($groupCounts[$group])) {
                    $groupCounts[$group] = [];
                }

                $key = $subgroup ?? '_root';
                if (!isset($groupCounts[$group][$key])) {
                    $groupCounts[$group][$key] = ['dashboards' => 0, 'datasets' => 0];
                }

                $groupCounts[$group][$key][$assetType] += 1;
            }
        }

        if (empty($groupCounts)) {
            $this->outputMessage(message: "No assets exported.", type: 'note');
            return;
        }

        // ────────────────────────────────────────────────────────────────
        // Output nicely grouped section
        // ────────────────────────────────────────────────────────────────

        $this->io->section('Export Summary by Group');

        foreach ($groupCounts as $group => $subgroups) {
            $this->io->writeln("<info>$group</info>");

            // Prepare rows
            $rows = [];
            foreach ($subgroups as $subgroup => $counts) {
                $name = ($subgroup === '_root') ? '(root)' : $subgroup;

                $rows[] = [
                    $name,
                    $counts['dashboards'] ?? 0,
                    $counts['datasets'] ?? 0,
                ];
            }

            $this->io->table(
                ['Subgroup', 'Dashboards', 'Datasets'],
                $rows
            );
        }

        $this->io->success('Export complete.');
    }

    /**
     * Get the group tag for an asset from its export file
     *
     * @param string $assetType Asset type (dashboards or datasets)
     * @param string $assetId Asset ID
     * @return string|null Group tag if found, null otherwise
     */
    private function getAssetGroupTag(string $assetType, string $assetId): ?string
    {
        $base     = $this->getResolvedBaseDir();
        $template = $this->baseDir; // e.g.: "config/{group}/quicksight-asset-exports"
        $pattern  = preg_quote($template, '#');

        if (strpos($template, '{group}') !== false) {
            $pattern = str_replace('\{group\}', '([^/]+)', $pattern);
        } else {
            $pattern .= '([^/]+)/';
        }
        if (strpos($template, '{subgroup}') !== false) {
            $pattern = str_replace('\{subgroup\}', '([^/]+)', $pattern);
        } else {
            $pattern .= '([^/]+)/';
        }
        $pattern .= preg_quote("/$assetType/", '#');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $base,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === "$assetId.json") {
                $path = $file->getPath();
                if (preg_match("#^" . $pattern . "#", $path, $matches)) {
                    if (isset($matches[2]) && $matches[2] !== '') {
                        return $matches[1] . '-' . $matches[2];
                    }
                    return $matches[1];
                }
            }
        }
        return null;
    }

    /**
     * Output messages with optional styling.
     *
     * @param string $message Message to output.
     * @param string $type Message type (info, success, warning, error, note).
     */
    private function outputMessage(string $message, string $type = 'info'): void
    {
        $types = [
            'error' => ['style' => 'error', 'color' => "\033[31m"],      // Red text
            'warning' => ['style' => 'warning', 'color' => "\033[33m"],      // Yellow text
            'success' => ['style' => 'success', 'color' => "\033[32m"],      // Green text
            'note' => ['style' => 'block', 'color' => "\033[46;30m"],   // Cyan background with black text
            'info' => ['style' => 'info', 'color' => "\033[0m"],
        ];

        $typeConfig = $types[$type] ?? $types['info'];

        if ($this->io) {
            // Use the correct SymfonyStyle method
            if ($type === 'note') {
                // Special handling for note to use block with cyan background
                $this->io->block($message, null, 'fg=black;bg=cyan', ' ', true);
            } elseif (method_exists($this->io, $typeConfig['style'])) {
                $this->io->{$typeConfig['style']}($message);
            } else {
                $this->io->text($message);
            }
        } else {
            // Fallback plain console output
            echo $typeConfig['color'] . $message . "\033[0m\n";
        }
    }
}
