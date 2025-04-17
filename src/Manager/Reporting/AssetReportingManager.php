<?php

namespace QSAssetManager\Manager\Reporting;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\TaggingHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use GuzzleHttp\Promise;

class AssetReportingManager
{
    protected array $config;
    protected QuickSightClient $quickSight;
    protected string $awsAccountId;
    protected string $awsRegion;
    protected ?SymfonyStyle $io;
    protected int $maxConcurrent = 5;

    /** @var array<string,string[]> dataset ID → dashboards */
    private array $datasetDashboardMap = [];
    /** @var array<string,string[]> dataset ID → analyses */
    private array $datasetAnalysisMap = [];
    /** @var array<string,string[]> analysis ID → dashboards */
    private array $analysisDashboardMap = [];
    /** @var array<string,string[]> dataset ID → dependent datasets */
    private array $datasetDependencyMap = [];

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

    public function exportAssetReport(
        ?string $outputPath = null,
        bool $onlyUntagged = false,
        bool $onlyNoFolders = false,
        ?string $tagFilter = null,
        string $assetType = 'all'
    ): bool|string {
        $this->write("Starting asset report export...");
        $timestamp   = date('Ymd_His');
        $defaultPath = $this->config['paths']['report_export_path'] ?? (getcwd() . '/exports');
        $exportDir   = rtrim($outputPath ?? $defaultPath, DIRECTORY_SEPARATOR);

        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
            $this->write("Created export directory: $exportDir");
        }

        $filename = "{$exportDir}/quicksight_assets_report_{$timestamp}.csv";
        $fh       = fopen($filename, 'w');
        if (!$fh) {
            $this->write("Error: Unable to create output file", 'error');
            return false;
        }

        $this->write("Collecting folder information...");
        $folders = $this->collectFolderInfo();
        $this->write("Collected folder info (" . count($folders) . " entries)");

        $this->write("Building usage maps with concurrency (max {$this->maxConcurrent})...");
        $this->buildUsageMaps();
        $this->write("Collected usage maps");

        // CSV header
        fputcsv(
            stream:    $fh,
            fields:    [
                'Asset Type', 'ID', 'Name', 'Shared Folders', 'Owners', 'Group', 'Other Tags',
                'UsedByDashboards', 'UsedByAnalyses', 'UsedByDataSets', 'Status'
            ],
            separator: ',',
            enclosure: '"',
            escape:    '\\'
        );

        if ($assetType === 'all' || $assetType === 'dashboards') {
            $this->exportDashboardsToCSV($fh, $folders, $onlyUntagged, $onlyNoFolders, $tagFilter);
        }
        if ($assetType === 'all' || $assetType === 'datasets') {
            $this->exportDatasetsToCSV($fh, $folders, $onlyUntagged, $onlyNoFolders, $tagFilter);
        }
        if ($assetType === 'all' || $assetType === 'analyses') {
            $this->exportAnalysesToCSV($fh, $folders, $onlyUntagged, $onlyNoFolders, $tagFilter);
        }

