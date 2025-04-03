<?php

namespace QSAssetManager\Manager\Tagging;

use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\TaggingHelper;

class AssetTaggingManager extends TaggingManager
{
    /**
     * Interactively scan and tag assets.
     *
     * @param bool $tagDashboards Whether to tag dashboards.
     * @param bool $tagDatasets   Whether to tag datasets.
     * @param bool $tagAnalyses   Whether to tag analyses.
     * @param bool $autoApply     Whether to automatically apply suggested tags without prompting.
     * @return array Statistics about the tagging operation.
     */
    public function interactiveScan(
        bool $tagDashboards = true,
        bool $tagDatasets = true,
        bool $tagAnalyses = true,
        bool $autoApply = false
    ): array {
        $stats = [
            'dashboards' => ['total' => 0, 'tagged' => 0, 'untagged' => []],
            'datasets'   => ['total' => 0, 'tagged' => 0, 'untagged' => []],
            'analyses'   => ['total' => 0, 'tagged' => 0, 'untagged' => []],
        ];

        // Debug: Add initial output
        $this->output(message: "Starting asset folder mapping...");

        try {
            // Use new asset folder mapping (caching folder ARNs and full hierarchy)
            $folderMapping = QuickSightHelper::getAssetFolderMapping(
                client:       $this->quickSight,
                awsAccountId: $this->awsAccountId,
                maxConcurrent: 5
            );

            // Debug: Output folder mapping count
            $this->output(message: "Got folder mapping with " . count($folderMapping) . " entries.");

            // Handle empty folder mapping gracefully
            if (empty($folderMapping)) {
                $this->output(
                    message: "Warning: No folder mappings found. This is unusual but will continue.",
                    type: 'warning'
                );
            }

            $this->outputFolderGroupMatches(folderMapping: $folderMapping);

            $this->output(message: "Folder group matching completed successfully.");

            if ($tagDashboards) {
                $stats['dashboards'] = $this->scanAndTagDashboards(
                    folders:   $folderMapping,
                    autoApply: $autoApply
                );
            }

            if ($tagDatasets) {
                $stats['datasets'] = $this->scanAndTagDatasets(
                    folders:   $folderMapping,
                    autoApply: $autoApply
                );
            }

            if ($tagAnalyses) {
                $stats['analyses'] = $this->scanAndTagAnalyses(
                    folders:   $folderMapping,
                    autoApply: $autoApply
                );
            }

            $this->outputSummary(stats: $stats);
            return $stats;
        } catch (\Exception $e) {
            $this->output(
                message: "Critical error in scanning process: " . $e->getMessage(),
                type:    'error'
            );
            return $stats;
        }
    }

