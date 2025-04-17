<?php

namespace QSAssetManager\Manager\Dataset;

use QSAssetManager\Manager\AssetManager;
use QSAssetManager\Utils\QuickSightHelper;
use Aws\Exception\AwsException;

/**
 * Manager for QuickSight Dataset operations
 */
class DatasetManager extends AssetManager
{
    protected function getAssetDefinition(string $datasetId): ?array
    {
        try {
            $response = QuickSightHelper::executeWithRetry($this->quickSight, 'describeDataSet', [
                'AwsAccountId' => $this->awsAccountId,
                'DataSetId'    => $datasetId,
            ]);
            return $response->toArray();
        } catch (AwsException $e) {
            echo "❌ Error describing dataset: " . $e->getMessage() . "\n";
            return null;
        }
    }

    protected function updateAsset(string $datasetId, array $definition, string $name = ''): bool
    {
        try {
            $params = [
                'AwsAccountId'     => $this->awsAccountId,
                'DataSetId'        => $datasetId,
                'Name'             => $name ?: $definition['Name'] ?? 'Updated Dataset',
                'PhysicalTableMap' => $definition['PhysicalTableMap'],
                'LogicalTableMap'  => $definition['LogicalTableMap'] ?? [],
                'OutputColumns'    => $definition['OutputColumns']    ?? [],
                'ImportMode'       => $definition['ImportMode']       ?? 'SPICE',
            ];

            $this->quickSight->updateDataSet($params);
            echo "✅ Dataset updated successfully.\n";
            return true;
        } catch (AwsException $e) {
            echo "❌ Error updating dataset: " . $e->getMessage() . "\n";
            return false;
        }
    }

    protected function getAssetName(string $datasetId): string
    {
        try {
            $response = QuickSightHelper::executeWithRetry($this->quickSight, 'describeDataSet', [
                'AwsAccountId' => $this->awsAccountId,
                'DataSetId'    => $datasetId,
            ]);
            return $response['Name'] ?? 'Updated Dataset';
        } catch (AwsException $e) {
            echo "⚠ Warning: Could not retrieve dataset name: " . $e->getMessage() . "\n";
            return 'Updated Dataset';
        }
    }

    protected function getAssetArn(string $datasetId): string
    {
        return "arn:aws:quicksight:{$this->awsRegion}:{$this->awsAccountId}:dataset/{$datasetId}";
    }

    public function renameDataset(string $datasetId, string $newName): bool
    {
        $definition = $this->getAssetDefinition($datasetId);
        if (!$definition) {
            return false;
        }

        echo "Renaming dataset '{$definition['Name']}' → '{$newName}'\n";
        return $this->updateAsset($datasetId, $definition, $newName);
    }

    public function deployDataset(array $config): bool
    {
        // Load template from file if provided
        if (!isset($config['template']) && isset($config['TemplateFilePath'])) {
            $templatePath = $config['TemplateFilePath'];
            if (!file_exists($templatePath)) {
                echo "❌ Template file not found: $templatePath\n";
                return false;
            }
            $content = file_get_contents($templatePath);
            if ($content === false) {
                echo "❌ Failed to read template: $templatePath\n";
                return false;
            }
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                echo "❌ Invalid JSON in template: $templatePath\n";
                return false;
            }
            $config['template'] = $parsed['DataSet'] ?? $parsed;
        }

        if (!isset($config['template'])) {
            echo "❌ No template provided in config.\n";
            return false;
        }

        $dataset       = $config['template'];
        $dataset['Name']   = $config['Name'];
        $newDatasetId  = $config['DataSetId'] ?? QuickSightHelper::generateUuid();

