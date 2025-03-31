<?php

namespace QSAssetManager\Manager\Exploration\Analysis;

use QSAssetManager\Manager\Exploration\ExplorationAssetManager;
use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;

/**
 * Manager for QuickSight Analysis operations
 */
class AnalysisManager extends ExplorationAssetManager
{
    /**
     * Get the analysis definition
     *
     * @param string $analysisId The analysis ID
     * @return array|null The analysis definition or null if an error occurred
     */
    protected function getAssetDefinition(string $analysisId): ?array
    {
        try {
            $definitionResponse = QuickSightHelper::executeWithRetry($this->quickSight, 'describeAnalysisDefinition', [
                'AwsAccountId' => $this->awsAccountId,
                'AnalysisId'   => $analysisId,
            ]);
        } catch (AwsException $e) {
            echo "Error describing analysis definition: " . $e->getMessage() . "\n";
            return null;
        }

        $responseArray = $definitionResponse->toArray();

        if (!isset($responseArray['Definition'])) {
            echo "Analysis definition missing 'Definition' member.\n";
            return null;
        }

        return $responseArray['Definition'];
    }

    /**
     * Update the analysis with a new definition
     *
     * @param string $analysisId The analysis ID
     * @param array $definition The updated definition
     * @param string $name The name of the analysis
     * @return bool True if successful, false otherwise
     */
    protected function updateAsset(string $analysisId, array $definition, string $name = ''): bool
    {
        if (empty($name)) {
            $name = $this->getAssetName($analysisId);
        }

        $updateParams = [
            'AwsAccountId' => $this->awsAccountId,
            'AnalysisId'   => $analysisId,
            'Name'         => $name,
            'Definition'   => $definition,
        ];

        try {
            $updateResponse = QuickSightHelper::executeWithRetry($this->quickSight, 'updateAnalysis', $updateParams);
            echo "Analysis updated successfully.\n";
            return true;
        } catch (AwsException $e) {
            echo "Error updating analysis: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Get the analysis name
     *
     * @param string $analysisId The analysis ID
     * @return string The analysis name or a default name
     */
    protected function getAssetName(string $analysisId): string
    {
        try {
            $describeResponse = QuickSightHelper::executeWithRetry($this->quickSight, 'describeAnalysis', [
                'AwsAccountId' => $this->awsAccountId,
                'AnalysisId'   => $analysisId,
            ]);

            if (isset($describeResponse['Analysis']['Name'])) {
                return $describeResponse['Analysis']['Name'];
            }
        } catch (AwsException $e) {
            echo "Warning: Could not get analysis name: " . $e->getMessage() . "\n";
        }

        return "Updated Analysis";
    }

    /**
     * Get the analysis ARN
     *
     * @param string $analysisId The analysis ID
     * @return string The analysis ARN
     */
    protected function getAssetArn(string $analysisId): string
    {
        return "arn:aws:quicksight:" . $this->awsRegion . ":" . $this->awsAccountId . ":analysis/" . $analysisId;
    }

    /**
     * Rename an analysis
     *
     * @param string $analysisId The analysis ID
     * @param string $newName The new name for the analysis
     * @return bool True if successful, false otherwise
     */
    public function renameAnalysis(string $analysisId, string $newName): bool
    {
        $definition = $this->getAssetDefinition($analysisId);
        if ($definition === null) {
            return false;
        }

        $updateParams = [
            'AwsAccountId' => $this->awsAccountId,
            'AnalysisId'   => $analysisId,
            'Name'         => $newName,
            'Definition'   => $definition,
        ];

        try {
            $updateResponse = QuickSightHelper::executeWithRetry($this->quickSight, 'updateAnalysis', $updateParams);
            echo "Analysis updated successfully with new name: $newName\n";

            echo "Analysis ID: $analysisId\n";
            echo "Analysis ARN: " . $this->getAssetArn($analysisId) . "\n";
            return true;
        } catch (AwsException $e) {
            echo "Error updating analysis: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Removes broken DataSetIdentifiers from the analysis
     *
     * @param string $analysisId The analysis ID
     * @return bool True if successful, false otherwise
     */
    public function removeBrokenDataSetIdentifiers(string $analysisId): bool
    {
        // Get analysis definition
        $definition = $this->getAssetDefinition($analysisId);
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
        $name = $this->getAssetName($analysisId);

        // Update the asset with the cleaned definition
        return $this->updateAsset($analysisId, $definition, $name);
    }

    /**
     * Find all DataSetIdentifiers used in the definition
     *
     * @param array $definition The analysis definition
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
     * Deploy an analysis from a combined deployment config.
     *
     * The deployment config must include a "template" key that contains the full analysis definition,
     * plus any overrides.
     *
     * @param array $deploymentConfig
     * @return bool True on success.
     */
    public function deployAnalysis(array $deploymentConfig): bool
    {
        if (!isset($deploymentConfig['template'])) {
            echo "Deployment config missing 'template' key.\n";
            return false;
        }
        $analysis = $deploymentConfig['template'];

        // Override the analysis name.
        $analysis['Name'] = $deploymentConfig['Name'] . " (deployed)";

        // Set or generate AnalysisId.
        if (empty($deploymentConfig['AnalysisId'])) {
            $deploymentConfig['AnalysisId'] = QuickSightHelper::generateUuid();
        }
        $newAnalysisId = $deploymentConfig['AnalysisId'];

        // (If needed, process dataset declarations or string replacements here.)
        // For simplicity, assume analyses do not need DataSetIdentifierDeclarations updates.
        if (isset($deploymentConfig['StringReplacements']) && is_array($deploymentConfig['StringReplacements'])) {
            QuickSightHelper::updateStringReplacements($analysis, $deploymentConfig['StringReplacements']);
        }

        $analysisParams = [
            'AwsAccountId' => $deploymentConfig['DestinationAwsAccountId'],
            'AnalysisId'   => $newAnalysisId,
            'Name'         => $analysis['Name'],
            'Definition'   => isset($analysis['Definition']) ? $analysis['Definition'] : $analysis,
        ];

        try {
            $this->quickSight->describeAnalysis([
                'AwsAccountId' => $deploymentConfig['DestinationAwsAccountId'],
                'AnalysisId'   => $newAnalysisId,
            ]);
            echo "Analysis exists. Updating analysis...\n";
            $response = $this->quickSight->updateAnalysis($analysisParams);
            echo "Analysis updated successfully with AnalysisId: $newAnalysisId\n";
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $response = $this->quickSight->createAnalysis($analysisParams);
                echo "Analysis created successfully with AnalysisId: $newAnalysisId\n";
            } else {
                echo "Error describing/updating analysis: " . $e->getMessage() . "\n";
                return false;
            }
        }

        // Update permissions if provided.
        if (isset($deploymentConfig['DefaultPermissions']) && is_array($deploymentConfig['DefaultPermissions'])) {
            try {
                $this->quickSight->updateAnalysisPermissions([
                    'AwsAccountId'     => $deploymentConfig['DestinationAwsAccountId'],
                    'AnalysisId'       => $newAnalysisId,
                    'GrantPermissions' => $deploymentConfig['DefaultPermissions'],
                ]);
                echo "Analysis permissions updated successfully.\n";
            } catch (AwsException $e) {
                echo "Error updating analysis permissions: " . $e->getMessage() . "\n";
            }
        }

        // Tag the analysis if provided.
        if (isset($deploymentConfig['Tags']) && is_array($deploymentConfig['Tags'])) {
            $tags = [];
            foreach ($deploymentConfig['Tags'] as $key => $value) {
                $tags[] = ['Key' => $key, 'Value' => $value];
            }
            $analysisArn = "arn:aws:quicksight:" .
                $deploymentConfig['AwsRegion'] . ":" .
                $deploymentConfig['DestinationAwsAccountId'] . ":analysis/" .
                $newAnalysisId;
            try {
                $this->quickSight->tagResource([
                    'ResourceArn' => $analysisArn,
                    'Tags'        => $tags,
                ]);
                echo "Analysis tagged successfully.\n";
            } catch (AwsException $e) {
                echo "Error tagging analysis: " . $e->getMessage() . "\n";
            }
        }

        echo "New Analysis ID: $newAnalysisId\n";
        echo "New Analysis ARN: arn:aws:quicksight:" .
             $deploymentConfig['AwsRegion'] . ":" .
             $deploymentConfig['DestinationAwsAccountId'] . ":analysis/" .
             $newAnalysisId . "\n";

        return true;
    }
}