    /**
     * Output folder group matches based on hierarchy strings.
     */
    protected function outputFolderGroupMatches(array $folderMapping): void
    {
        try {
            $this->output(message: "Folder Group Matches:");

            // Simple case - handle empty folder mapping
            if (empty($folderMapping)) {
                $this->output(message: "  (No folder mappings found)");
                return;
            }

            // Get all folder hierarchies
            $folderHierarchies = [];
            foreach ($folderMapping as $memberArn => $hierarchies) {
                if (!is_array($hierarchies)) {
                    continue;
                }
                foreach ($hierarchies as $hierarchy) {
                    if (!is_string($hierarchy)) {
                        continue;
                    }
                    if (!isset($folderHierarchies[$hierarchy])) {
                        $folderHierarchies[$hierarchy] = 0;
                    }
                    $folderHierarchies[$hierarchy]++;
                }
            }

            // Show sample of folder hierarchies
            $this->output(message: "  Found " . count($folderHierarchies) . " unique folder hierarchies");
            $i = 0;
            foreach ($folderHierarchies as $path => $count) {
                if ($i++ >= 5) {
                    break;
                }
                $this->output(message: "    '{$path}' ({$count} assets)");
            }

            // Find all matches using the same logic as determineAssetTag
            $allMatches = [];
            foreach ($folderHierarchies as $hierarchy => $count) {
                foreach ($this->groups as $groupKey => $groupConfig) {
                    if (!isset($groupConfig['aliases']) || !is_array($groupConfig['aliases'])) {
                        continue;
                    }

                    foreach ($groupConfig['aliases'] as $alias) {
                        if (stripos($hierarchy, $alias) !== false) {
                            if (!isset($allMatches[$groupKey])) {
                                $allMatches[$groupKey] = [
                                    'count' => 0,
                                    'matches' => []
                                ];
                            }
                            $allMatches[$groupKey]['count'] += $count;
                            $allMatches[$groupKey]['matches'][] = [
                                'hierarchy' => $hierarchy,
                                'alias' => $alias,
                                'count' => $count
                            ];
                            break; // Found a match for this hierarchy/group combination
                        }
                    }
                }
            }

            // Output the matches
            if (empty($allMatches)) {
                $this->output(message: "  (No group matches found in folders)");
                return;
            }

            foreach ($allMatches as $groupKey => $data) {
                $this->output(message: "  $groupKey: {$data['count']} folder members");
                // Show examples of matches (limit to 3 per group)
                $shown = 0;
                foreach ($data['matches'] as $match) {
                    if ($shown++ >= 3) {
                        break;
                    }
                    $this->output(
                        message: "    '{$match['hierarchy']}' matched alias '{$match['alias']}'" .
                        "({$match['count']} assets)"
                    );
                }
            }
        } catch (\Exception $e) {
            $this->output(
                message: "Error in outputFolderGroupMatches: " . $e->getMessage(),
                type:    'error'
            );
        }
    }

    /**
     * Scan and tag dashboards.
     */
    protected function scanAndTagDashboards(array $folders, bool $autoApply = false): array
    {
        return $this->scanAndTagAssets(
            folders:       $folders,
            assetType:     'dashboards',
            apiMethod:     'listDashboards',
            resultKey:     'DashboardSummaryList',
            assetTypeName: 'Dashboard',
            idField:       'DashboardId',
            autoApply:     $autoApply
        );
    }

    /**
     * Scan and tag datasets.
     */
    protected function scanAndTagDatasets(array $folders, bool $autoApply = false): array
    {
        return $this->scanAndTagAssets(
            folders:             $folders,
            assetType:           'datasets',
            apiMethod:           'listDataSets',
            resultKey:           'DataSetSummaries',
            assetTypeName:       'Dataset',
            idField:             'DataSetId',
            handleDatasetErrors: true,
            autoApply:           $autoApply
        );
    }

    /**
     * Scan and tag analyses.
     */
    protected function scanAndTagAnalyses(array $folders, bool $autoApply = false): array
    {
        return $this->scanAndTagAssets(
            folders:       $folders,
            assetType:     'analyses',
            apiMethod:     'listAnalyses',
            resultKey:     'AnalysisSummaryList',
            assetTypeName: 'Analysis',
            idField:       'AnalysisId',
            autoApply:     $autoApply
        );
    }

