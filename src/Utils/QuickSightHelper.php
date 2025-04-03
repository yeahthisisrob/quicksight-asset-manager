<?php

namespace QSAssetManager\Utils;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use GuzzleHttp\Promise\Utils;

class QuickSightHelper
{
    /**
     * Generates a version 4 UUID.
     *
     * @return string
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Executes an AWS API call with retry logic for throttling.
     *
     * @param QuickSightClient $client The QuickSight client
     * @param string $method The API method to call
     * @param array $params The parameters for the API call
     * @param int $maxRetries Maximum number of retry attempts
     * @return mixed The response from the API call
     * @throws AwsException
     */
    public static function executeWithRetry(
        QuickSightClient $client,
        string $method,
        array $params,
        int $maxRetries = 5
    ): mixed {
        $attempt = 0;
        while (true) {
            try {
                return $client->$method($params);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() === 'ThrottlingException' && $attempt < $maxRetries) {
                    $attempt++;
                    $delay = (2 ** $attempt) * 1000 + rand(0, 1000);
                    echo "\033[33m⚠ Throttling on $method. Retry #$attempt in " . ($delay / 1000) . "s...\033[0m\n";
                    flush();
                    usleep($delay * 1000);
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Recursively searches for and removes all instances of a specific DataSetIdentifier
     *
     * @param array &$definition The definition to clean
     * @param string $invalidDataSetId The DataSetIdentifier to remove
     * @return bool True if any changes were made
     */
    public static function cleanInvalidDataSetIdentifier(array &$definition, string $invalidDataSetId): bool
    {
        $modified = false;

        // First, remove it from FilterGroups which is the most common location
        if (isset($definition['FilterGroups'])) {
            $filterGroupsToRemove = [];

            foreach ($definition['FilterGroups'] as $index => $filterGroup) {
                $filterGroupId = $filterGroup['FilterGroupId'] ?? '';

                if (!isset($filterGroup['Filters'])) {
                    continue;
                }

                $filtersToRemove = [];
                foreach ($filterGroup['Filters'] as $filterIndex => $filter) {
                    foreach ($filter as $filterType => $filterDetails) {
                        if (
                            isset($filterDetails['Column']['DataSetIdentifier']) &&
                            $filterDetails['Column']['DataSetIdentifier'] === $invalidDataSetId
                        ) {
                            $filterId = $filterDetails['FilterId'] ?? '';
                            echo "Found invalid DataSetIdentifier '{$invalidDataSetId}' in FilterGroup " .
                                "'{$filterGroupId}', Filter '{$filterId}'\n";
                            $filtersToRemove[] = $filterIndex;
                            $modified = true;
                            break;
                        }
                    }
                }

                // Remove invalid filters (in reverse order to maintain indices)
                rsort($filtersToRemove);
                foreach ($filtersToRemove as $filterIndex) {
                    array_splice($definition['FilterGroups'][$index]['Filters'], $filterIndex, 1);
                    echo "Removed invalid filter from FilterGroup '$filterGroupId'\n";
                }

                // If all filters were removed, mark the filter group for removal
                if (empty($definition['FilterGroups'][$index]['Filters'])) {
                    $filterGroupsToRemove[] = $index;
                }
            }

            // Remove empty filter groups (in reverse order to maintain indices)
            rsort($filterGroupsToRemove);
            foreach ($filterGroupsToRemove as $index) {
                $filterGroupId = $definition['FilterGroups'][$index]['FilterGroupId'] ?? '';
                array_splice($definition['FilterGroups'], $index, 1);
                echo "Removed empty FilterGroup '$filterGroupId'\n";
            }

            // If all FilterGroups are removed, remove the FilterGroups key
            if (empty($definition['FilterGroups'])) {
                unset($definition['FilterGroups']);
                echo "Removed empty FilterGroups array\n";
            }
        }

        // Now recursively search the entire definition for any other instances
        self::recursivelyRemoveDataSetIdentifier($definition, $invalidDataSetId, $modified);

        return $modified;
    }

    /**
     * Recursively removes a specific DataSetIdentifier from the definition
     *
     * @param array &$arr The array to clean
     * @param string $invalidDataSetId The DataSetIdentifier to remove
     * @param bool &$modified Will be set to true if changes were made
     */
    private static function recursivelyRemoveDataSetIdentifier(
        array &$arr,
        string $invalidDataSetId,
        bool &$modified
    ): void {
        foreach ($arr as $key => &$value) {
            if ($key === 'DataSetIdentifier' && $value === $invalidDataSetId) {
                // We found it! But we can't safely just remove or replace it
                echo "Found invalid DataSetIdentifier '{$invalidDataSetId}' in definition structure" .
                    "\n";
                $modified = true;
            } elseif (is_array($value)) {
                self::recursivelyRemoveDataSetIdentifier(
                    $value,
                    $invalidDataSetId,
                    $modified
                );
            }
        }
    }

    /**
     * Extracts the version number from a dashboard update response or dashboard description.
     *
     * @param mixed $response The API response (array or Aws\Result)
     * @return int|null The version number or null if not found
     */
    public static function extractVersionNumber($response): ?int
    {
        if ($response instanceof \Aws\Result) {
            $response = $response->toArray();
        }

        // First try from update response
        if (isset($response['VersionArn'])) {
            $parts = explode('/', $response['VersionArn']);
            $lastPart = end($parts);
            if (is_numeric($lastPart)) {
                return (int) $lastPart;
            }
        }

        // Try from dashboard description
        if (isset($response['Dashboard']['Version']['VersionNumber'])) {
            return (int) $response['Dashboard']['Version']['VersionNumber'];
        }

        // Try from version ARN in dashboard description
        if (isset($response['Dashboard']['Version']['Arn'])) {
            $parts = explode('/', $response['Dashboard']['Version']['Arn']);
            $lastPart = end($parts);
            if (is_numeric($lastPart)) {
                return (int) $lastPart;
            }
        }

        return null;
    }

    /**
     * Updates a dashboard's published version.
     *
     * This method does the following:
     *   - Extracts the expected version number from the updateDashboard response.
     *   - Polls describeDashboard until the dashboard status is no longer CREATION_IN_PROGRESS.
     *   - Uses the expected version number (from updateDashboard) to update the published version.
     *
     * @param QuickSightClient $client The QuickSight client
     * @param string $awsAccountId The AWS account ID
     * @param string $dashboardId The dashboard ID
     * @param mixed $updateResponse The response from updateDashboard (optional)
     * @return bool True if successful, false otherwise
     */
    public static function updateDashboardPublishedVersion(
        QuickSightClient $client,
        string $awsAccountId,
        string $dashboardId,
        $updateResponse = null
    ): bool {
        // Extract the expected version from the update response.
        $expectedVersion = null;
        if ($updateResponse) {
            $responseArray = $updateResponse instanceof \Aws\Result ? $updateResponse->toArray() : $updateResponse;
            $expectedVersion = self::extractVersionNumber($responseArray);
            if ($expectedVersion) {
                echo "Expected version number from update response: $expectedVersion\n";
            }
        }

        if (!$expectedVersion) {
            echo "No version number found in update response.\n";
            return false;
        }

        // Poll describeDashboard until the dashboard is no longer in CREATION_IN_PROGRESS.
        $maxAttempts = 10;
        $ready = false;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $descResponse = self::executeWithRetry($client, 'describeDashboard', [
                    'AwsAccountId' => $awsAccountId,
                    'DashboardId' => $dashboardId
                ]);

                if (isset($descResponse['Dashboard']['Version']['Status'])) {
                    $status = $descResponse['Dashboard']['Version']['Status'];
                    echo "Attempt $attempt: Dashboard status is $status\n";
                    if ($status !== 'CREATION_IN_PROGRESS') {
                        $ready = true;
                        break;
                    }
                }
            } catch (AwsException $e) {
                echo "Error checking dashboard version: " . $e->getMessage() . "\n";
                return false;
            }
            sleep(1);
        }

        if (!$ready) {
            echo "Dashboard still in CREATION_IN_PROGRESS after polling; unable to update published version.\n";
            return false;
        }

        // Now that the dashboard is ready, update the published version using the expected version number.
        try {
            self::executeWithRetry($client, 'updateDashboardPublishedVersion', [
                'AwsAccountId' => $awsAccountId,
                'DashboardId' => $dashboardId,
                'VersionNumber' => $expectedVersion
            ]);
            echo "Dashboard published version updated successfully to version $expectedVersion.\n";
            return true;
        } catch (AwsException $e) {
            echo "Error updating dashboard published version: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Updates dataset declarations inside a dashboard definition.
     *
     * It searches for "DataSetIdentifierDeclarations" (inside Definition or top level)
     * and updates each declaration's DataSetArn with the destination account.
     *
     * @param array &$dashboardDef Dashboard definition array.
     * @param string $destAccountId Destination AWS account ID.
     */
    public static function updateDataSetDeclarations(&$dashboardDef, string $destAccountId): void
    {
        if (isset($dashboardDef['Definition']) && isset($dashboardDef['Definition']['DataSetIdentifierDeclarations'])) {
            $decls = &$dashboardDef['Definition']['DataSetIdentifierDeclarations'];
        } elseif (isset($dashboardDef['DataSetIdentifierDeclarations'])) {
            $decls = &$dashboardDef['DataSetIdentifierDeclarations'];
        } else {
            return;
        }
        foreach ($decls as &$dsDecl) {
            if (!isset($dsDecl['DataSetArn'])) {
                continue;
            }
            // ARN format: arn:aws:quicksight:<region>:<account>:dataset/<datasetId>
            $parts = explode(":", $dsDecl['DataSetArn']);
            if (count($parts) < 5) {
                continue;
            }
            $dsDecl['DataSetArn'] =
                "arn:aws:quicksight:" .
                $parts[3] . ":" .
                $destAccountId . ":dataset/" .
                basename($dsDecl['DataSetArn']);
        }
        unset($dsDecl);
    }

    /**
     * Recursively updates all string values by replacing occurrences based on the replacements.
     *
     * @param mixed &$asset The asset definition (or part of it).
     * @param array $replacements Array of replacements with 'find' and 'replace' keys.
     */
    public static function updateStringReplacements(&$asset, array $replacements): void
    {
        if (is_array($asset)) {
            foreach ($asset as &$value) {
                self::updateStringReplacements($value, $replacements);
            }
        } elseif (is_string($asset)) {
            foreach ($replacements as $rep) {
                if (isset($rep['find']) && isset($rep['replace'])) {
                    $asset = str_replace($rep['find'], $rep['replace'], $asset);
                }
            }
        }
    }

    /**
     * Builds a mapping from old dataset identifiers to new ones based on order.
     *
     * @param array $originalDecls Original declarations
     * @param array $configDecls Declarations provided in deployment config
     * @return array Mapping of old => new identifiers
     */
    public static function buildIdentifierMappingFromOrder(array $originalDecls, array $configDecls): array
    {
        $mapping = [];
        $count = min(count($originalDecls), count($configDecls));
        for ($i = 0; $i < $count; $i++) {
            $oldId = $originalDecls[$i]['Identifier'] ?? null;
            $newId = $configDecls[$i]['Identifier'] ?? null;
            if ($oldId && $newId) {
                $mapping[$oldId] = $newId;
            }
        }
        return $mapping;
    }

    /**
     * Recursively updates all keys named "DataSetIdentifier" using a mapping.
     *
     * @param mixed &$asset The asset definition array to update.
     * @param array $mapping Mapping from old identifiers to new ones.
     */
    public static function updateAllDataSetIdentifiers(&$asset, array $mapping): void
    {
        self::recursiveUpdateDataSetIdentifier($asset, $mapping);
    }

    /**
     * Helper: recursively update DataSetIdentifier values.
     */
    private static function recursiveUpdateDataSetIdentifier(&$data, array $mapping): void
    {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if ($key === 'DataSetIdentifier' && is_string($value)) {
                    if (isset($mapping[$value])) {
                        $value = $mapping[$value];
                    }
                } else {
                    self::recursiveUpdateDataSetIdentifier($value, $mapping);
                }
            }
        }
    }

    /**
     * Waits for dashboard version creation to reach a terminal state.
     *
     * @param QuickSightClient $client
     * @param string $awsAccountId
     * @param string $dashboardId
     * @param int $versionNumber
     * @param int $maxAttempts
     * @param int $delaySeconds
     * @return array ['success' => bool, 'versionNumber' => int, 'errors' => array]
     */
    public static function waitForDashboardUpdateSuccess(
        QuickSightClient $client,
        string $awsAccountId,
        string $dashboardId,
        int $versionNumber,
        int $maxAttempts = 8,
        int $delaySeconds = 3
    ): array {
        echo "🔍 Waiting for dashboard $dashboardId version $versionNumber creation status...\n";

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = self::executeWithRetry($client, 'describeDashboard', [
                    'AwsAccountId' => $awsAccountId,
                    'DashboardId' => $dashboardId,
                    'VersionNumber' => $versionNumber,
                ]);

                $status = $response['Dashboard']['Version']['Status'] ?? 'UNKNOWN';

                if ($status === 'CREATION_SUCCESSFUL') {
                    echo "✅ Dashboard version $versionNumber creation successful.\n";
                    return ['success' => true, 'versionNumber' => $versionNumber, 'errors' => []];
                }

                if ($status === 'CREATION_FAILED') {
                    echo "❌ Dashboard version $versionNumber creation failed.\n";
                    return [
                        'success' => false,
                        'versionNumber' => $versionNumber,
                        'errors' => $response['Dashboard']['Version']['Errors'] ?? []
                    ];
                }

                echo "Attempt $attempt: Status = $status, retrying after {$delaySeconds}s...\n";
            } catch (AwsException $e) {
                $errorCode = $e->getAwsErrorCode() ?? '';
                $errorMsg = $e->getMessage();

                if (
                    $errorCode === 'ResourceNotFoundException' ||
                    strpos($errorMsg, 'is not found') !== false
                ) {
                    echo "Attempt $attempt: Dashboard version not yet available (404), retrying...\n";
                } else {
                    echo "❌ AWS exception during status check: $errorMsg\n";
                    return [
                        'success' => false,
                        'versionNumber' => $versionNumber,
                        'errors' => [['Message' => $errorMsg]]
                    ];
                }
            }

            sleep($delaySeconds);
        }

