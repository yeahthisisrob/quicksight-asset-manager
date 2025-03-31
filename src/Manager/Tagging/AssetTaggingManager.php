<?php

namespace QSAssetManager\Manager\Tagging;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use QSAssetManager\Utils\TaggingHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class AssetTaggingManager extends TaggingManager
{
    /**
     * Interactively scan and tag assets
     *
     * @param bool $tagDashboards Whether to tag dashboards
     * @param bool $tagDatasets Whether to tag datasets
     * @param bool $tagAnalyses Whether to tag analyses
     * @return array Statistics about the tagging operation
     */
    public function interactiveScan(
        bool $tagDashboards = true,
        bool $tagDatasets = true,
        bool $tagAnalyses = true
    ): array {
        $stats = [
            'dashboards' => ['total' => 0, 'tagged' => 0, 'untagged' => []],
            'datasets' => ['total' => 0, 'tagged' => 0, 'untagged' => []],
            'analyses' => ['total' => 0, 'tagged' => 0, 'untagged' => []],
        ];

        // Get folder information
        $folders = $this->collectFolderInfo();
        $this->outputFolderGroupMatches($folders);

        // Process dashboards
        if ($tagDashboards) {
            $stats['dashboards'] = $this->scanAndTagDashboards($folders);
        }

        // Process datasets
        if ($tagDatasets) {
            $stats['datasets'] = $this->scanAndTagDatasets($folders);
        }

        // Process analyses
        if ($tagAnalyses) {
            $stats['analyses'] = $this->scanAndTagAnalyses($folders);
        }

        // Output summary
        $this->outputSummary($stats);

        return $stats;
    }

    /**
     * Output folder group matches based on names
     */
    protected function outputFolderGroupMatches(array $folders): void
    {
        $folderNames = [];
        foreach ($folders as $memberArn => $folderList) {
            foreach ($folderList as $folderName) {
                if (!isset($folderNames[$folderName])) {
                    $folderNames[$folderName] = 0;
                }
                $folderNames[$folderName]++;
            }
        }

        $groupMatches = [];
        foreach ($this->groups as $groupKey => $groupConfig) {
            $groupMatches[$groupKey] = 0;
        }

        foreach ($folderNames as $folderName => $count) {
            $matchedGroup = TaggingHelper::determineGroupTag($folderName, $this->groups);
            if ($matchedGroup) {
                $groupMatches[$matchedGroup] += $count;
            }
        }

        $this->output("Folder Group Matches:");
        foreach ($groupMatches as $group => $count) {
            if ($count > 0) {
                $this->output("  $group: $count folder members");
            }
        }
    }

    /**
     * Scan and tag dashboards
     */
    protected function scanAndTagDashboards(array $folders): array
    {
        return $this->scanAndTagAssets(
            $folders,
            'dashboards',
            'listDashboards',
            'DashboardSummaryList',
            'Dashboard',
            'DashboardId'
        );
    }

    /**
     * Scan and tag datasets
     */
    protected function scanAndTagDatasets(array $folders): array
    {
        return $this->scanAndTagAssets(
            $folders,
            'datasets',
            'listDataSets',
            'DataSetSummaries',
            'Dataset',
            'DataSetId',
            true // Handle dataset-specific errors
        );
    }

    /**
     * Scan and tag analyses
     */
    protected function scanAndTagAnalyses(array $folders): array
    {
        return $this->scanAndTagAssets(
            $folders,
            'analyses',
            'listAnalyses',
            'AnalysisSummaryList',
            'Analysis',
            'AnalysisId'
        );
    }

