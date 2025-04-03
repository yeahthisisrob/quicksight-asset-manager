<?php

namespace QSAssetManager\Utils;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;

class TaggingHelper
{
    /**
     * Determine group tag based on name, group patterns, and folder paths.
     *
     * @param string $name The item name to check
     * @param array $groups Group configuration with patterns
     * @param array|string $folderPaths Optional array or string of folder hierarchies to check
     * @param bool $prioritizeFolders Whether to prioritize folder matches over name matches
     * @return string|null The matching group key or null if no match
     */
    public static function determineGroupTag(
        string $name,
        array $groups,
        $folderPaths = [],
        bool $prioritizeFolders = true
    ): ?string {
        // Handle string folderPaths for backward compatibility
        if (is_string($folderPaths)) {
            $folderPaths = [$folderPaths];
        } elseif (!is_array($folderPaths)) {
            $folderPaths = [];
        }

        // First, find all potential matches (both from name and folders)
        $nameMatches = [];
        $folderMatches = [];

        // Check name patterns first
        foreach ($groups as $groupKey => $groupConfig) {
            if (!isset($groupConfig['match'])) {
                continue;
            }

            foreach ($groupConfig['match'] as $pattern) {
                if (stripos($name, $pattern) !== false) {
                    $nameMatches[$groupKey] = true;
                    break;
                }
            }
        }

        // Check folder paths if provided
        if (!empty($folderPaths)) {
            foreach ($groups as $groupKey => $groupConfig) {
                if (!isset($groupConfig['match'])) {
                    continue;
                }

                foreach ($folderPaths as $folderPath) {
                    if (empty($folderPath)) {
                        continue;
                    }

                    foreach ($groupConfig['match'] as $pattern) {
                        if (stripos($folderPath, $pattern) !== false) {
                            $folderMatches[$groupKey] = true;
                            break 2; // Break out of both loops once we find a match
                        }
                    }
                }
            }
        }

        // Return the appropriate match based on priority
        if ($prioritizeFolders) {
            // If we prioritize folders, return the first folder match if any
            if (!empty($folderMatches)) {
                reset($folderMatches);
                return key($folderMatches);
            }

            // Otherwise fall back to name matches
            if (!empty($nameMatches)) {
                reset($nameMatches);
                return key($nameMatches);
            }
        } else {
            // If we prioritize names, return the first name match if any
            if (!empty($nameMatches)) {
                reset($nameMatches);
                return key($nameMatches);
            }

            // Otherwise fall back to folder matches
            if (!empty($folderMatches)) {
                reset($folderMatches);
                return key($folderMatches);
            }
        }

        return null;
    }

    /**
     * Get the current group tag from a list of resource tags.
     *
     * @param array $tags Array of resource tags
     * @param string $tagKey The tag key to look for
     * @return string|null The group tag value or null if not found
     */
    public static function getGroupTag(array $tags, string $tagKey): ?string
    {
        foreach ($tags as $tag) {
            if (isset($tag['Key']) && $tag['Key'] === $tagKey) {
                return $tag['Value'] ?? null;
            }
        }
        return null;
    }

    /**
     * Apply a group tag to a resource.
     *
     * @param QuickSightClient $client The QuickSight client
     * @param string $awsAccountId AWS account ID
     * @param string $resourceArn The resource ARN to tag
     * @param string $groupTag The group tag value to apply
     * @param string $tagKey The tag key to use
     * @return bool Whether the operation was successful
     */
    public static function applyGroupTag(
        QuickSightClient $client,
        string $awsAccountId,
        string $resourceArn,
        string $groupTag,
        string $tagKey
    ): bool {
        try {
            $client->tagResource([
                'ResourceArn' => $resourceArn,
                'Tags' => [
                    [
                        'Key' => $tagKey,
                        'Value' => $groupTag
                    ]
                ]
            ]);
            return true;
        } catch (AwsException $e) {
            throw $e;
        }
    }

    /**
     * Get all tags for a resource.
     *
     * @param QuickSightClient $client The QuickSight client
     * @param string $resourceArn The resource ARN to get tags for
     * @return array Array of tags
     */
    public static function getResourceTags(QuickSightClient $client, string $resourceArn): array
    {
        try {
            $response = $client->listTagsForResource([
                'ResourceArn' => $resourceArn
            ]);
            return $response['Tags'] ?? [];
        } catch (AwsException $e) {
            return [];
        }
    }
}