        // capture original definition if updating
        $isUpdate = false;
        $originalDefinition = null;
        try {
            $orig = $this->quickSight->describeDataSet([
                'AwsAccountId' => $config['DestinationAwsAccountId'],
                'DataSetId'    => $newDatasetId,
            ]);
            $isUpdate = true;
            $originalDefinition = $orig->toArray()['DataSet'] ?? null;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                echo "❌ Error describing existing dataset: " . $e->getMessage() . "\n";
                return false;
            }
        }

        // Override DataSourceArn if provided
        if (!empty($config['DataSourceArn']) && isset($dataset['PhysicalTableMap'])) {
            foreach ($dataset['PhysicalTableMap'] as $key => $table) {
                if (isset($table['RelationalTable'])) {
                    $dataset['PhysicalTableMap'][$key]['RelationalTable']['DataSourceArn']
                        = $config['DataSourceArn'];
                }
                if (isset($table['CustomSql'])) {
                    $dataset['PhysicalTableMap'][$key]['CustomSql']['DataSourceArn']
                        = $config['DataSourceArn'];
                }
            }
            echo "🔄 Overrode PhysicalTableMap to use DataSource {$config['DataSourceArn']}\n";
        }

        // build params
        $params = [
            'AwsAccountId'     => $config['DestinationAwsAccountId'],
            'DataSetId'        => $newDatasetId,
            'Name'             => $dataset['Name'],
            'PhysicalTableMap' => $dataset['PhysicalTableMap'],
            'LogicalTableMap'  => $dataset['LogicalTableMap'] ?? [],
            'OutputColumns'    => $dataset['OutputColumns']   ?? [],
            'ImportMode'       => $dataset['ImportMode']      ?? 'SPICE',
        ];

        // create or update
        $created = false;
        try {
            if ($isUpdate) {
                $this->quickSight->updateDataSet($params);
                echo "📝 Updated dataset: $newDatasetId\n";
            } else {
                $this->quickSight->createDataSet($params);
                echo "📦 Created dataset: $newDatasetId\n";
                $created = true;
            }
        } catch (AwsException $e) {
            echo "❌ Deployment error: " . $e->getMessage() . "\n";
            return false;
        }

        // validation: describe and compare
        try {
            $desc = $this->quickSight->describeDataSet([
                'AwsAccountId' => $config['DestinationAwsAccountId'],
                'DataSetId'    => $newDatasetId,
            ])['DataSet'];
        } catch (AwsException $e) {
            echo "❌ Error during validation describe: " . $e->getMessage() . "\n";
            // rollback
            if ($created) {
                $this->quickSight->deleteDataSet([
                    'AwsAccountId' => $config['DestinationAwsAccountId'],
                    'DataSetId'    => $newDatasetId,
                ]);
                echo "⏪ Rolled back created dataset: $newDatasetId\n";
            } elseif ($originalDefinition) {
                $this->updateAsset($newDatasetId, $originalDefinition);
                echo "⏪ Rolled back update on dataset: $newDatasetId\n";
            }
            return false;
        }

        // ensure DataSourceArn matches
        if (!empty($config['DataSourceArn'])) {
            $mismatch = false;
            foreach ($desc['PhysicalTableMap'] as $table) {
                if (
                    isset($table['RelationalTable']) &&
                    $table['RelationalTable']['DataSourceArn'] !== $config['DataSourceArn']
                ) {
                    $mismatch = true;
                }
                if (
                    isset($table['CustomSql']) &&
                    $table['CustomSql']['DataSourceArn'] !== $config['DataSourceArn']
                ) {
                    $mismatch = true;
                }
            }
            if ($mismatch) {
                echo "❌ Validation failed: DataSourceArn mismatch after deploy\n";
                // rollback
                if ($created) {
                    $this->quickSight->deleteDataSet([
                        'AwsAccountId' => $config['DestinationAwsAccountId'],
                        'DataSetId'    => $newDatasetId,
                    ]);
                    echo "⏪ Rolled back created dataset: $newDatasetId\n";
                } elseif ($originalDefinition) {
                    $this->updateAsset($newDatasetId, $originalDefinition);
                    echo "⏪ Rolled back update on dataset: $newDatasetId\n";
                }
                return false;
            }
        }

        echo "✅ Validation passed: DataSourceArn is correct\n";

        // post-deploy steps
        $this->handleRefreshProperties($newDatasetId, $config);
        $this->handleRefreshSchedules($newDatasetId, $config);
        $this->applyPermissionsAndTags($newDatasetId, $config);

        return true;
    }

    private function handleRefreshProperties(string $datasetId, array $config): void
    {
        if (empty($config['template']['DataSetRefreshProperties'])) {
            return;
        }
        try {
            $this->quickSight->deleteDataSetRefreshProperties([
                'AwsAccountId' => $config['DestinationAwsAccountId'],
                'DataSetId'    => $datasetId,
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'InvalidParameterValueException') {
                echo "⚠ Error deleting existing refresh properties: " . $e->getMessage() . "\n";
            }
        }
        try {
            $this->quickSight->putDataSetRefreshProperties([
                'AwsAccountId'             => $config['DestinationAwsAccountId'],
                'DataSetId'                => $datasetId,
                'DataSetRefreshProperties' => $config['template']['DataSetRefreshProperties'],
            ]);
            echo "✅ Refresh properties set.\n";
        } catch (AwsException $e) {
            echo "❌ Error setting refresh properties: " . $e->getMessage() . "\n";
        }
    }

    private function handleRefreshSchedules(string $datasetId, array $config): void
    {
        $schedules = $config['template']['RefreshSchedules'] ?? [];
        if (empty($schedules)) {
            return;
        }
        try {
            $existing = $this->quickSight->listRefreshSchedules([
                'AwsAccountId' => $config['DestinationAwsAccountId'],
                'DataSetId'    => $datasetId,
            ])['RefreshSchedules'] ?? [];
            foreach ($existing as $schedule) {
                $this->quickSight->deleteRefreshSchedule([
                    'AwsAccountId' => $config['DestinationAwsAccountId'],
                    'DataSetId'    => $datasetId,
                    'ScheduleId'   => $schedule['ScheduleId'],
                ]);
            }
        } catch (AwsException $e) {
            echo "⚠ Could not list or delete existing refresh schedules: " . $e->getMessage() . "\n";
        }
        sleep(2);
        foreach ($schedules as $schedule) {
            $scheduleId = QuickSightHelper::generateUuid();
            try {
                $this->quickSight->createRefreshSchedule([
                    'AwsAccountId' => $config['DestinationAwsAccountId'],
                    'DataSetId'    => $datasetId,
                    'Schedule'     => [
                        'ScheduleId'         => $scheduleId,
                        'RefreshType'        => $schedule['RefreshType'],
                        'ScheduleFrequency'  => $schedule['ScheduleFrequency'],
                        'StartAfterDateTime' => $schedule['StartAfterDateTime'],
                    ],
                ]);
                echo "🕒 Refresh schedule created: $scheduleId\n";
            } catch (Aws\Exception\AwsException $e) {
                echo "❌ Error creating refresh schedule: " . $e->getMessage() . "\n";
            }
        }
    }

    private function applyPermissionsAndTags(string $datasetId, array $config): void
    {
        $accountId = $config['DestinationAwsAccountId'];
        $arn       = "arn:aws:quicksight:{$this->awsRegion}:{$accountId}:dataset/{$datasetId}";
        if (!empty($config['DefaultPermissions'])) {
            try {
                $this->quickSight->updateDataSetPermissions([
                    'AwsAccountId'     => $accountId,
                    'DataSetId'        => $datasetId,
                    'GrantPermissions' => $config['DefaultPermissions'],
                ]);
                echo "🔐 Dataset permissions updated.\n";
            } catch (Aws\Exception\AwsException $e) {
                echo "⚠ Permissions update failed: " . $e->getMessage() . "\n";
            }
        }
        if (!empty($config['Tags'])) {
            $tags = array_map(
                fn($k, $v) => ['Key' => $k, 'Value' => $v],
                array_keys($config['Tags']),
                $config['Tags']
            );
            try {
                $this->quickSight->tagResource([
                    'ResourceArn' => $arn,
                    'Tags'        => $tags,
                ]);
                echo "🏷️ Dataset tagged successfully.\n";
            } catch (Aws\Exception\AwsException $e) {
                echo "⚠ Tagging error: " . $e->getMessage() . "\n";
            }
        }
    }
}
