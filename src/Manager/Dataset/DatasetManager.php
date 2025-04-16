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
                'OutputColumns'    => $definition['OutputColumns'] ?? [],
                'ImportMode'       => $definition['ImportMode'] ?? 'SPICE',
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
        // Load template from file if TemplateFilePath is specified
        if (!isset($config['template']) && isset($config['TemplateFilePath'])) {
            $templatePath = $config['TemplateFilePath'];
            if (!file_exists($templatePath)) {
                echo "❌ Template file not found at $templatePath\n";
                return false;
            }

            $templateContent = file_get_contents($templatePath);
            if (!$templateContent) {
                echo "❌ Failed to read template file content.\n";
                return false;
            }

            $parsed = json_decode($templateContent, true);
            if (!is_array($parsed)) {
                echo "❌ Invalid JSON in template file: $templatePath\n";
                return false;
            }

            // Accept top-level key "DataSet" (as returned by describeDataSet) or raw template
            $config['template'] = $parsed['DataSet'] ?? $parsed;
        }

        if (!isset($config['template'])) {
            echo "❌ Deployment config missing 'template' key and no TemplateFilePath provided.\n";
            return false;
        }

        $dataset = $config['template'];
        $dataset['Name'] = $config['Name'];
        $newDatasetId = $config['DataSetId'] ?? QuickSightHelper::generateUuid();

        $params = [
            'AwsAccountId'     => $config['DestinationAwsAccountId'],
            'DataSetId'        => $newDatasetId,
            'Name'             => $dataset['Name'],
            'PhysicalTableMap' => $dataset['PhysicalTableMap'],
            'LogicalTableMap'  => $dataset['LogicalTableMap'] ?? [],
            'OutputColumns'    => $dataset['OutputColumns'] ?? [],
            'ImportMode'       => $dataset['ImportMode'] ?? 'SPICE',
        ];

        try {
            $this->quickSight->describeDataSet([
                'AwsAccountId' => $config['DestinationAwsAccountId'],
                'DataSetId'    => $newDatasetId,
            ]);
            $response = $this->quickSight->updateDataSet($params);
            echo "📝 Dataset updated: $newDatasetId\n";
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $response = $this->quickSight->createDataSet($params);
                echo "📦 Dataset created: $newDatasetId\n";
            } else {
                echo "❌ Dataset deployment error: " . $e->getMessage() . "\n";
                return false;
            }
        }

        $this->handleRefreshProperties($newDatasetId, $config);
        $this->handleRefreshSchedules($newDatasetId, $config);
        $this->applyPermissionsAndTags($newDatasetId, $config);

        return true;
    }


    private function handleRefreshProperties(string $datasetId, array $config): void
    {
        if (!isset($config['template']['DataSetRefreshProperties'])) {
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

        sleep(2); // allow propagation

        foreach ($schedules as $schedule) {
            $scheduleId = QuickSightHelper::generateUuid();
            try {
                $this->quickSight->createRefreshSchedule([
                    'AwsAccountId' => $config['DestinationAwsAccountId'],
                    'DataSetId'    => $datasetId,
                    'Schedule'     => [
                        'ScheduleId'        => $scheduleId,
                        'RefreshType'       => $schedule['RefreshType'],
                        'ScheduleFrequency' => $schedule['ScheduleFrequency'],
                        'StartAfterDateTime' => $schedule['StartAfterDateTime'],
                    ],
                ]);
                echo "🕒 Refresh schedule created: $scheduleId\n";
            } catch (AwsException $e) {
                echo "❌ Error creating refresh schedule: " . $e->getMessage() . "\n";
            }
        }
    }

    private function applyPermissionsAndTags(string $datasetId, array $config): void
    {
        $accountId = $config['DestinationAwsAccountId'];
        $arn = "arn:aws:quicksight:{$this->awsRegion}:{$accountId}:dataset/{$datasetId}";

        if (!empty($config['DefaultPermissions'])) {
            try {
                $this->quickSight->updateDataSetPermissions([
                    'AwsAccountId'     => $accountId,
                    'DataSetId'        => $datasetId,
                    'GrantPermissions' => $config['DefaultPermissions'],
                ]);
                echo "🔐 Dataset permissions updated.\n";
            } catch (AwsException $e) {
                echo "⚠ Permissions update failed: " . $e->getMessage() . "\n";
            }
        }

        if (!empty($config['Tags'])) {
            $tags = array_map(fn($k, $v) => ['Key' => $k, 'Value' => $v], array_keys($config['Tags']), $config['Tags']);
            try {
                $this->quickSight->tagResource([
                    'ResourceArn' => $arn,
                    'Tags'        => $tags,
                ]);
                echo "🏷️ Dataset tagged successfully.\n";
            } catch (AwsException $e) {
                echo "⚠ Tagging error: " . $e->getMessage() . "\n";
            }
        }
    }
}
