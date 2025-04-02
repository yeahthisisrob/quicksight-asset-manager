<?php

namespace QSAssetManager\Manager\Reporting;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\TaggingHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class AssetReportingManager
{
    protected array $config;
    protected QuickSightClient $quickSight;
    protected string $awsAccountId;
    protected string $awsRegion;
    protected ?SymfonyStyle $io;

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
     * Export a CSV report of QuickSight assets.
     *
     * @param string|null $outputPath    Directory for CSV.
     * @param bool        $onlyUntagged  Export only untagged assets.
     * @param bool        $onlyNoFolders Export only assets with no folder.
     * @param string|null $tagFilter     Filter assets by group tag.
     * @param string      $assetType     Export type (dashboards, datasets, analyses, all).
     * @return string|false Full path to CSV or false on failure.
     */
    public function exportAssetReport(
        ?string $outputPath = null,
        bool $onlyUntagged = false,
        bool $onlyNoFolders = false,
        ?string $tagFilter = null,
        string $assetType = 'all'
    ): bool|string {
        $this->write("Starting asset report export...");
        $timestamp   = date('Ymd_His');
        $defaultPath = $this->config['paths']['report_export_path']
            ?? (getcwd() . '/exports');
        $exportDir   = rtrim($outputPath ?? $defaultPath, DIRECTORY_SEPARATOR);

        if (!is_dir($exportDir)) {
            mkdir(directory: $exportDir, permissions: 0777, recursive: true);
            $this->write("Created export directory: $exportDir");
        }

        $filename = "{$exportDir}/quicksight_assets_report_{$timestamp}.csv";
        $fh = fopen(filename: $filename, mode: 'w');
        if (!$fh) {
            $this->write("Error: Unable to create output file", 'error');
            return false;
        }

        $this->write("Writing CSV header...");
        fputcsv(
            stream:    $fh,
            fields:    ['Asset Type', 'ID', 'Name', 'Shared Folders', 'Owners', 'Group', 'Other Tags'],
            separator: ',',
            enclosure: '"',
            escape:    '\\'
        );

        $this->write("Collecting folder information...");
        $folders = $this->collectFolderInfo();
        $this->write("Collected folder info (" . count($folders) . " entries).");

        if ($assetType === 'all' || $assetType === 'dashboards') {
            $this->write("Exporting dashboards...");
            $this->exportDashboardsToCSV(
                fh: $fh,
                folders: $folders,
                onlyUntagged: $onlyUntagged,
                onlyNoFolders: $onlyNoFolders,
                tagFilter: $tagFilter
            );
            $this->write("Finished exporting dashboards.");
        }
        if ($assetType === 'all' || $assetType === 'datasets') {
            $this->write("Exporting datasets...");
            $this->exportDatasetsToCSV(
                fh: $fh,
                folders: $folders,
                onlyUntagged: $onlyUntagged,
                onlyNoFolders: $onlyNoFolders,
                tagFilter: $tagFilter
            );
            $this->write("Finished exporting datasets.");
        }
        if ($assetType === 'all' || $assetType === 'analyses') {
            $this->write("Exporting analyses...");
            $this->exportAnalysesToCSV(
                fh: $fh,
                folders: $folders,
                onlyUntagged: $onlyUntagged,
                onlyNoFolders: $onlyNoFolders,
                tagFilter: $tagFilter
            );
            $this->write("Finished exporting analyses.");
        }

        fclose($fh);
        $this->write("Asset report generated successfully at: $filename", 'success');
        return $filename;
    }

    protected function write(string $message, string $type = 'info'): void
    {
        if ($this->io) {
            $this->io->writeln("<$type>$message</$type>");
        } else {
            echo $message . "\n";
        }
    }