    /**
     * Generic method to scan and tag QuickSight assets
     *
     * @param array $folders Folder information
     * @param string $assetType Type of asset (used in logging)
     * @param string $apiMethod API method to list assets
     * @param string $resultKey Key in the API result that contains the asset list
     * @param string $assetTypeName User-friendly name of the asset type
     * @param string $idField Field name that holds the asset ID
     * @param bool $handleDatasetErrors Whether to handle dataset-specific errors
     * @return array Statistics about the tagging operation
     */
    protected function scanAndTagAssets(
        array $folders,
        string $assetType,
        string $apiMethod,
        string $resultKey,
        string $assetTypeName,
        string $idField,
        bool $handleDatasetErrors = false
    ): array {
        $assetCount = 0;
        $taggedCount = 0;
        $untaggedAssets = [];
        $nextToken = null;

        $assetTypeName = ucfirst($assetTypeName);
        $this->output("Scanning {$assetType}...");

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
                foreach ($assetsResponse[$resultKey] as $asset) {
                    $assetCount++;
                    $folderNames = isset($folders[$asset['Arn']])
                        ? implode(', ', $folders[$asset['Arn']])
                        : 'None';

                    $tags = TaggingHelper::getResourceTags($this->quickSight, $asset['Arn']);
                    $groupTag = TaggingHelper::getGroupTag($tags, $this->tagKey);

                    if ($groupTag) {
                        $derivedTag = TaggingHelper::determineGroupTag(
                            $asset['Name'],
                            $this->groups,
                            $folderNames
                        );

                        if ($derivedTag && $derivedTag !== $groupTag) {
                            $this->output("⚠ {$assetTypeName} #{$assetCount} {$asset[$idField]}", 'comment');
                            $this->output("  Name: {$asset['Name']}");
                            $this->output("  Folders: $folderNames");
                            $this->output("  Current Group: '$groupTag'");

                            if (
                                !$this->handleAssetTagging(
                                    $asset,
                                    $idField,
                                    $folderNames,
                                    $derivedTag,
                                    $taggedCount,
                                    $untaggedAssets,
                                    $handleDatasetErrors
                                )
                            ) {
                                $untaggedAssets[] = [
                                    $asset[$idField],
                                    $asset['Name'],
                                    $folderNames
                                ];
                            }
                        } else {
                            $this->output(
                                "✓ {$assetTypeName} #{$assetCount} {$asset[$idField]} '{$asset['Name']}' " .
                                "[{$this->tagKey}: $groupTag]",
                                'success'
                            );
                        }
                    } else {
                        $this->output("⚠ {$assetTypeName} #{$assetCount} {$asset[$idField]}", 'warning');
                        $this->output("  Name: {$asset['Name']}");
                        $this->output("  Folders: $folderNames");

                        $derivedTag = TaggingHelper::determineGroupTag(
                            $asset['Name'],
                            $this->groups,
                            $folderNames
                        );

                        if (
                            !$this->handleAssetTagging(
                                $asset,
                                $idField,
                                $folderNames,
                                $derivedTag,
                                $taggedCount,
                                $untaggedAssets,
                                $handleDatasetErrors
                            )
                        ) {
                            $untaggedAssets[] = [
                                $asset[$idField],
                                $asset['Name'],
                                $folderNames
                            ];
                        }
                    }
                }

                $nextToken = $assetsResponse['NextToken'] ?? null;
            } catch (AwsException $e) {
                $this->output("Error scanning {$assetType}: " . $e->getMessage(), 'error');
                break;
            }
        } while ($nextToken);

        $singularType = substr($assetType, 0, -1);
        $this->output(ucfirst($singularType) . " scan complete. Processed $assetCount {$assetType}.");

        return [
            'total' => $assetCount,
            'tagged' => $taggedCount,
            'untagged' => $untaggedAssets
        ];
    }

    /**
     * Handle the tagging process for a single asset
     *
     * @param array $asset The asset data
     * @param string $idField Field name that holds the asset ID
     * @param string $folderNames Comma-separated folder names
     * @param string|null $derivedTag Suggested tag value
     * @param int &$taggedCount Reference to the tagged count to update
     * @param array &$untaggedAssets Reference to the untagged assets array
     * @param bool $handleDatasetErrors Whether to handle dataset-specific errors
     * @return bool True if the asset was tagged, false otherwise
     */
    protected function handleAssetTagging(
        array $asset,
        string $idField,
        string $folderNames,
        ?string $derivedTag,
        int &$taggedCount,
        array &$untaggedAssets,
        bool $handleDatasetErrors = false
    ): bool {
        if ($derivedTag) {
            $this->output("  Suggested Group: '$derivedTag'");

            if ($this->io) {
                $applyTag = $this->io->confirm("  Apply '$derivedTag'?", false);
            } else {
                $this->output("  Apply '$derivedTag'? (y/n): ");
                $applyTag = strtolower(trim(fgets(STDIN))) === 'y';
            }

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
                        $this->output("  ✓ Tagged as '$derivedTag'", 'success');
                        return true;
                    } else {
                        $this->output("  ✗ Failed to tag", 'error');
                        return false;
                    }
                } catch (AwsException $e) {
                    if ($handleDatasetErrors) {
                        $errorMessage = $e->getAwsErrorMessage() ?: $e->getMessage();
                        if (strpos($errorMessage, 'The data set type is not supported through API yet') !== false) {
                            $this->output("  ⚠ Skipped: Flat file dataset not supported by the API.", 'warning');
                        } else {
                            $this->output("  ✗ Failed: " . $e->getMessage(), 'error');
                        }
                    } else {
                        $this->output("  ✗ Failed: " . $e->getMessage(), 'error');
                    }
                    return false;
                }
            } else {
                // Try manual tagging
                return $this->handleManualTagging($asset, $taggedCount);
            }
        } else {
            // No suggested tag, try manual
            return $this->handleManualTagging($asset, $taggedCount);
        }
    }

    /**
     * Handle manual tagging for an asset
     *
     * @param array $asset The asset data
     * @param int &$taggedCount Reference to the tagged count to update
     * @return bool True if the asset was tagged, false otherwise
     */
    protected function handleManualTagging(array $asset, int &$taggedCount): bool
    {
        if ($this->io) {
            $manualTag = $this->io->confirm("  Manually tag '{$asset['Name']}'?", false);
        } else {
            $this->output("  Manually tag '{$asset['Name']}'? (y/n): ");
            $manualTag = strtolower(trim(fgets(STDIN))) === 'y';
        }

        if ($manualTag) {
            if ($this->io) {
                $newTag = $this->io->ask("  Enter group tag");
            } else {
                $this->output("  Enter group tag: ");
                $newTag = trim(fgets(STDIN));
            }

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
                        $this->output("  ✓ Tagged as '$newTag'", 'success');
                        return true;
                    } else {
                        $this->output("  ✗ Failed to tag", 'error');
                        return false;
                    }
                } catch (AwsException $e) {
                    $this->output("  ✗ Failed: " . $e->getMessage(), 'error');
                    return false;
                }
            } else {
                $this->output("  No tag provided. Skipped.");
                return false;
            }
        } else {
            $this->output("  Skipped.");
            return false;
        }
    }

    /**
     * Output summary of tagging operations
     */
    protected function outputSummary(array $stats): void
    {
        $timestamp = date('Ymd_His');

        $this->output("Summary:");

        // Dashboards
        $this->outputAssetTypeSummary('dashboards', $stats['dashboards'], $timestamp);

        // Datasets
        $this->outputAssetTypeSummary('datasets', $stats['datasets'], $timestamp);

        // Analyses
        $this->outputAssetTypeSummary('analyses', $stats['analyses'], $timestamp);
    }

    /**
     * Output summary for a specific asset type
     *
     * @param string $assetType Type of asset
     * @param array $stats Statistics for this asset type
     * @param string $timestamp Timestamp for filename
     */
    protected function outputAssetTypeSummary(
        string $assetType,
        array $stats,
        string $timestamp
    ): void {
        if ($stats['total'] > 0) {
            $untaggedCount = count($stats['untagged']);
            $this->output(
                "  " . ucfirst($assetType) . ": {$stats['total']} total, {$stats['tagged']} tagged, " .
                "$untaggedCount untagged"
            );

            if ($untaggedCount > 0) {
                $reportDir = $this->config['paths']['report_export_path'] ?? (getcwd() . '/exports');
                $reportDir = rtrim(string: $reportDir, characters: DIRECTORY_SEPARATOR);

                if (!is_dir(filename: $reportDir)) {
                    mkdir(directory: $reportDir, permissions: 0777, recursive: true);
                }

                $filename = "{$reportDir}/untagged_{$assetType}_{$timestamp}.csv";
                $csv = fopen($filename, 'w');
                fputcsv($csv, ['ID', 'Name', 'Folders'], ',', '"', '\\');
                foreach ($stats['untagged'] as $asset) {
                    fputcsv($csv, $asset, ',', '"', '\\');
                }
                fclose($csv);
                $this->output(
                    "  ⚠ Untagged " . ucfirst($assetType) . " saved to '$filename'",
                    'warning'
                );
            }
        }
    }
}