        fclose($fh);
        $this->write("Asset report generated successfully at: $filename", 'success');
        return $filename;
    }

    protected function buildUsageMaps(): void
    {
        // ── 1) dashboards → datasetDashboardMap & analysisDashboardMap ──

        $dashboards = QuickSightHelper::paginate(
            client:       $this->quickSight,
            awsAccountId: $this->awsAccountId,
            operation:    'listDashboards',
            listKey:      'DashboardSummaryList'
        );
        $total = count($dashboards);
        $this->write("Describing {$total} dashboards…");

        foreach (array_chunk($dashboards, $this->maxConcurrent) as $batch) {
            $promises = [];
            foreach ($batch as $d) {
                $id     = $d['DashboardId'];
                $params = ['AwsAccountId' => $this->awsAccountId, 'DashboardId' => $id];

                $promises[$id] = $this->quickSight
                    ->describeDashboardAsync($params)
                    ->otherwise(function ($reason) use ($params) {
                        return QuickSightHelper::executeWithRetry(
                            $this->quickSight,
                            'describeDashboard',
                            $params
                        );
                    });
            }

            $results = Promise\Utils::settle($promises)->wait();
            foreach ($results as $id => $res) {
                if ($res['state'] !== 'fulfilled') {
                    $this->write("Failed to describe dashboard {$id}: " . $res['reason'], 'error');
                    continue;
                }
                $desc = $res['value'];
                $ver  = $desc['Dashboard']['Version'] ?? [];

                foreach ($ver['DataSetArns'] ?? [] as $arn) {
                    $this->datasetDashboardMap[basename($arn)][] = $id;
                }
                if (!empty($ver['SourceEntityArn']) && strpos($ver['SourceEntityArn'], ':analysis/') !== false) {
                    $aid = substr(strrchr($ver['SourceEntityArn'], '/'), 1);
                    $this->analysisDashboardMap[$aid][] = $id;
                }
            }
        }

        // ── 2) analyses → datasetAnalysisMap ──

        $analyses = QuickSightHelper::paginate(
            client:       $this->quickSight,
            awsAccountId: $this->awsAccountId,
            operation:    'listAnalyses',
            listKey:      'AnalysisSummaryList'
        );
        $total = count($analyses);
        $this->write("Describing {$total} analyses…");

        foreach (array_chunk($analyses, $this->maxConcurrent) as $batch) {
            $promises = [];
            foreach ($batch as $a) {
                $id     = $a['AnalysisId'];
                $params = ['AwsAccountId' => $this->awsAccountId, 'AnalysisId' => $id];

                $promises[$id] = $this->quickSight
                    ->describeAnalysisAsync($params)
                    ->otherwise(function ($reason) use ($params) {
                        return QuickSightHelper::executeWithRetry(
                            $this->quickSight,
                            'describeAnalysis',
                            $params
                        );
                    });
            }

            $results = Promise\Utils::settle($promises)->wait();
            foreach ($results as $id => $res) {
                if ($res['state'] !== 'fulfilled') {
                    $this->write("Failed to describe analysis {$id}: " . $res['reason'], 'error');
                    continue;
                }
                $desc = $res['value'];
                foreach ($desc['Analysis']['DataSetArns'] ?? [] as $arn) {
                    $this->datasetAnalysisMap[basename($arn)][] = $id;
                }
            }
        }

        // ── 3) datasets → datasetDependencyMap ──

        $datasets = QuickSightHelper::paginate(
            client:       $this->quickSight,
            awsAccountId: $this->awsAccountId,
            operation:    'listDataSets',
            listKey:      'DataSetSummaries'
        );
        $total = count($datasets);
        $this->write("Describing {$total} datasets…");

        // Initialize dependency map with empty arrays for all datasets to avoid nulls
        foreach ($datasets as $ds) {
            $this->datasetDependencyMap[$ds['DataSetId']] = [];
        }

        foreach (array_chunk($datasets, $this->maxConcurrent) as $batch) {
            $promises = [];
            foreach ($batch as $ds) {
                $id     = $ds['DataSetId'];
                $params = ['AwsAccountId' => $this->awsAccountId, 'DataSetId' => $id];

                $promises[$id] = $this->quickSight
                    ->describeDataSetAsync($params)
                    ->otherwise(function ($reason) use ($params, $id) {
                        if (
                            $reason instanceof AwsException
                            && $reason->getAwsErrorCode() === 'InvalidParameterValueException'
                        ) {
                            $this->write("Skipping unsupported dataset {$id}", 'info');
                            return null;
                        }
                        return QuickSightHelper::executeWithRetry(
                            $this->quickSight,
                            'describeDataSet',
                            $params
                        );
                    });
            }

            $results = Promise\Utils::settle($promises)->wait();
            foreach ($results as $id => $res) {
                if ($res['state'] !== 'fulfilled') {
                    $this->write("Failed to describe dataset {$id}: " . $res['reason'], 'error');
                    continue;
                }
                $value = $res['value'];
                if ($value === null || !isset($value['DataSet'])) {
                    // skipped flat-file or unsupported dataset
                    continue;
                }
                $def = $value['DataSet'];
                foreach ($def['LogicalTableMap'] ?? [] as $lt) {
                    $src = $lt['Source'] ?? $lt['Value']['Source'] ?? [];
                    if (!empty($src['DataSetArn'])) {
                        $parent = basename($src['DataSetArn']);
                        if (!in_array($id, $this->datasetDependencyMap[$parent] ?? [])) {
                            $this->datasetDependencyMap[$parent][] = $id;
                        }
                    }
                }
            }
        }

        // Clean up empty arrays in the dependency map for better CSV output
        foreach ($this->datasetDependencyMap as $id => $deps) {
            if (empty($deps)) {
                $this->datasetDependencyMap[$id] = [];
            }
        }
    }

    protected function collectFolderInfo(): array
    {
        $map = [];
        try {
            $resp = QuickSightHelper::executeWithRetry(
                client: $this->quickSight,
                method: 'listFolders',
                params: ['AwsAccountId' => $this->awsAccountId]
            );
            foreach ($resp['FolderSummaryList'] ?? [] as $f) {
                $members = QuickSightHelper::executeWithRetry(
                    client: $this->quickSight,
                    method: 'listFolderMembers',
                    params: ['AwsAccountId' => $this->awsAccountId, 'FolderId' => $f['FolderId']]
                )['FolderMemberList'] ?? [];
                foreach ($members as $m) {
                    $map[$m['MemberArn']][] = $f['Name'];
                }
            }
        } catch (AwsException $e) {
            $this->write("Error collecting folders: " . $e->getMessage(), 'error');
        }
        return $map;
    }

    protected function exportDashboardsToCSV(
        $fh,
        array $folders,
        bool $onlyUntagged,
        bool $onlyNoFolders,
        ?string $tagFilter
    ): void {
        $count = 0;
        $token = null;
        do {
            $resp = QuickSightHelper::executeWithRetry(
                client: $this->quickSight,
                method: 'listDashboards',
                params: ['AwsAccountId' => $this->awsAccountId, 'NextToken' => $token]
            );
            foreach ($resp['DashboardSummaryList'] ?? [] as $d) {
                $count++;
                $tags  = TaggingHelper::getResourceTags($this->quickSight, $d['Arn']);
                $group = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->config['tagging']['default_key'] ??
                'group');
                if (($onlyUntagged && $group) || ($tagFilter && $group !== $tagFilter)) {
                    continue;
                }
                $sf = $folders[$d['Arn']] ?? [];
                if ($onlyNoFolders && !empty($sf)) {
                    continue;
                }
                $owners = $this->getDashboardOwners($d['DashboardId']);
                $other  = [];
                foreach ($tags as $t) {
                    if (
                        strtolower($t['Key']) !== strtolower($this->config['tagging']['default_key'] ??
                        'group')
                    ) {
                        $other[] = "{$t['Key']}={$t['Value']}";
                    }
                }
                fputcsv(
                    stream: $fh,
                    fields: [
                        'Dashboard',
                        $d['DashboardId'],
                        $d['Name'],
                        implode('|', $sf),
                        implode('|', $owners),
                        $group ?? 'Untagged',
                        implode('; ', $other),
                        '',
                        '',
                        '',
                        '' // Status blank for dashboards
                    ],
                    separator: ',',
                    enclosure: '"',
                    escape: '\\'
                );
            }
            $token = $resp['NextToken'] ?? null;
        } while ($token);
        $this->write("Processed $count dashboards");
    }

    protected function exportDatasetsToCSV(
        $fh,
        array $folders,
        bool $onlyUntagged,
        bool $onlyNoFolders,
        ?string $tagFilter
    ): void {
        $count = 0;
        $token = null;
        do {
            $resp = QuickSightHelper::executeWithRetry(
                client: $this->quickSight,
                method: 'listDataSets',
                params: ['AwsAccountId' => $this->awsAccountId, 'NextToken' => $token]
            );
            foreach ($resp['DataSetSummaries'] ?? [] as $ds) {
                $count++;
                $tags  = TaggingHelper::getResourceTags($this->quickSight, $ds['Arn']);
                $group = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->config['tagging']['default_key'] ??
                'group');
                if (($onlyUntagged && $group) || ($tagFilter && $group !== $tagFilter)) {
                    continue;
                }
                $sf = $folders[$ds['Arn']] ?? [];
                if ($onlyNoFolders && !empty($sf)) {
                    continue;
                }
                $owners  = $this->getDatasetOwners($ds['DataSetId']);
                $other   = [];
                foreach ($tags as $t) {
                    if (
                        strtolower($t['Key']) !== strtolower($this->config['tagging']['default_key'] ??
                        'group')
                    ) {
                        $other[] = "{$t['Key']}={$t['Value']}";
                    }
                }
                $dashList = $this->datasetDashboardMap[$ds['DataSetId']] ?? [];
                $anaList  = $this->datasetAnalysisMap[$ds['DataSetId']]  ?? [];
                $depList  = $this->datasetDependencyMap[$ds['DataSetId']] ?? [];

                fputcsv(
                    stream: $fh,
                    fields: [
                        'Dataset',
                        $ds['DataSetId'],
                        $ds['Name'],
                        implode('|', $sf),
                        implode('|', $owners),
                        $group ?? 'Untagged',
                        implode('; ', $other),
                        implode('|', $dashList),
                        implode('|', $anaList),
                        implode('|', $depList),
                        '' // Status blank for datasets
                    ],
                    separator: ',',
                    enclosure: '"',
                    escape: '\\'
                );
            }
            $token = $resp['NextToken'] ?? null;
        } while ($token);
        $this->write("Processed $count datasets");
    }

    protected function exportAnalysesToCSV(
        $fh,
        array $folders,
        bool $onlyUntagged,
        bool $onlyNoFolders,
        ?string $tagFilter
    ): void {
        $count = 0;
        $token = null;
        do {
            $resp = QuickSightHelper::executeWithRetry(
                client: $this->quickSight,
                method: 'listAnalyses',
                params: ['AwsAccountId' => $this->awsAccountId, 'NextToken' => $token]
            );
            foreach ($resp['AnalysisSummaryList'] ?? [] as $an) {
                $count++;
                $tags  = TaggingHelper::getResourceTags($this->quickSight, $an['Arn']);
                $group = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->config['tagging']['default_key'] ??
                'group');
                if (($onlyUntagged && $group) || ($tagFilter && $group !== $tagFilter)) {
                    continue;
                }
                $sf = $folders[$an['Arn']] ?? [];
                if ($onlyNoFolders && !empty($sf)) {
                    continue;
                }
                $owners  = $this->getAnalysisOwners($an['AnalysisId']);
                $other   = [];
                foreach ($tags as $t) {
                    if (strtolower($t['Key']) !== strtolower($this->config['tagging']['default_key'] ?? 'group')) {
                        $other[] = "{$t['Key']}={$t['Value']}";
                    }
                }
                $dashList = $this->analysisDashboardMap[$an['AnalysisId']] ?? [];

                // Get analysis status directly from the describe call
                $status = '';
                try {
                    $descResp = QuickSightHelper::executeWithRetry(
                        client: $this->quickSight,
                        method: 'describeAnalysis',
                        params: ['AwsAccountId' => $this->awsAccountId, 'AnalysisId' => $an['AnalysisId']]
                    );
                    $status = $descResp['Analysis']['Status'] ?? 'UNKNOWN';
                } catch (AwsException $e) {
                    $this->write("Error getting status for analysis {$an['AnalysisId']}", 'info');
                }

                fputcsv(
                    stream: $fh,
                    fields: [
                        'Analysis',
                        $an['AnalysisId'],
                        $an['Name'],
                        implode('|', $sf),
                        implode('|', $owners),
                        $group ?? 'Untagged',
                        implode('; ', $other),
                        implode('|', $dashList),
                        '',
                        '',
                        $status // Status column at the end
                    ],
                    separator: ',',
                    enclosure: '"',
                    escape: '\\'
                );
            }
            $token = $resp['NextToken'] ?? null;
        } while ($token);
        $this->write("Processed $count analyses");
    }

    protected function getDashboardOwners(string $dashboardId): array
    {
        try {
            $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'describeDashboardPermissions', [
                'AwsAccountId' => $this->awsAccountId,
                'DashboardId'  => $dashboardId,
            ]);
            return array_column($resp['Permissions'] ?? [], 'Principal');
        } catch (AwsException $e) {
            return ['Unknown'];
        }
    }

    protected function getDatasetOwners(string $datasetId): array
    {
        try {
            $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'describeDataSetPermissions', [
                'AwsAccountId' => $this->awsAccountId,
                'DataSetId'    => $datasetId,
            ]);
            return array_column($resp['Permissions'] ?? [], 'Principal');
        } catch (AwsException $e) {
            return ['Unknown'];
        }
    }

    protected function getAnalysisOwners(string $analysisId): array
    {
        try {
            $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'describeAnalysisPermissions', [
                'AwsAccountId' => $this->awsAccountId,
                'AnalysisId'   => $analysisId,
            ]);
            return array_column($resp['Permissions'] ?? [], 'Principal');
        } catch (AwsException $e) {
            return ['Unknown'];
        }
    }

    protected function write(string $msg, string $type = 'info'): void
    {
        if ($this->io) {
            $this->io->writeln("<$type>$msg</$type>");
        } else {
            echo $msg . "\n";
        }
    }
}
