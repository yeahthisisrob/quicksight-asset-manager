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
    public function __construct(
        protected array          $config,
        protected QuickSightClient $quickSight,
        protected string         $awsAccountId,
        protected string         $awsRegion,
        protected ?SymfonyStyle  $io = null
    ) {}

    /**
     * @param string|null $outputPath Directory to write CSV into
     * @return string|false Path to CSV on success, false on failure
     */
    public function exportIngestionDetailsReport(?string $outputPath = null): bool|string
    {
        $this->write("Starting ingestion details report...");
        $ts        = date('Ymd_His');
        $base      = $outputPath
            ?? ($this->config['paths']['report_export_path'] ?? getcwd().'/exports');
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

        // CSV header (explicit escape parameter added)
        fputcsv(
            $fh,
            [
                'DataSetId','DataSetName','Group','OtherTags',
                'IngestionId','CreatedTime','IngestionStatus','IngestionTimeInSeconds'
            ],
            ',', '"', '\\'
        );

        // Cutoff = now - 30 days
        $cutoff = new \DateTimeImmutable('-30 days');

        // 1) list all datasets
        $datasets = QuickSightHelper::paginate(
            client:        $this->quickSight,
            awsAccountId:  $this->awsAccountId,
            operation:     'listDataSets',
            listKey:       'DataSetSummaries'
        );

        foreach ($datasets as $ds) {
            // only SPICE
            if (strtoupper($ds['ImportMode'] ?? '') !== 'SPICE') {
                continue;
            }
            $dsId   = $ds['DataSetId'];
            $dsName = $ds['Name'] ?? '';
            $arn    = $ds['Arn']  ?? '';

            // fetch tags & group
            $tags  = TaggingHelper::getResourceTags($this->quickSight, $arn);
            $group = TaggingHelper::getGroupTag(
                tags:   $tags,
                tagKey: $this->config['tagging']['default_key'] ?? 'group'
            );
            $other = [];
            foreach ($tags as $t) {
                if (strcasecmp($t['Key'], $this->config['tagging']['default_key'] ?? 'group') !== 0) {
                    $other[] = "{$t['Key']}={$t['Value']}";
                }
            }
            $otherStr = implode('; ', $other);

            // 2) list ingestions **with DataSetId** and paging
            $ingestions = [];
            $token      = null;
            do {
                try {
                    $params = [
                        'AwsAccountId' => $this->awsAccountId,
                        'DataSetId'    => $dsId,
                    ];
                    if ($token) {
                        $params['NextToken'] = $token;
                    }
                    $resp = QuickSightHelper::executeWithRetry(
                        client: $this->quickSight,
                        method: 'listIngestions',
                        params: $params
                    );
                    $ingestions = array_merge(
                        $ingestions,
                        $resp['Ingestions'] ?? []
                    );
                    $token = $resp['NextToken'] ?? null;
                } catch (AwsException $e) {
                    $this->write(
                        "Error listing ingestions for {$dsId}: {$e->getMessage()}",
                        'warning'
                    );
                    break;
                }
            } while ($token);

            foreach ($ingestions as $ing) {
                // 3) only last 30 days
                if (empty($ing['CreatedTime'])) {
                    continue;
                }
                $created = new \DateTimeImmutable($ing['CreatedTime']);
                if ($created < $cutoff) {
                    continue;
                }

                $ingId   = $ing['IngestionId'] ?? '';
                $status  = $ing['IngestionStatus'] ?? '';
                $latency = $ing['IngestionTimeInSeconds'] ?? null;

                // 4) if latency missing, describe it
                if ($latency === null) {
                    try {
                        $detail = QuickSightHelper::executeWithRetry(
                            client: $this->quickSight,
                            method: 'describeIngestion',
                            params: [
                                'AwsAccountId'=> $this->awsAccountId,
                                'DataSetId'   => $dsId,
                                'IngestionId' => $ingId
                            ]
                        );
                        $def     = $detail['Ingestion'] ?? [];
                        $status  = $def['IngestionStatus'] ?? $status;
                        $latency = $def['IngestionTimeInSeconds'] ?? '';
                    } catch (AwsException) {
                        // swallow individual failures
                    }
                }

                // write row (explicit escape parameter)
                fputcsv(
                    $fh,
                    [
                        $dsId,
                        $dsName,
                        $group   ?? 'Untagged',
                        $otherStr,
                        $ingId,
                        $created->format(\DateTime::ATOM),
                        $status,
                        $latency
                    ],
                    ',', '"', '\\'
                );
            }
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