    /**
     * Generic method to scan and tag QuickSight assets.
     *
     * @param array  $folders             Folder information.
     * @param string $assetType           Type of asset (used in logging).
     * @param string $apiMethod           API method to list assets.
     * @param string $resultKey           Key in the API result that contains the asset list.
     * @param string $assetTypeName       User-friendly name of the asset type.
     * @param string $idField             Field name that holds the asset ID.
     * @param bool   $handleDatasetErrors Whether to handle dataset-specific errors.
     * @param bool   $autoApply           Whether to automatically apply suggested tags without prompting.
     * @return array Statistics about the tagging operation.
     */
    protected function scanAndTagAssets(
        array $folders,
        string $assetType,
        string $apiMethod,
        string $resultKey,
        string $assetTypeName,
        string $idField,
        bool $handleDatasetErrors = false,
        bool $autoApply = false
    ): array {
        $assetCount     = 0;
        $taggedCount    = 0;
        $untaggedAssets = [];
        $nextToken      = null;
        $assetTypeName  = ucfirst($assetTypeName);
        $this->output(message: "Scanning {$assetType}...");

        try {
            do {
                $params = ['AwsAccountId' => $this->awsAccountId];
                if ($nextToken) {
                    $params['NextToken'] = $nextToken;
                }

                try {
                    $assetsResponse = QuickSightHelper::executeWithRetry(
                        $this->quickSight,
                        $apiMethod,
                        $params
                    );

                    if (!isset($assetsResponse[$resultKey]) || !is_array($assetsResponse[$resultKey])) {
                        $this->output(
                            message: "Warning: Missing or invalid result key '{$resultKey}' in API response",
                            type:    'warning'
                        );
                        break;
                    }

                    foreach ($assetsResponse[$resultKey] as $asset) {
                        $assetCount++;

                        // Safely check for Arn
                        if (!isset($asset['Arn'])) {
                            $this->output(
                                message: "Warning: Asset #{$assetCount} is missing 'Arn' field",
                                type:    'warning'
                            );
                            continue;
                        }

                        // Safely get folder hierarchies
                        $folderHierarchies = isset($folders[$asset['Arn']]) && is_array($folders[$asset['Arn']])
                            ? $folders[$asset['Arn']]
                            : [];

                        $folderNamesDisplay = !empty($folderHierarchies)
                            ? implode(', ', $folderHierarchies)
                            : 'None';

                        // Get tags
                        $tags = TaggingHelper::getResourceTags(
                            $this->quickSight,
                            $asset['Arn']
                        );

                        $groupTag = TaggingHelper::getGroupTag(
                            tags:   $tags,
                            tagKey: $this->tagKey
                        );

                        // Determine tag based on name and folders
                        $derivedTag = null;
                        if (isset($asset['Name'])) {
                            $derivedTag = $this->determineAssetTag($asset['Name'], $folderHierarchies);
                        }

                        // Process based on existing tag
                        if ($groupTag) {
                            if ($derivedTag && $derivedTag !== $groupTag) {
                                $this->output(
                                    message: "⚠ {$assetTypeName} #{$assetCount} {$asset[$idField]}",
                                    type:    'comment'
                                );
                                $this->output(message: "  Name: {$asset['Name']}");
                                $this->output(message: "  Folders: {$folderNamesDisplay}");
                                $this->output(message: "  Current Group: '{$groupTag}'");

                                if (
                                    !$this->handleAssetTagging(
                                        asset:               $asset,
                                        idField:             $idField,
                                        folderNames:         $folderNamesDisplay,
                                        folderHierarchies:   $folderHierarchies,
                                        derivedTag:          $derivedTag,
                                        taggedCount:         $taggedCount,
                                        untaggedAssets:      $untaggedAssets,
                                        handleDatasetErrors: $handleDatasetErrors,
                                        autoApply:           $autoApply
                                    )
                                ) {
                                    $untaggedAssets[] = [
                                        $asset[$idField],
                                        $asset['Name'],
                                        $folderNamesDisplay,
                                    ];
                                }
                            } else {
                                $this->output(
                                    message: "✓ {$assetTypeName} #{$assetCount} {$asset[$idField]} " .
                                    "'{$asset['Name']}' [{$this->tagKey}: $groupTag]",
                                    type:    'success'
                                );
                            }
                        } else {
                            $this->output(
                                message: "⚠ {$assetTypeName} #{$assetCount} {$asset[$idField]}",
                                type:    'warning'
                            );
                            $this->output(message: "  Name: {$asset['Name']}");
                            $this->output(message: "  Folders: {$folderNamesDisplay}");

                            if (
                                !$this->handleAssetTagging(
                                    asset:               $asset,
                                    idField:             $idField,
                                    folderNames:         $folderNamesDisplay,
                                    folderHierarchies:   $folderHierarchies,
                                    derivedTag:          $derivedTag,
                                    taggedCount:         $taggedCount,
                                    untaggedAssets:      $untaggedAssets,
                                    handleDatasetErrors: $handleDatasetErrors,
                                    autoApply:           $autoApply
                                )
                            ) {
                                $untaggedAssets[] = [
                                    $asset[$idField],
                                    $asset['Name'],
                                    $folderNamesDisplay,
                                ];
                            }
                        }
                    }

                    $nextToken = $assetsResponse['NextToken'] ?? null;
                } catch (AwsException $e) {
                    $this->output(
                        message: "Error scanning {$assetType}: " . $e->getMessage(),
                        type:    'error'
                    );
                    break;
                }
            } while ($nextToken);

            $singularType = substr($assetType, 0, -1);
            $this->output(
                message: ucfirst($singularType) . " scan complete. Processed $assetCount {$assetType}."
            );

            return [
                'total'    => $assetCount,
                'tagged'   => $taggedCount,
                'untagged' => $untaggedAssets,
            ];
        } catch (\Exception $e) {
            $this->output(
                message: "Critical error in scanAndTagAssets: " . $e->getMessage(),
                type:    'error'
            );

            return [
                'total'    => $assetCount,
                'tagged'   => $taggedCount,
                'untagged' => $untaggedAssets,
            ];
        }
    }

/**
     * Determine tag for an asset based on name and folder hierarchies.
     * Modified to work with the 'aliases' field instead of 'match'.
     *
     * @param string $name Asset name
     * @param array $folderHierarchies Folder hierarchies
     * @return string|null Determined tag or null if no match
     */
    protected function determineAssetTag(string $name, array $folderHierarchies): ?string
    {
        try {
            // Track best matches by specificity (length of matching string)
            $bestFolderMatch = null;
            $bestFolderMatchLength = 0;
            $bestFolderMatchGroup = null;

            $bestNameMatch = null;
            $bestNameMatchLength = 0;
            $bestNameMatchGroup = null;

            // Find the best matches (most specific)
            foreach ($this->groups as $groupKey => $groupConfig) {
                if (!isset($groupConfig['aliases']) || !is_array($groupConfig['aliases'])) {
                    continue;
                }

                // Check folder matches
                if (!empty($folderHierarchies)) {
                    foreach ($folderHierarchies as $hierarchy) {
                        foreach ($groupConfig['aliases'] as $alias) {
                            if (stripos($hierarchy, $alias) !== false) {
                                // Check if this is a more specific match
                                if (strlen($alias) > $bestFolderMatchLength) {
                                    $bestFolderMatch = $alias;
                                    $bestFolderMatchLength = strlen($alias);
                                    $bestFolderMatchGroup = $groupKey;
                                }
                            }
                        }
                    }
                }

                // Check name matches
                foreach ($groupConfig['aliases'] as $alias) {
                    if (stripos($name, $alias) !== false) {
                        // Check if this is a more specific match
                        if (strlen($alias) > $bestNameMatchLength) {
                            $bestNameMatch = $alias;
                            $bestNameMatchLength = strlen($alias);
                            $bestNameMatchGroup = $groupKey;
                        }
                    }
                }
            }

            // Prioritize folder matches over name matches
            if ($bestFolderMatchGroup !== null) {
                return $bestFolderMatchGroup;
            }

            // Fall back to name matches
            if ($bestNameMatchGroup !== null) {
                return $bestNameMatchGroup;
            }

            return null;
        } catch (\Exception $e) {
            $this->output(
                message: "Error in determineAssetTag: " . $e->getMessage(),
                type:    'error'
            );
            return null;
        }
    }

