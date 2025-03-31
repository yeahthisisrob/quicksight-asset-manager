<?php

namespace QSAssetManager\Utils;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;

class TaggingHelper
{
    /**
     * Determine group tag based on asset name and folders
     *
     * @param string $name The asset name
     * @param array $groups The groups configuration
     * @param string $folders Comma-separated folder names
     * @return string|null The determined group key or null if not found
     */
    public static function determineGroupTag(string $name, array $groups, string $folders = ''): ?string
    {
        $textToSearch = strtolower(string: $name . ' ' . $folders);
        $bestMatch = null;
        $bestAliasLength = 0;

        foreach ($groups as $groupKey => $groupConfig) {
            if (!isset($groupConfig['aliases'])) {
                continue;
            }

            foreach ($groupConfig['aliases'] as $alias) {
                $alias = strtolower($alias);
                $aliasLength = strlen($alias);

                // Prefer exact word matches first (word boundaries)
                if (
                    preg_match(
                        pattern: '/\b' . preg_quote(str: $alias, delimiter: '/') . '\b/',
                        subject: $textToSearch
                    )
                ) {
                    if ($aliasLength > $bestAliasLength) {
                        $bestMatch = $groupKey;
                        $bestAliasLength = $aliasLength;
                    }
                } elseif (stripos($textToSearch, $alias) !== false && $bestAliasLength === 0) {
                    $bestMatch = $groupKey;
                    $bestAliasLength = $aliasLength;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Get group tag from AWS resource tags if it exists
     *
     * @param array $tags Array of AWS resource tags
     * @param string $tagKey The tag key to look for (defaults to 'group')
     * @return string|null The group tag value or null if not found
     */
    public static function getGroupTag(array $tags, string $tagKey = 'group'): ?string
    {
        foreach ($tags as $tag) {
            if (isset($tag['Key']) && strtolower($tag['Key']) === strtolower($tagKey)) {
                return $tag['Value'];
            }
        }
        return null;
    }

    /**
     * Apply a group tag to a QuickSight resource
     *
     * @param QuickSightClient $client The QuickSight client
     * @param string $awsAccountId The AWS account ID
     * @param string $resourceArn The resource ARN to tag
     * @param string $tagValue The tag value to apply
     * @param string $tagKey The tag key to use (defaults to 'group')
     * @return bool True if successful, false otherwise
     */
    public static function applyGroupTag(
        QuickSightClient $client,
        string $awsAccountId,
        string $resourceArn,
        string $tagValue,
        string $tagKey = 'group'
    ): bool {
        try {
            QuickSightHelper::executeWithRetry($client, 'tagResource', [
                'AwsAccountId' => $awsAccountId,
                'ResourceArn' => $resourceArn,
                'Tags' => [['Key' => $tagKey, 'Value' => $tagValue]]
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Get all tags for a resource
     *
     * @param QuickSightClient $client The QuickSight client
     * @param string $resourceArn The resource ARN
     * @return array The tags for the resource
     */
    public static function getResourceTags(QuickSightClient $client, string $resourceArn): array
    {
        try {
            $tagsResponse = QuickSightHelper::executeWithRetry(
                $client,
                'listTagsForResource',
                ['ResourceArn' => $resourceArn]
            );
            return isset($tagsResponse['Tags']) ? $tagsResponse['Tags'] : [];
        } catch (AwsException $e) {
            return [];
        }
    }
}