        echo "❌ Timed out after $maxAttempts attempts waiting for dashboard version $versionNumber.\n";
        return [
            'success' => false,
            'versionNumber' => $versionNumber,
            'errors' => [['Message' => 'Polling timed out']]
        ];
    }

    /**
     * Prints detailed dashboard creation errors.
     *
     * @param array $errors
     */
    public static function printDashboardCreationErrors(array $errors): void
    {
        foreach ($errors as $error) {
            $type = $error['Type'] ?? 'UnknownType';
            $message = $error['Message'] ?? 'No details';
            echo " - [$type] $message\n";

            if (!empty($error['ViolatedEntities'])) {
                foreach ($error['ViolatedEntities'] as $entity) {
                    echo "   Violated Entity Path: {$entity['Path']}\n";
                }
            }
        }
    }

    /**
     * List all folders.
     */
    public static function listAllFolders(
        QuickSightClient $client,
        string $awsAccountId
    ): array {
        $all       = [];
        $nextToken = null;
        do {
            $params = ['AwsAccountId' => $awsAccountId];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }
            $resp      = self::executeWithRetry(
                client:      $client,
                method:      'listFolders',
                params:      $params
            );
            $folders   = $resp['FolderSummaryList'] ?? [];
            $all       = array_merge($all, $folders);
            $nextToken = $resp['NextToken'] ?? null;
        } while ($nextToken);
        return $all;
    }

    /**
     * Get folder hierarchy mapping.
     * Returns mapping: FolderArn => "Parent / ... / FolderName"
     */
    public static function getFolderHierarchyMapping(
        QuickSightClient $client,
        string $awsAccountId,
        int $maxConcurrent = 5
    ): array {
        $folders = self::listAllFolders(
            client:      $client,
            awsAccountId: $awsAccountId
        );
        $map = [];
        foreach ($folders as $folder) {
            $folderArn = $folder['Arn'] ?? null;
            if ($folderArn) {
                $map[$folderArn] = $folder['Name'] ?? '';
            }
        }
        $results  = [];
        $promises = [];
        $batches  = array_chunk($folders, $maxConcurrent);
        foreach ($batches as $batch) {
            foreach ($batch as $folder) {
                $folderId  = $folder['FolderId'];
                $folderArn = $folder['Arn'] ?? null;
                if (!$folderArn) {
                    continue;
                }
                $promises[$folderId] = $client->describeFolderAsync([
                    'AwsAccountId' => $awsAccountId,
                    'FolderId'     => $folderId
                ]);
            }
            $settled = Utils::settle($promises)->wait();
            foreach ($settled as $folderId => $result) {
                $folder = null;
                foreach ($batch as $f) {
                    if ($f['FolderId'] === $folderId) {
                        $folder = $f;
                        break;
                    }
                }
                if (!$folder) {
                    continue;
                }
                $folderArn = $folder['Arn'] ?? null;
                if (!$folderArn) {
                    continue;
                }
                if ($result['state'] === 'fulfilled') {
                    $desc      = $result['value'];
                    $path      = $desc['Folder']['FolderPath'] ?? [];
                    $hierarchy = [];
                    foreach ($path as $parentArn) {
                        $hierarchy[] = $map[$parentArn] ?? '';
                    }
                    $hierarchy[] = $map[$folderArn] ?? '';
                    $results[$folderArn] = implode(' / ', array_filter($hierarchy));
                }
            }
            $promises = [];
        }
        return $results;
    }

    /**
     * Get asset folder mapping.
     * Returns mapping: ResourceArn => array of folder hierarchy strings.
     */
    public static function getAssetFolderMapping(
        QuickSightClient $client,
        string $awsAccountId,
        int $maxConcurrent = 5
    ): array {
        $folders = self::listAllFolders(
            client:      $client,
            awsAccountId: $awsAccountId
        );
        $hierMap = self::getFolderHierarchyMapping(
            client:       $client,
            awsAccountId:  $awsAccountId,
            maxConcurrent: $maxConcurrent
        );
        // Build folderId -> FolderArn mapping.
        $idToArn = [];
        foreach ($folders as $folder) {
            if (isset($folder['FolderId'], $folder['Arn'])) {
                $idToArn[$folder['FolderId']] = $folder['Arn'];
            }
        }
        $assetMap = [];
        $promises = [];
        $batches  = array_chunk($folders, $maxConcurrent);
        foreach ($batches as $batch) {
            foreach ($batch as $folder) {
                $folderId = $folder['FolderId'];
                $promises[$folderId] = $client->listFolderMembersAsync([
                    'AwsAccountId' => $awsAccountId,
                    'FolderId'     => $folderId
                ]);
            }
            $settled = Utils::settle($promises)->wait();
            foreach ($settled as $folderId => $result) {
                if ($result['state'] !== 'fulfilled') {
                    continue;
                }
                $members   = $result['value']['FolderMemberList'] ?? [];
                $folderArn = $idToArn[$folderId] ?? null;
                if (!$folderArn) {
                    continue;
                }
                $folderHierarchyString = $hierMap[$folderArn] ?? "[MISSING: {$folderArn}]";

                foreach ($members as $member) {
                    $memberArn = $member['MemberArn'] ?? null;
                    if (!$memberArn) {
                        continue;
                    }
                    if (!isset($assetMap[$memberArn])) {
                        $assetMap[$memberArn] = [];
                    }
                    $assetMap[$memberArn][] = $folderHierarchyString;
                }
            }
            $promises = [];
        }
        return $assetMap;
    }
}