    /**
     * Handle the tagging process for a single asset.
     *
     * @param array  $asset               The asset data.
     * @param string $idField             Field name that holds the asset ID.
     * @param string $folderNames         Display string of folder names.
     * @param array  $folderHierarchies   Full folder hierarchies for this asset.
     * @param string|null $derivedTag     Suggested tag value.
     * @param int    &$taggedCount        Reference to the tagged count.
     * @param array  &$untaggedAssets     Reference to the untagged assets array.
     * @param bool   $handleDatasetErrors Whether to handle dataset-specific errors.
     * @param bool   $autoApply           Whether to automatically apply suggested tags without prompting.
     * @return bool True if the asset was tagged, false otherwise.
     */
    protected function handleAssetTagging(
        array $asset,
        string $idField,
        string $folderNames,
        array $folderHierarchies,
        ?string $derivedTag,
        int &$taggedCount,
        array &$untaggedAssets,
        bool $handleDatasetErrors = false,
        bool $autoApply = false
    ): bool {
        try {
            if ($derivedTag) {
                $this->output(message: "  Suggested Group: '{$derivedTag}'");

                // Explain matching source if possible
                $matchSource = $this->explainTagMatch($asset['Name'], $folderHierarchies, $derivedTag);
                if ($matchSource) {
                    $this->output(message: "  {$matchSource}");
                }

                $applyTag = $autoApply || ($this->io
                ? $this->io->confirm("  Apply '{$derivedTag}'?", false)
                : (strtolower(trim(fgets(STDIN))) === 'y'));

                if ($applyTag) {
                    try {
                        if (
                            TaggingHelper::applyGroupTag(
                                $this->quickSight,
                                $this->awsAccountId,
                                $asset['Arn'],
                                $derivedTag,
                                $this->tagKey
                            )
                        ) {
                            $taggedCount++;
                            $this->output(
                                message: "  ✓ Tagged as '{$derivedTag}'",
                                type:    'success'
                            );
                            return true;
                        } else {
                            $this->output(
                                message: "  ✗ Failed to tag",
                                type:    'error'
                            );
                            return false;
                        }
                    } catch (AwsException $e) {
                        if ($handleDatasetErrors) {
                            $errorMessage = $e->getAwsErrorMessage() ?: $e->getMessage();
                            if (strpos($errorMessage, 'The data set type is not supported') !== false) {
                                $this->output(
                                    message: "  ⚠ Skipped: Flat file dataset not supported by the API.",
                                    type:    'warning'
                                );
                            } else {
                                $this->output(
                                    message: "  ✗ Failed: " . $e->getMessage(),
                                    type:    'error'
                                );
                            }
                        } else {
                            $this->output(
                                message: "  ✗ Failed: " . $e->getMessage(),
                                type:    'error'
                            );
                        }
                        return false;
                    }
                } else {
                    return $this->handleManualTagging(asset: $asset, taggedCount: $taggedCount);
                }
            } else {
                $this->output(message: "  No tag could be derived.");
                return $this->handleManualTagging(asset: $asset, taggedCount: $taggedCount);
            }
        } catch (\Exception $e) {
            $this->output(
                message: "Error in handleAssetTagging: " . $e->getMessage(),
                type:    'error'
            );
            return false;
        }
    }