    protected function collectFolderInfo(): array
    {
        $folders = [];
        try {
            $foldersResponse = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'listFolders',
                ['AwsAccountId' => $this->awsAccountId]
            );
            if (isset($foldersResponse['FolderSummaryList'])) {
                foreach ($foldersResponse['FolderSummaryList'] as $folder) {
                    $folderMembers = QuickSightHelper::executeWithRetry(
                        $this->quickSight,
                        'listFolderMembers',
                        [
                            'AwsAccountId' => $this->awsAccountId,
                            'FolderId'     => $folder['FolderId'],
                        ]
                    );
                    if (isset($folderMembers['FolderMemberList'])) {
                        foreach ($folderMembers['FolderMemberList'] as $member) {
                            if (!isset($folders[$member['MemberArn']])) {
                                $folders[$member['MemberArn']] = [];
                            }
                            $folders[$member['MemberArn']][] = $folder['Name'];
                        }
                    }
                }
            }
        } catch (AwsException $e) {
            $this->write("Error collecting folder information: " . $e->getMessage(), 'error');
        }
        return $folders;
    }

    protected function exportDashboardsToCSV(
        $fh,
        array $folders,
        bool $onlyUntagged,
        bool $onlyNoFolders,
        ?string $tagFilter
    ): void {
        $dashboardCount = 0;
        $nextToken = null;
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
                    $tags = TaggingHelper::getResourceTags(
                        $this->quickSight,
                        $dashboard['Arn']
                    );
                    $groupTag = TaggingHelper::getGroupTag(
                        tags:   $tags,
                        tagKey: $this->config['tagging']['default_key'] ?? 'group'
                    );
                    if ($onlyUntagged && $groupTag) {
                        continue;
                    }
                    if ($tagFilter && $groupTag !== $tagFilter) {
                        continue;
                    }
                    $sharedFolders = $folders[$dashboard['Arn']] ?? [];
                    if ($onlyNoFolders && !empty($sharedFolders)) {
                        continue;
                    }
                    $owners = $this->getDashboardOwners(
                        $dashboard['DashboardId']
                    );
                    $otherTags = [];
                    foreach ($tags as $tag) {
                        if (
                            strtolower($tag['Key']) !== strtolower(
                                $this->config['tagging']['default_key'] ?? 'group'
                            )
                        ) {
                            $otherTags[] = "{$tag['Key']}={$tag['Value']}";
                        }
                    }
                    fputcsv(
                        $fh,
                        [
                            'Dashboard',
                            $dashboard['DashboardId'],
                            $dashboard['Name'],
                            implode('|', $sharedFolders),
                            implode('|', $owners),
                            $groupTag ?? 'Untagged',
                            implode('; ', $otherTags),
                        ],
                        ',',
                        '"',
                        '\\'
                    );
                }
                $nextToken = $response['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->write("Error processing dashboards: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);
        $this->write("Processed $dashboardCount dashboards.");
    }

    protected function getDashboardOwners(string $dashboardId): array
    {
        try {
            $response = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'describeDashboardPermissions',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'DashboardId'  => $dashboardId,
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
            return ['Unknown'];
        }
    }

    protected function exportDatasetsToCSV(
        $fh,
        array $folders,
        bool $onlyUntagged,
        bool $onlyNoFolders,
        ?string $tagFilter
    ): void {
        $datasetCount = 0;
        $nextToken = null;
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
                    $tags = TaggingHelper::getResourceTags(
                        $this->quickSight,
                        $dataset['Arn']
                    );
                    $groupTag = TaggingHelper::getGroupTag(
                        tags:   $tags,
                        tagKey: $this->config['tagging']['default_key'] ?? 'group'
                    );
                    if ($onlyUntagged && $groupTag) {
                        continue;
                    }
                    if ($tagFilter && $groupTag !== $tagFilter) {
                        continue;
                    }
                    $sharedFolders = $folders[$dataset['Arn']] ?? [];
                    if ($onlyNoFolders && !empty($sharedFolders)) {
                        continue;
                    }
                    $owners = $this->getDatasetOwners(
                        $dataset['DataSetId']
                    );
                    $otherTags = [];
                    foreach ($tags as $tag) {
                        if (
                            strtolower($tag['Key']) !== strtolower(
                                $this->config['tagging']['default_key'] ?? 'group'
                            )
                        ) {
                            $otherTags[] = "{$tag['Key']}={$tag['Value']}";
                        }
                    }
                    fputcsv(
                        $fh,
                        [
                            'Dataset',
                            $dataset['DataSetId'],
                            $dataset['Name'],
                            implode('|', $sharedFolders),
                            implode('|', $owners),
                            $groupTag ?? 'Untagged',
                            implode('; ', $otherTags),
                        ],
                        ',',
                        '"',
                        '\\'
                    );
                }
                $nextToken = $response['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->write("Error processing datasets: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);
        $this->write("Processed $datasetCount datasets.");
    }

    protected function getDatasetOwners(string $datasetId): array
    {
        try {
            $response = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'describeDataSetPermissions',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'DataSetId'    => $datasetId,
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
            return ['Unknown'];
        }
    }

    protected function exportAnalysesToCSV(
        $fh,
        array $folders,
        bool $onlyUntagged,
        bool $onlyNoFolders,
        ?string $tagFilter
    ): void {
        $analysisCount = 0;
        $nextToken = null;
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
                    $tags = TaggingHelper::getResourceTags(
                        $this->quickSight,
                        $analysis['Arn']
                    );
                    $groupTag = TaggingHelper::getGroupTag(
                        tags:   $tags,
                        tagKey: $this->config['tagging']['default_key'] ?? 'group'
                    );
                    if ($onlyUntagged && $groupTag) {
                        continue;
                    }
                    if ($tagFilter && $groupTag !== $tagFilter) {
                        continue;
                    }
                    $sharedFolders = $folders[$analysis['Arn']] ?? [];
                    if ($onlyNoFolders && !empty($sharedFolders)) {
                        continue;
                    }
                    $owners = $this->getAnalysisOwners(
                        $analysis['AnalysisId']
                    );
                    $otherTags = [];
                    foreach ($tags as $tag) {
                        if (
                            strtolower($tag['Key']) !== strtolower(
                                $this->config['tagging']['default_key'] ?? 'group'
                            )
                        ) {
                            $otherTags[] = "{$tag['Key']}={$tag['Value']}";
                        }
                    }
                    fputcsv(
                        $fh,
                        [
                            'Analysis',
                            $analysis['AnalysisId'],
                            $analysis['Name'],
                            implode('|', $sharedFolders),
                            implode('|', $owners),
                            $groupTag ?? 'Untagged',
                            implode('; ', $otherTags),
                        ],
                        ',',
                        '"',
                        '\\'
                    );
                }
                $nextToken = $response['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->write("Error processing analyses: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);
        $this->write("Processed $analysisCount analyses.");
    }

    protected function getAnalysisOwners(string $analysisId): array
    {
        try {
            $response = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'describeAnalysisPermissions',
                [
                    'AwsAccountId' => $this->awsAccountId,
                    'AnalysisId'   => $analysisId,
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
            return ['Unknown'];
        }
    }
}
