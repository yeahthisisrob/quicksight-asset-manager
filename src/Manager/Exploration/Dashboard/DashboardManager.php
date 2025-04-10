<?php

namespace QSAssetManager\Manager\Exploration\Dashboard;

use QSAssetManager\Manager\Exploration\ExplorationAssetManager;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;

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
    /**
     * Deploy a dashboard from a combined deployment config.
     *
     * Supports either "template" (inline) or "TemplateFilePath" (external file).
     *
     * @param array $deploymentConfig
     * @return bool True on success, false otherwise.
     */
    public function deployDashboard(array $deploymentConfig): bool
    {
        if (isset($deploymentConfig['TemplateFilePath'])) {
            $path = $deploymentConfig['TemplateFilePath'];
            if (!file_exists($path)) {
                echo "❌ Template file not found: $path\n";
                return false;
            }

            $json = file_get_contents($path);
            $dashboard = json_decode($json, true);

            if (!is_array($dashboard)) {
                echo "❌ Failed to parse JSON from: $path\n";
                return false;
            }

            if (isset($dashboard['Dashboard'])) {
                $dashboard = $dashboard['Dashboard'];
            }
        } elseif (isset($deploymentConfig['template'])) {
            $dashboard = $deploymentConfig['template'];
        } else {
            echo "❌ Deployment config missing 'template' or 'TemplateFilePath'.\n";
            return false;
        }

        $dashboard['Name'] = $deploymentConfig['Name'];
        $newDashboardId = $deploymentConfig['DashboardId'] ?? QuickSightHelper::generateUuid();

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

        QuickSightHelper::updateDataSetDeclarations($dashboard, $deploymentConfig['DestinationAwsAccountId']);

        if (!empty($deploymentConfig['StringReplacements'])) {
            QuickSightHelper::updateStringReplacements($dashboard, $deploymentConfig['StringReplacements']);
        }

        $dashboardParams = [
            'AwsAccountId' => $deploymentConfig['DestinationAwsAccountId'],
            'DashboardId'  => $newDashboardId,
            'Name'         => $dashboard['Name'],
            'Definition'   => $dashboard['Definition'] ?? $dashboard,
        ];

        try {
            $this->quickSight->describeDashboard([
                'AwsAccountId' => $deploymentConfig['DestinationAwsAccountId'],
                'DashboardId'  => $newDashboardId,
            ]);

            $response = $this->quickSight->updateDashboard($dashboardParams);
            echo "📘 Dashboard updated: $newDashboardId\n";
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $response = $this->quickSight->createDashboard($dashboardParams);
                echo "📗 Dashboard created: $newDashboardId\n";

                $versionNumber = QuickSightHelper::extractVersionNumber($response);
                $statusResult = QuickSightHelper::waitForDashboardUpdateSuccess(
                    $this->quickSight,
                    $deploymentConfig['DestinationAwsAccountId'],
                    $newDashboardId,
                    $versionNumber
                );

                if (!$statusResult['success']) {
                    echo "❌ Dashboard creation failed due to errors:\n";
                    QuickSightHelper::printDashboardCreationErrors($statusResult['errors']);
                    return false;
                }
            } else {
                echo "❌ Dashboard deployment error: " . $e->getMessage() . "\n";
                return false;
            }
        }

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
}
