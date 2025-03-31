<?php

namespace QSAssetManager\Manager;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;

/**
 * Base abstract class for all QuickSight asset types
 */
abstract class AssetManager
{
    protected $config;
    protected $quickSight;
    protected $awsAccountId;
    protected $awsRegion;

    /**
     * Constructor
     *
     * @param array $config Global configuration
     * @param QuickSightClient $quickSight QuickSight API client
     * @param string $awsAccountId AWS account ID
     * @param string $awsRegion AWS region
     */
    public function __construct(array $config, QuickSightClient $quickSight, string $awsAccountId, string $awsRegion)
    {
        $this->config       = $config;
        $this->quickSight   = $quickSight;
        $this->awsAccountId = $awsAccountId;
        $this->awsRegion    = $awsRegion;
    }

    /**
     * Get the asset definition from QuickSight API
     *
     * @param string $assetId ID of the asset
     * @return array|null Definition or null if error occurs
     */
    abstract protected function getAssetDefinition(string $assetId): ?array;

    /**
     * Update the asset with a new definition
     *
     * @param string $assetId ID of the asset
     * @param array $definition Updated definition
     * @param string $name Name of the asset (optional)
     * @return bool Success status
     */
    abstract protected function updateAsset(string $assetId, array $definition, string $name = ''): bool;

    /**
     * Get the asset name
     *
     * @param string $assetId ID of the asset
     * @return string Asset name or a default name
     */
    abstract protected function getAssetName(string $assetId): string;

    /**
     * Get the asset ARN
     *
     * @param string $assetId ID of the asset
     * @return string Asset ARN
     */
    abstract protected function getAssetArn(string $assetId): string;
}
