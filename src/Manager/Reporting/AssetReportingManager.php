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
        $this->config = $config;
        $this->quickSight = $quickSight;
        $this->awsAccountId = $awsAccountId;
        $this->awsRegion = $awsRegion;
        $this->io = $io;
    }

    /**
     * Export a CSV report of QuickSight assets.
     */
    public function exportAssetReport(
        ?string $outputPath = null,
        bool $onlyUntagged = false,
        bool $onlyNoFolders = false,
        ?string $tagFilter = null,
        string $assetType = 'all'
    ): bool|string {
        $this->write("Starting asset report export...");
        $timestamp = date('Ymd_His');
        $defaultDir = $this->config['paths']['report_export_path']
            ?? (getcwd() . '/exports');
        $exportDir = rtrim($outputPath ?? $defaultDir, DIRECTORY_SEPARATOR);

        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
            $this->write("Created export directory: $exportDir");
        }

        $filename = "$exportDir/quicksight_assets_report_{$timestamp}.csv";
        $fh = fopen($filename, 'w');
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

        // Header row with new UsedByDataSets column
        fputcsv($fh, [
            'Asset Type',
            'ID',
            'Name',
            'Shared Folders',
            'Owners',
            'Group',
            'Other Tags',
            'UsedByDashboards',
            'UsedByAnalyses',
            'UsedByDataSets',
        ], ',', '"', '\\');

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
        // Describe dashboards → datasetDashboardMap & analysisDashboardMap
        $dashboards = [];
        $token = null;
        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($token) {
                $params['NextToken'] = $token;
            }
            try {
                $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'listDashboards', $params);
                $dashboards = array_merge($dashboards, $resp['DashboardSummaryList'] ?? []);
                $token = $resp['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->write("Error listing dashboards: " . $e->getMessage(), 'error');
                break;
            }
        } while ($token);

        $total = count($dashboards);
        $processed = 0;
        foreach (array_chunk($dashboards, $this->maxConcurrent) as $batch) {
            $promises = [];
            foreach ($batch as $dash) {
                $id = $dash['DashboardId'];
                $promises[$id] = $this->quickSight->describeDashboardAsync([
                    'AwsAccountId' => $this->awsAccountId,
                    'DashboardId' => $id,
                ]);
            }
            $results = Promise\Utils::settle($promises)->wait();
            foreach ($results as $id => $res) {
                $processed++;
                if ($processed % 10 === 0 || $processed === $total) {
                    $this->write("Described $processed/$total dashboards");
                }
                if ($res['state'] !== 'fulfilled') {
                    continue;
                }
                $desc = $res['value'];
                $version = $desc['Dashboard']['Version'] ?? [];
                foreach ($version['DataSetArns'] ?? [] as $arn) {
                    $ds = basename($arn);
                    $this->datasetDashboardMap[$ds][] = $id;
                }
                $src = $version['SourceEntityArn'] ?? '';
                if (strpos($src, ':analysis/') !== false) {
                    $aid = substr(strrchr($src, '/'), 1);
                    $this->analysisDashboardMap[$aid][] = $id;
                }
            }
        }

        // Describe analyses → datasetAnalysisMap
        $analysisList = [];
        $token = null;
        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($token) {
                $params['NextToken'] = $token;
            }
            try {
                $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'listAnalyses', $params);
                $analysisList = array_merge($analysisList, $resp['AnalysisSummaryList'] ?? []);
                $token = $resp['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->write("Error listing analyses: " . $e->getMessage(), 'error');
                break;
            }
        } while ($token);

        $total = count($analysisList);
        $processed = 0;
        foreach (array_chunk($analysisList, $this->maxConcurrent) as $batch) {
            $promises = [];
            foreach ($batch as $an) {
                $id = $an['AnalysisId'];
                $promises[$id] = $this->quickSight->describeAnalysisAsync([
                    'AwsAccountId' => $this->awsAccountId,
                    'AnalysisId' => $id,
                ]);
            }
            $results = Promise\Utils::settle($promises)->wait();
            foreach ($results as $id => $res) {
                $processed++;
                if ($processed % 10 === 0 || $processed === $total) {
                    $this->write("Described $processed/$total analyses");
                }
                if ($res['state'] !== 'fulfilled') {
                    continue;
                }
                $desc = $res['value'];
                foreach ($desc['Analysis']['DataSetArns'] ?? [] as $arn) {
                    $ds = basename($arn);
                    $this->datasetAnalysisMap[$ds][] = $id;
                }
            }
        }

        // Describe datasets → datasetDependencyMap
        $dataSets = [];
        $token = null;
        do {
            $params = ['AwsAccountId' => $this->awsAccountId];
            if ($token) {
                $params['NextToken'] = $token;
            }
            try {
                $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'listDataSets', $params);
                $dataSets = array_merge($dataSets, $resp['DataSetSummaries'] ?? []);
                $token = $resp['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->write("Error listing datasets: " . $e->getMessage(), 'error');
                break;
            }
        } while ($token);

        $total = count($dataSets);
        $processed = 0;
        foreach (array_chunk($dataSets, $this->maxConcurrent) as $batch) {
            $promises = [];
            foreach ($batch as $ds) {
                $id = $ds['DataSetId'];
                $promises[$id] = $this->quickSight->describeDataSetAsync([
                    'AwsAccountId' => $this->awsAccountId,
                    'DataSetId' => $id,
                ]);
            }
            $results = Promise\Utils::settle($promises)->wait();
            foreach ($results as $id => $res) {
                $processed++;
                if ($processed % 10 === 0 || $processed === $total) {
                    $this->write("Described $processed/$total datasets");
                }
                if ($res['state'] !== 'fulfilled') {
                    continue;
                }
                $desc = $res['value'];
                // Look for any DataSetArn sources in LogicalTableMap
                foreach ($desc['DataSet']['LogicalTableMap'] ?? [] as $logical) {
                    $src = $logical['Value']['Source'] ?? [];
                    if (!empty($src['DataSetArn'])) {
                        $parent = basename($src['DataSetArn']);
                        $this->datasetDependencyMap[$parent][] = $id;
                    }
                }
            }
        }
    }

    protected function collectFolderInfo(): array
    {
        $map = [];
        try {
            $resp = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'listFolders',
                ['AwsAccountId' => $this->awsAccountId]
            );
            foreach ($resp['FolderSummaryList'] ?? [] as $f) {
                $members = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listFolderMembers',
                    ['AwsAccountId' => $this->awsAccountId, 'FolderId' => $f['FolderId']]
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
        $cnt = 0;
        $token = null;
        do {
            $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'listDashboards', [
                'AwsAccountId' => $this->awsAccountId,
                'NextToken' => $token,
            ]);
            foreach ($resp['DashboardSummaryList'] ?? [] as $d) {
                $cnt++;
                $tags = TaggingHelper::getResourceTags($this->quickSight, $d['Arn']);
                $group = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->config['tagging']['default_key'] ?? 'group');
                if (($onlyUntagged && $group) || ($tagFilter && $group !== $tagFilter)) {
                    continue;
                }
                $sf = $folders[$d['Arn']] ?? [];
                if ($onlyNoFolders && !empty($sf)) {
                    continue;
                }
                $owners = $this->getDashboardOwners($d['DashboardId']);
                $other = [];
                foreach ($tags as $t) {
                    if (strtolower($t['Key']) !== strtolower($this->config['tagging']['default_key'] ?? 'group')) {
                        $other[] = "{$t['Key']}={$t['Value']}";
                    }
                }
                fputcsv($fh, [
                    'Dashboard',
                    $d['DashboardId'],
                    $d['Name'],
                    implode('|', $sf),
                    implode('|', $owners),
                    $group ?? 'Untagged',
                    implode('; ', $other),
                    '',
                    '',
                    '', // no analyses/datasets columns here
                ], ',', '"', '\\');
            }
            $token = $resp['NextToken'] ?? null;
        } while ($token);
        $this->write("Processed $cnt dashboards");
    }

    protected function exportDatasetsToCSV(
        $fh,
        array $folders,
        bool $onlyUntagged,
        bool $onlyNoFolders,
        ?string $tagFilter
    ): void {
        $cnt = 0;
        $token = null;
        do {
            $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'listDataSets', [
                'AwsAccountId' => $this->awsAccountId,
                'NextToken' => $token,
            ]);
            foreach ($resp['DataSetSummaries'] ?? [] as $ds) {
                $cnt++;
                $tags = TaggingHelper::getResourceTags($this->quickSight, $ds['Arn']);
                $group = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->config['tagging']['default_key'] ?? 'group');
                if (($onlyUntagged && $group) || ($tagFilter && $group !== $tagFilter)) {
                    continue;
                }
                $sf = $folders[$ds['Arn']] ?? [];
                if ($onlyNoFolders && !empty($sf)) {
                    continue;
                }
                $owners = $this->getDatasetOwners($ds['DataSetId']);
                $other = [];
                foreach ($tags as $t) {
                    if (strtolower($t['Key']) !== strtolower($this->config['tagging']['default_key'] ?? 'group')) {
                        $other[] = "{$t['Key']}={$t['Value']}";
                    }
                }
                $dashList = $this->datasetDashboardMap[$ds['DataSetId']] ?? [];
                $anaList = $this->datasetAnalysisMap[$ds['DataSetId']] ?? [];
                $depList = $this->datasetDependencyMap[$ds['DataSetId']] ?? [];
                fputcsv($fh, [
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
                ], ',', '"', '\\');
            }
            $token = $resp['NextToken'] ?? null;
        } while ($token);
        $this->write("Processed $cnt datasets");
    }

    protected function exportAnalysesToCSV(
        $fh,
        array $folders,
        bool $onlyUntagged,
        bool $onlyNoFolders,
        ?string $tagFilter
    ): void {
        $cnt = 0;
        $token = null;
        do {
            $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'listAnalyses', [
                'AwsAccountId' => $this->awsAccountId,
                'NextToken' => $token,
            ]);
            foreach ($resp['AnalysisSummaryList'] ?? [] as $an) {
                $cnt++;
                $tags = TaggingHelper::getResourceTags($this->quickSight, $an['Arn']);
                $group = TaggingHelper::getGroupTag(tags: $tags, tagKey: $this->config['tagging']['default_key'] ?? 'group');
                if (($onlyUntagged && $group) || ($tagFilter && $group !== $tagFilter)) {
                    continue;
                }
                $sf = $folders[$an['Arn']] ?? [];
                if ($onlyNoFolders && !empty($sf)) {
                    continue;
                }
                $owners = $this->getAnalysisOwners($an['AnalysisId']);
                $other = [];
                foreach ($tags as $t) {
                    if (strtolower($t['Key']) !== strtolower($this->config['tagging']['default_key'] ?? 'group')) {
                        $other[] = "{$t['Key']}={$t['Value']}";
                    }
                }
                $dashList = $this->analysisDashboardMap[$an['AnalysisId']] ?? [];
                fputcsv($fh, [
                    'Analysis',
                    $an['AnalysisId'],
                    $an['Name'],
                    implode('|', $sf),
                    implode('|', $owners),
                    $group ?? 'Untagged',
                    implode('; ', $other),
                    implode('|', $dashList),
                    '', // no analyses list for analyses
                    '', // no dependent datasets here
                ], ',', '"', '\\');
            }
            $token = $resp['NextToken'] ?? null;
        } while ($token);
        $this->write("Processed $cnt analyses");
    }

    protected function getDashboardOwners(string $dashboardId): array
    {
        try {
            $resp = QuickSightHelper::executeWithRetry($this->quickSight, 'describeDashboardPermissions', [
                'AwsAccountId' => $this->awsAccountId,
                'DashboardId' => $dashboardId,
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
                'DataSetId' => $datasetId,
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
                'AnalysisId' => $analysisId,
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
