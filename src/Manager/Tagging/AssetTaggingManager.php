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
     * @return array Statistics about the tagging operation.
     */
    public function interactiveScan(
        bool $tagDashboards = true,
        bool $tagDatasets = true,
        bool $tagAnalyses = true
    ): array {
        $stats = [
            'dashboards' => ['total' => 0, 'tagged' => 0, 'untagged' => []],
            'datasets'   => ['total' => 0, 'tagged' => 0, 'untagged' => []],
            'analyses'   => ['total' => 0, 'tagged' => 0, 'untagged' => []],
        ];

        // Get folder information and output folder group matches.
        $folders = $this->collectFolderInfo();
        $this->outputFolderGroupMatches(folders: $folders);

        if ($tagDashboards) {
            $stats['dashboards'] = $this->scanAndTagDashboards(folders: $folders);
        }
        if ($tagDatasets) {
            $stats['datasets'] = $this->scanAndTagDatasets(folders: $folders);
        }
        if ($tagAnalyses) {
            $stats['analyses'] = $this->scanAndTagAnalyses(folders: $folders);
        }

        $this->outputSummary(stats: $stats);
        return $stats;
    }

    /**
     * Output folder group matches based on names.
     */
    protected function outputFolderGroupMatches(array $folders): void
    {
        $folderNames = [];
        foreach ($folders as $memberArn => $folderList) {
            foreach ($folderList as $folderName) {
                $folderNames[$folderName] = ($folderNames[$folderName] ?? 0) + 1;
            }
        }
        $groupMatches = [];
        foreach ($this->groups as $groupKey => $groupConfig) {
            $groupMatches[$groupKey] = 0;
        }
        foreach ($folderNames as $folderName => $count) {
            $matchedGroup = TaggingHelper::determineGroupTag(
                $folderName,
                $this->groups
            );
            if ($matchedGroup) {
                $groupMatches[$matchedGroup] += $count;
            }
        }
        $this->output(message: "Folder Group Matches:");
        foreach ($groupMatches as $group => $count) {
            if ($count > 0) {
                $this->output(message: "  $group: $count folder members");
            }
        }
    }

    /**
     * Scan and tag dashboards.
     */
    protected function scanAndTagDashboards(array $folders): array
    {
        return $this->scanAndTagAssets(
            folders:       $folders,
            assetType:     'dashboards',
            apiMethod:     'listDashboards',
            resultKey:     'DashboardSummaryList',
            assetTypeName: 'Dashboard',
            idField:       'DashboardId'
        );
    }

    /**
     * Scan and tag datasets.
     */
    protected function scanAndTagDatasets(array $folders): array
    {
        return $this->scanAndTagAssets(
            folders:           $folders,
            assetType:         'datasets',
            apiMethod:         'listDataSets',
            resultKey:         'DataSetSummaries',
            assetTypeName:     'Dataset',
            idField:           'DataSetId',
            handleDatasetErrors: true
        );
    }

    /**
     * Scan and tag analyses.
     */
    protected function scanAndTagAnalyses(array $folders): array
    {
        return $this->scanAndTagAssets(
            folders:       $folders,
            assetType:     'analyses',
            apiMethod:     'listAnalyses',
            resultKey:     'AnalysisSummaryList',
            assetTypeName: 'Analysis',
            idField:       'AnalysisId'
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
     * @return array Statistics about the tagging operation.
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
        $assetCount    = 0;
        $taggedCount   = 0;
        $untaggedAssets = [];
        $nextToken     = null;
        $assetTypeName = ucfirst($assetTypeName);
        $this->output(message: "Scanning {$assetType}...");

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
                    $tags     = TaggingHelper::getResourceTags(
                        $this->quickSight,
                        $asset['Arn']
                    );
                    $groupTag = TaggingHelper::getGroupTag(
                        tags:   $tags,
                        tagKey: $this->tagKey
                    );
                    if ($groupTag) {
                        $derivedTag = TaggingHelper::determineGroupTag(
                            $asset['Name'],
                            $this->groups,
                            $folderNames
                        );
                        if ($derivedTag && $derivedTag !== $groupTag) {
                            $this->output(
                                message: "⚠ {$assetTypeName} #{$assetCount} " .
                                         "{$asset[$idField]}",
                                type:    'comment'
                            );
                            $this->output(message: "  Name: {$asset['Name']}");
                            $this->output(message: "  Folders: $folderNames");
                            $this->output(message: "  Current Group: '$groupTag'");
                            if (
                                !$this->handleAssetTagging(
                                    asset:               $asset,
                                    idField:             $idField,
                                    folderNames:         $folderNames,
                                    derivedTag:          $derivedTag,
                                    taggedCount:         $taggedCount,
                                    untaggedAssets:      $untaggedAssets,
                                    handleDatasetErrors: $handleDatasetErrors
                                )
                            ) {
                                $untaggedAssets[] = [
                                    $asset[$idField],
                                    $asset['Name'],
                                    $folderNames,
                                ];
                            }
                        } else {
                            $this->output(
                                message: "✓ {$assetTypeName} #{$assetCount} " .
                                         "{$asset[$idField]} '{$asset['Name']}' " .
                                         "[{$this->tagKey}: $groupTag]",
                                type:    'success'
                            );
                        }
                    } else {
                        $this->output(
                            message: "⚠ {$assetTypeName} #{$assetCount} " .
                                     "{$asset[$idField]}",
                            type:    'warning'
                        );
                        $this->output(message: "  Name: {$asset['Name']}");
                        $this->output(message: "  Folders: $folderNames");
                        $derivedTag = TaggingHelper::determineGroupTag(
                            $asset['Name'],
                            $this->groups,
                            $folderNames
                        );
                        if (
                            !$this->handleAssetTagging(
                                asset:               $asset,
                                idField:             $idField,
                                folderNames:         $folderNames,
                                derivedTag:          $derivedTag,
                                taggedCount:         $taggedCount,
                                untaggedAssets:      $untaggedAssets,
                                handleDatasetErrors: $handleDatasetErrors
                            )
                        ) {
                            $untaggedAssets[] = [
                                $asset[$idField],
                                $asset['Name'],
                                $folderNames,
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
    }

    /**
     * Handle the tagging process for a single asset.
     *
     * @param array  $asset             The asset data.
     * @param string $idField           Field name that holds the asset ID.
     * @param string $folderNames       Comma-separated folder names.
     * @param string|null $derivedTag     Suggested tag value.
     * @param int    &$taggedCount      Reference to the tagged count.
     * @param array  &$untaggedAssets   Reference to the untagged assets array.
     * @param bool   $handleDatasetErrors Whether to handle dataset-specific errors.
     * @return bool True if the asset was tagged, false otherwise.
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
            $this->output(message: "  Suggested Group: '$derivedTag'");
            $applyTag = $this->io
                ? $this->io->confirm("  Apply '$derivedTag'?", false)
                : (strtolower(trim(fgets(STDIN))) === 'y');
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
                            message: "  ✓ Tagged as '$derivedTag'",
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
            return $this->handleManualTagging(asset: $asset, taggedCount: $taggedCount);
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
                            message: "  ✓ Tagged as '$newTag'",
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
}
