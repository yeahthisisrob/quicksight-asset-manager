<?php
// src/Manager/Reporting/IngestionDetailsReportingManager.php

namespace QSAssetManager\Manager\Reporting;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\TaggingHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class IngestionDetailsReportingManager
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

    public function exportIngestionDetailsReport(?string $outputPath = null): bool|string
    {
        $this->write("Starting ingestion details report...");
        $ts   = date('Ymd_His');
        $base = $outputPath
            ?? ($this->config['paths']['report_export_path'] ?? getcwd() . '/exports');
        $exportDir = rtrim($base, DIRECTORY_SEPARATOR);

        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
            $this->write("Created export directory: $exportDir");
        }

        $file = "{$exportDir}/quicksight_ingestion_details_{$ts}.csv";
        $fh   = fopen($file, 'w');
        if (!$fh) {
            $this->write("Error: Unable to create output file", 'error');
            return false;
        }

        // CSV header
        fputcsv($fh, [
            'DataSetId','DataSetName','Group','OtherTags',
            'IngestionId','CreatedTime','IngestionStatus','IngestionTimeInSeconds',
            'HasSchedule','HasIncrementalRefresh','ScheduleFrequencies',
            'ErrorMessage','ErrorType',
            'RowsDropped','RowsIngested','TotalRowsInDataset',
        ], ',', '"', '\\');

        $cutoff = new \DateTimeImmutable('-30 days');

        // 1) List all SPICE datasets
        $datasets = QuickSightHelper::paginate(
            client:       $this->quickSight,
            awsAccountId: $this->awsAccountId,
            operation:    'listDataSets',
            listKey:      'DataSetSummaries'
        );

        foreach ($datasets as $ds) {
            if (strtoupper($ds['ImportMode'] ?? '') !== 'SPICE') {
                continue;
            }

            // Tags & Group
            $arn   = $ds['Arn'] ?? '';
            $tags  = TaggingHelper::getResourceTags($this->quickSight, $arn);
            $group = TaggingHelper::getGroupTag(
                tags:   $tags,
                tagKey:$this->config['tagging']['default_key'] ?? 'group'
            );
            $other = [];
            foreach ($tags as $t) {
                if (strcasecmp($t['Key'], $this->config['tagging']['default_key'] ?? 'group') !== 0) {
                    $other[] = "{$t['Key']}={$t['Value']}";
                }
            }
            $otherStr = implode('; ', $other);

            // Refresh schedules
            try {
                $scheduleResp      = QuickSightHelper::executeWithRetry(
                    client: $this->quickSight,
                    method: 'listRefreshSchedules',
                    params: [
                        'AwsAccountId' => $this->awsAccountId,
                        'DataSetId'    => $ds['DataSetId'],
                    ]
                );
                $refreshSchedules = $scheduleResp['RefreshSchedules'] ?? [];
            } catch (AwsException $e) {
                $this->write(
                    "Warning: could not list refresh schedules for {$ds['DataSetId']}: {$e->getMessage()}",
                    'warning'
                );
                $refreshSchedules = [];
            }

            $hasSchedule    = !empty($refreshSchedules);
            $hasIncremental = false;
            $freqs          = [];
            foreach ($refreshSchedules as $s) {
                if (($s['RefreshType'] ?? '') === 'INCREMENTAL_REFRESH') {
                    $hasIncremental = true;
                }
                $sf = $s['ScheduleFrequency'] ?? [];
                $freqs[] = trim((string)($sf['Interval']    ?? '')
                            .' at '.($sf['TimeOfTheDay'] ?? '')
                            .' '.($sf['Timezone']     ?? ''));
            }
            $scheduleFreqStr = implode('; ', array_unique($freqs));

            // 2) List ingestions via executeWithRetry(), avoid DescribeIngestion
            $token = null;
            do {
                $params = [
                    'AwsAccountId' => $this->awsAccountId,
                    'DataSetId'    => $ds['DataSetId'],
                ];
                if ($token) {
                    $params['NextToken'] = $token;
                }

                try {
                    $resp = QuickSightHelper::executeWithRetry(
                        client: $this->quickSight,
                        method: 'listIngestions',
                        params: $params
                    );
                } catch (AwsException $e) {
                    $this->write(
                        "Warning: failed to list ingestions for {$ds['DataSetId']}: {$e->getMessage()}",
                        'warning'
                    );
                    break;
                }

                foreach ($resp['Ingestions'] ?? [] as $ing) {
                    if (empty($ing['CreatedTime'])) {
                        continue;
                    }
                    $created = new \DateTimeImmutable($ing['CreatedTime']);
                    if ($created < $cutoff) {
                        continue;
                    }

                    $errorInfo    = $ing['ErrorInfo']       ?? [];
                    $rowInfo      = $ing['RowInfo']         ?? [];

                    $errorMessage = $errorInfo['Message']   ?? '';
                    $errorType    = $errorInfo['Type']      ?? '';
                    $rowsDropped  = $rowInfo['RowsDropped'] ?? '';
                    $rowsIngested = $rowInfo['RowsIngested'] ?? '';
                    $totalRows    = $rowInfo['TotalRowsInDataset'] ?? '';

                    fputcsv($fh, [
                        $ds['DataSetId'],                        // DataSetId
                        $ds['Name']                ?? '',        // DataSetName
                        $group   ?: 'Untagged',                 // Group
                        $otherStr,                              // OtherTags
                        $ing['IngestionId'],                    // IngestionId
                        $created->format(\DateTime::ATOM),     // CreatedTime
                        $ing['IngestionStatus']    ?? '',       // Status
                        $ing['IngestionTimeInSeconds'] ?? '',   // Latency
                        $hasSchedule    ? 'TRUE'   : 'FALSE',   // HasSchedule
                        $hasIncremental ? 'TRUE'   : 'FALSE',   // HasIncremental
                        $scheduleFreqStr,                       // Frequencies
                        $errorMessage,                          // ErrorMessage
                        $errorType,                             // ErrorType
                        $rowsDropped,                           // RowsDropped
                        $rowsIngested,                          // RowsIngested
                        $totalRows,                             // TotalRows
                    ], ',', '"', '\\');
                }

                $token = $resp['NextToken'] ?? null;
            } while ($token);
        }

        fclose($fh);
        $this->write("Ingestion details report generated at: $file", 'success');
        return $file;
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