    /**
     * Explain why a specific tag was determined for an asset.
     * Modified to work with the 'aliases' field instead of 'match'.
     *
     * @param string $name Asset name
     * @param array $folderHierarchies Folder hierarchies
     * @param string $derivedTag The derived tag
     * @return string|null Explanation or null if can't be determined
     */
    protected function explainTagMatch(string $name, array $folderHierarchies, string $derivedTag): ?string
    {
        try {
            if (!isset($this->groups[$derivedTag]['aliases']) || !is_array($this->groups[$derivedTag]['aliases'])) {
                return null;
            }

            // First check if it matched from a folder hierarchy
            if (!empty($folderHierarchies)) {
                foreach ($folderHierarchies as $hierarchy) {
                    foreach ($this->groups[$derivedTag]['aliases'] as $alias) {
                        if (stripos($hierarchy, $alias) !== false) {
                            return "(Matched from folder: '{$hierarchy}' with alias '{$alias}')";
                        }
                    }
                }
            }

            // Then check if it matched from the name
            foreach ($this->groups[$derivedTag]['aliases'] as $alias) {
                if (stripos($name, $alias) !== false) {
                    return "(Matched from name with alias '{$alias}')";
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->output(
                message: "Error in explainTagMatch: " . $e->getMessage(),
                type:    'error'
            );
            return null;
        }
    }

    /**
     * Handle manual tagging for an asset.
     *
     * @param array $asset         The asset data.
     * @param int   &$taggedCount  Reference to the tagged count.
     * @return bool True if the asset was tagged, false otherwise.
     */
    protected function handleManualTagging(
        array $asset,
        int &$taggedCount
    ): bool {
        try {
            $manualTag = $this->io
            ? $this->io->confirm("  Manually tag '{$asset['Name']}'?", false)
            : (strtolower(trim(fgets(STDIN))) === 'y');

            if ($manualTag) {
                $newTag = $this->io
                ? $this->io->ask("  Enter group tag")
                : trim(fgets(STDIN));

                if (!empty($newTag)) {
                    try {
                        if (
                            TaggingHelper::applyGroupTag(
                                $this->quickSight,
                                $this->awsAccountId,
                                $asset['Arn'],
                                $newTag,
                                $this->tagKey
                            )
                        ) {
                            $taggedCount++;
                            $this->output(
                                message: "  ✓ Tagged as '{$newTag}'",
                                type:    'success'
                            );
                            return true;
                        } else {
                            $this->output(
                                message: "  ✗ Failed to tag",
                                type:    'error'
                            );
                            return false;
                        }
                    } catch (AwsException $e) {
                        $this->output(
                            message: "  ✗ Failed: " . $e->getMessage(),
                            type:    'error'
                        );
                        return false;
                    }
                } else {
                    $this->output(message: "  No tag provided. Skipped.");
                    return false;
                }
            } else {
                $this->output(message: "  Skipped.");
                return false;
            }
        } catch (\Exception $e) {
            $this->output(
                message: "Error in handleManualTagging: " . $e->getMessage(),
                type:    'error'
            );
            return false;
        }
    }

    /**
     * Output summary of tagging operations.
     */
    protected function outputSummary(array $stats): void
    {
        $this->output(message: "Summary:");
        $this->output(message: "  Dashboards: {$stats['dashboards']['total']} total, " .
        "{$stats['dashboards']['tagged']} tagged, " .
        count($stats['dashboards']['untagged']) . " untagged");
        $this->output(message: "  Datasets: {$stats['datasets']['total']} total, " .
        "{$stats['datasets']['tagged']} tagged, " .
        count($stats['datasets']['untagged']) . " untagged");
        $this->output(message: "  Analyses: {$stats['analyses']['total']} total, " .
        "{$stats['analyses']['tagged']} tagged, " .
        count($stats['analyses']['untagged']) . " untagged");
    }

    /**
     * Scan and tag QuickSight users based on email domains.
     *
     * @param bool $autoApply Whether to automatically apply suggested tags without prompting.
     * @return array Statistics about the tagging operation.
     */
    public function scanAndTagUsers(bool $autoApply = false): array
    {
        $this->output(message: "Scanning QuickSight Users for tagging...");

        $stats = [
        'total'   => 0,
        'tagged'  => 0,
        'skipped' => 0
        ];

        $nextToken = null;

        do {
            $params = [
            'AwsAccountId' => $this->awsAccountId,
            'Namespace'    => 'default'
            ];

            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            try {
                $response = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listUsers',
                    $params
                );

                foreach ($response['UserList'] as $user) {
                    $stats['total']++;
                    $userArn = $user['Arn'];
                    $email = $user['Email'];
                    $userName = $user['UserName'];

                    // Check for existing tag
                    $tags = TaggingHelper::getResourceTags(
                        $this->quickSight,
                        $userArn
                    );

                    $existingTag = TaggingHelper::getGroupTag(
                        tags:   $tags,
                        tagKey: $this->tagKey
                    );

                    // Determine group tag based on email domain
                    $derivedTag = $this->determineGroupFromEmail($email);

                    if ($existingTag) {
                            $this->output(
                                message: "✓ User $userName ($email) already tagged with '{$existingTag}'",
                                type:    'success'
                            );
                            $stats['tagged']++;
                            continue;
                    }

                    if ($derivedTag) {
                        $this->output(
                            message: "⚠ User $userName ($email)",
                            type:    'warning'
                        );
                        $this->output(message: "  Suggested Group: '{$derivedTag}'");

                        $applyTag = $autoApply || ($this->io
                            ? $this->io->confirm("  Apply '{$derivedTag}'?", false)
                            : (strtolower(trim(fgets(STDIN))) === 'y'));

                        if ($applyTag) {
                            try {
                                TaggingHelper::applyGroupTag(
                                    $this->quickSight,
                                    $this->awsAccountId,
                                    $userArn,
                                    $derivedTag,
                                    $this->tagKey
                                );
                                $stats['tagged']++;
                                $this->output(
                                    message: "  ✓ Tagged as '{$derivedTag}'",
                                    type:    'success'
                                );
                            } catch (AwsException $e) {
                                $this->output(
                                    message: "  ✗ Failed: " . $e->getMessage(),
                                    type:    'error'
                                );
                            }
                        } else {
                            // If suggested tag was declined, offer manual tagging
                            $manualTag = $this->io
                            ? $this->io->confirm("  Manually tag $userName?", false)
                            : (strtolower(trim(fgets(STDIN))) === 'y');

                            if ($manualTag) {
                                $newTag = $this->io
                                ? $this->io->ask("  Enter group tag")
                                : trim(fgets(STDIN));

                                if (!empty($newTag)) {
                                    try {
                                        TaggingHelper::applyGroupTag(
                                            $this->quickSight,
                                            $this->awsAccountId,
                                            $userArn,
                                            $newTag,
                                            $this->tagKey
                                        );
                                        $stats['tagged']++;
                                        $this->output(
                                            message: "  ✓ Tagged as '{$newTag}'",
                                            type:    'success'
                                        );
                                    } catch (AwsException $e) {
                                        $this->output(
                                            message: "  ✗ Failed: " . $e->getMessage(),
                                            type:    'error'
                                        );
                                    }
                                } else {
                                    $this->output(message: "  No tag provided. Skipped.");
                                    $stats['skipped']++;
                                }
                            } else {
                                $this->output(message: "  Skipped.");
                                $stats['skipped']++;
                            }
                        }
                    } else {
                        $this->output(
                            message: "⚠ User $userName ($email) - No matching domain",
                            type:    'warning'
                        );

                        // No derived tag, offer manual tagging directly
                        $manualTag = $this->io
                        ? $this->io->confirm("  Manually tag $userName?", false)
                        : (strtolower(trim(fgets(STDIN))) === 'y');

                        if ($manualTag) {
                            $newTag = $this->io
                            ? $this->io->ask("  Enter group tag")
                            : trim(fgets(STDIN));

                            if (!empty($newTag)) {
                                try {
                                    TaggingHelper::applyGroupTag(
                                        $this->quickSight,
                                        $this->awsAccountId,
                                        $userArn,
                                        $newTag,
                                        $this->tagKey
                                    );
                                    $stats['tagged']++;
                                    $this->output(
                                        message: "  ✓ Tagged as '{$newTag}'",
                                        type:    'success'
                                    );
                                } catch (AwsException $e) {
                                    $this->output(
                                        message: "  ✗ Failed: " . $e->getMessage(),
                                        type:    'error'
                                    );
                                }
                            } else {
                                $this->output(message: "  No tag provided. Skipped.");
                                $stats['skipped']++;
                            }
                        } else {
                            $this->output(message: "  Skipped.");
                            $stats['skipped']++;
                        }
                    }
                }

                $nextToken = $response['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->output(
                    message: "Error listing users: " . $e->getMessage(),
                    type:    'error'
                );
                break;
            }
        } while ($nextToken);

        $this->output(
            message: "User scan complete. Processed {$stats['total']} users, " .
                "tagged {$stats['tagged']}, skipped {$stats['skipped']}."
        );

        return $stats;
    }

    /**
     * Determine the group tag based on user's email domain.
     *
     * @param string $email The user's email address
     * @return string|null The derived group tag or null if no match found
     */
    protected function determineGroupFromEmail(string $email): ?string
    {
        $atPos = strpos($email, '@');
        if ($atPos === false) {
            return null;
        }

        $domain = strtolower(substr($email, $atPos + 1));

        foreach ($this->emailDomains as $groupKey => $domains) {
            foreach ($domains as $matchDomain) {
                if ($domain === strtolower($matchDomain)) {
                    return $groupKey;
                }
            }
        }

        return null;
    }
}
