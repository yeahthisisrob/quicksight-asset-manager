<?php

namespace QSAssetManager\Manager\Tagging;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\TaggingHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class TaggingManager
{
    protected $config;
    protected $quickSight;
    protected $awsAccountId;
    protected $awsRegion;
    protected $groups;
    protected $emailDomains;
    protected $io;
    protected $tagKey;

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

        // Load groups configuration
        $groupsConfigPath = $config['tagging']['groups_config_file'] ?? null;
        if ($groupsConfigPath && file_exists($groupsConfigPath)) {
            $groupsConfig       = require $groupsConfigPath;
            $this->groups       = $groupsConfig['groups']        ?? [];
            $this->emailDomains = $groupsConfig['email_domains'] ?? [];
            $this->tagKey       = $groupsConfig['default_key']   ?? 'group'; // Load the tag key with default
        } else {
            $this->groups       = [];
            $this->emailDomains = [];
            $this->tagKey       = 'group'; // Default if no config
        }

        $this->io = $io;
    }

    /**
     * Output a message with or without SymfonyStyle
     */
    protected function output(string $message, string $type = 'info'): void
    {
        if ($this->io) {
            switch ($type) {
                case 'error':
                    $this->io->error($message);
                    break;
                case 'warning':
                    $this->io->warning($message);
                    break;
                case 'success':
                    $this->io->success($message);
                    break;
                default:
                    $this->io->text($message);
            }
        } else {
            echo $message . "\n";
        }
    }

    /**
     * Collect folder membership information for assets
     */
    protected function collectFolderInfo(): array
    {
        $this->output("Collecting folder information...");
        $folders = [];

        try {
            $foldersResponse = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'listFolders',
                ['AwsAccountId' => $this->awsAccountId]
            );

            foreach ($foldersResponse['FolderSummaryList'] as $folder) {
                $folderMembers = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listFolderMembers',
                    [
                        'AwsAccountId' => $this->awsAccountId,
                        'FolderId'     => $folder['FolderId'],
                    ]
                );

                foreach ($folderMembers['FolderMemberList'] as $member) {
                    if (!isset($folders[$member['MemberArn']])) {
                        $folders[$member['MemberArn']] = [];
                    }
                    $folders[$member['MemberArn']][] = $folder['Name'];
                }
            }

            return $folders;
        } catch (AwsException $e) {
            $this->output("Error collecting folder information: " . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Export a list of assets and their tags to CSV
     */
    public function exportAssetList(?string $outputPath = null): bool
    {
        $timestamp = date(format: 'Ymd_His');

        // Fallback to config-defined report export path if not provided
        $defaultPath = $this->config['paths']['report_export_path'] ?? (getcwd() . '/exports');

        $exportDir = rtrim(string: $outputPath ?? $defaultPath, characters: DIRECTORY_SEPARATOR);

        // Ensure directory exists
        if (!is_dir($exportDir)) {
            mkdir($exportDir, permissions: 0777, recursive: true);
        }

        $filename = "{$exportDir}/quicksight_assets_report_{$timestamp}.csv";

        $this->output(message: "Generating asset report to $filename");

        $output = fopen(filename: $filename, mode: 'w');
        if (!$output) {
            $this->output(message: "Error: Unable to create output file", type: 'error');
            return false;
        }

        fputcsv(
            stream: $output,
            fields: [
                'Asset Type',
                'ID',
                'Name',
                'Shared Folders',
                'Owners',
                'Group',
                'Other Tags',
            ],
            separator: ',',
            enclosure: '"',
            escape: '\\'
        );

        // Collect folder information
        $folders = $this->collectFolderInfo();

        // Export dashboards
        $this->exportDashboardsToCSV($output, $folders);

        // Export datasets
        $this->exportDatasetsToCSV($output, $folders);

        // Export analyses
        $this->exportAnalysesToCSV($output, $folders);

        fclose($output);
        $this->output("Asset report generated successfully.", 'success');
        return true;
    }

    /**
     * Export dashboards to the CSV file
     */
    protected function exportDashboardsToCSV($fileHandle, array $folders): void
    {
        $dashboardCount = 0;
        $nextToken      = null;

        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            try {
                $response = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listDashboards',
                    $params
                );

                foreach ($response['DashboardSummaryList'] as $dashboard) {
                    $dashboardCount++;
                    $this->output("Processing dashboard #{$dashboardCount}: {$dashboard['DashboardId']}");

                    // Get tags
                    $tags = TaggingHelper::getResourceTags(
                        client: $this->quickSight,
                        resourceArn: $dashboard['Arn']
                    );
                    $groupTag = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->tagKey);

                    // Get other tags
                    $otherTags = [];
                    foreach ($tags as $tag) {
                        if (strtolower($tag['Key']) !== strtolower($this->tagKey)) {
                            $otherTags[] = "{$tag['Key']}={$tag['Value']}";
                        }
                    }

                    // Get folder names
                    $sharedFolders = isset($folders[$dashboard['Arn']]) ? $folders[$dashboard['Arn']] : [];

                    // Get permissions (owners)
                    $owners = $this->getDashboardOwners($dashboard['DashboardId']);

                    fputcsv(
                        stream: $fileHandle,
                        fields: [
                            'Dashboard',
                            $dashboard['DashboardId'],
                            $dashboard['Name'],
                            implode(separator: '|', array: $sharedFolders),
                            implode(separator: '|', array: $owners),
                            $groupTag ?? 'Untagged',
                            implode(separator: '; ', array: $otherTags),
                        ],
                        separator: ',',
                        enclosure: '"',
                        escape: '\\'
                    );
                }

                $nextToken = $response['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->output("Error processing dashboards: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);

        $this->output("Processed $dashboardCount dashboards.");
    }

    /**
     * Helper to get dashboard owners from permissions
     */
    protected function getDashboardOwners(string $dashboardId): array
    {
        try {
            $response = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'describeDashboardPermissions',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'DashboardId'  => $dashboardId
                ]
            );

            $principals = [];
            if (isset($response['Permissions'])) {
                foreach ($response['Permissions'] as $perm) {
                    if (isset($perm['Principal'])) {
                        $principals[] = $perm['Principal'];
                    }
                }
            }
            return $principals;
        } catch (AwsException $e) {
            return ['Unknown - ' . $e->getMessage()];
        }
    }

    /**
     * Export datasets to the CSV file
     */
    protected function exportDatasetsToCSV($fileHandle, array $folders): void
    {
        $datasetCount = 0;
        $nextToken    = null;

        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            try {
                $response = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listDataSets',
                    $params
                );

                foreach ($response['DataSetSummaries'] as $dataset) {
                    $datasetCount++;
                    $this->output("Processing dataset #{$datasetCount}: {$dataset['DataSetId']}");

                    // Get tags
                    $tags     = TaggingHelper::getResourceTags(client: $this->quickSight, resourceArn: $dataset['Arn']);
                    $groupTag = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->tagKey);

                    // Get other tags
                    $otherTags = [];
                    foreach ($tags as $tag) {
                        if (strtolower($tag['Key']) !== 'group') {
                            $otherTags[] = "{$tag['Key']}={$tag['Value']}";
                        }
                    }

                    // Get folder names
                    $sharedFolders = isset($folders[$dataset['Arn']]) ? $folders[$dataset['Arn']] : [];

                    // Get permissions (owners)
                    $owners = $this->getDatasetOwners($dataset['DataSetId']);

                    fputcsv(
                        stream: $fileHandle,
                        fields: [
                            'Dataset',
                            $dataset['DataSetId'],
                            $dataset['Name'],
                            implode(separator: '|', array: $sharedFolders),
                            implode(separator: '|', array: $owners),
                            $groupTag ?? 'Untagged',
                            implode(separator: '; ', array: $otherTags),
                        ],
                        separator: ',',
                        enclosure: '"',
                        escape: '\\'
                    );
                }

                $nextToken = $response['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->output("Error processing datasets: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);

        $this->output("Processed $datasetCount datasets.");
    }

    /**
     * Helper to get dataset owners from permissions
     */
    protected function getDatasetOwners(string $datasetId): array
    {
        try {
            $response = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'describeDataSetPermissions',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'DataSetId'    => $datasetId
                ]
            );

            $principals = [];
            if (isset($response['Permissions'])) {
                foreach ($response['Permissions'] as $perm) {
                    if (isset($perm['Principal'])) {
                        $principals[] = $perm['Principal'];
                    }
                }
            }
            return $principals;
        } catch (AwsException $e) {
            return ['Unknown - ' . $e->getMessage()];
        }
    }

    /**
     * Export analyses to the CSV file
     */
    protected function exportAnalysesToCSV($fileHandle, array $folders): void
    {
        $analysisCount = 0;
        $nextToken     = null;

        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            try {
                $response = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listAnalyses',
                    $params
                );

                foreach ($response['AnalysisSummaryList'] as $analysis) {
                    $analysisCount++;
                    $this->output("Processing analysis #{$analysisCount}: {$analysis['AnalysisId']}");

                    // Get tags
                    $tags     = TaggingHelper::getResourceTags(
                        client: $this->quickSight,
                        resourceArn: $analysis['Arn']
                    );
                    $groupTag = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->tagKey);

                    // Get other tags
                    $otherTags = [];
                    foreach ($tags as $tag) {
                        if (strtolower($tag['Key']) !== 'group') {
                            $otherTags[] = "{$tag['Key']}={$tag['Value']}";
                        }
                    }

                    // Get folder names
                    $sharedFolders = isset($folders[$analysis['Arn']]) ? $folders[$analysis['Arn']] : [];

                    // Get permissions (owners)
                    $owners = $this->getAnalysisOwners($analysis['AnalysisId']);

                    fputcsv(
                        stream: $fileHandle,
                        fields: [
                            'Analysis',
                            $analysis['AnalysisId'],
                            $analysis['Name'],
                            implode(separator: '|', array: $sharedFolders),
                            implode(separator: '|', array: $owners),
                            $groupTag ?? 'Untagged',
                            implode(separator: '; ', array: $otherTags),
                        ],
                        separator: ',',
                        enclosure: '"',
                        escape: '\\'
                    );
                }

                $nextToken = $response['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->output("Error processing analyses: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);

        $this->output("Processed $analysisCount analyses.");
    }

    /**
     * Helper to get analysis owners from permissions
     */
    protected function getAnalysisOwners(string $analysisId): array
    {
        try {
            $response = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'describeAnalysisPermissions',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'AnalysisId'   => $analysisId
                ]
            );

            $principals = [];
            if (isset($response['Permissions'])) {
                foreach ($response['Permissions'] as $perm) {
                    if (isset($perm['Principal'])) {
                        $principals[] = $perm['Principal'];
                    }
                }
            }
            return $principals;
        } catch (AwsException $e) {
            return ['Unknown - ' . $e->getMessage()];
        }
    }
}
