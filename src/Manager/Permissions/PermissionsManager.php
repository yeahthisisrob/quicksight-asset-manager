<?php

namespace QSAssetManager\Manager\Permissions;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;

class PermissionsManager
{
    protected QuickSightClient $qs;
    protected string $awsAccountId;
    protected $outputCallback;

    // Owner‑level actions
    private const DASHBOARD_OWNER_ACTIONS = [
        'quicksight:DescribeDashboard',
        'quicksight:ListDashboardVersions',
        'quicksight:UpdateDashboardPermissions',
        'quicksight:QueryDashboard',
        'quicksight:UpdateDashboard',
        'quicksight:DeleteDashboard',
        'quicksight:DescribeDashboardPermissions',
        'quicksight:UpdateDashboardPublishedVersion',
    ];
    private const DATASET_OWNER_ACTIONS = [
        'quicksight:UpdateDataSetPermissions',
        'quicksight:DescribeDataSet',
        'quicksight:DescribeDataSetPermissions',
        'quicksight:PassDataSet',
        'quicksight:DescribeIngestion',
        'quicksight:ListIngestions',
        'quicksight:UpdateDataSet',
        'quicksight:DeleteDataSet',
        'quicksight:CreateIngestion',
        'quicksight:CancelIngestion',
    ];
    private const DATASOURCE_OWNER_ACTIONS = [
        'quicksight:DescribeDataSource',
        'quicksight:DescribeDataSourcePermissions',
        'quicksight:PassDataSource',
        'quicksight:UpdateDataSourcePermissions',
        'quicksight:UpdateDataSource',
        'quicksight:DeleteDataSource',
    ];
    private const ANALYSIS_OWNER_ACTIONS = [
        'quicksight:RestoreAnalysis',
        'quicksight:DescribeAnalysis',
        'quicksight:DescribeAnalysisPermissions',
        'quicksight:UpdateAnalysis',
        'quicksight:UpdateAnalysisPermissions',
        'quicksight:DeleteAnalysis',
        'quicksight:QueryAnalysis',
    ];

    // Reader‑level actions
    private const DASHBOARD_READER_ACTIONS = [
        'quicksight:DescribeDashboard',
        'quicksight:ListDashboardVersions',
        'quicksight:DescribeDashboardPermissions',
        'quicksight:QueryDashboard',
    ];
    private const DATASET_READER_ACTIONS = [
        'quicksight:DescribeDataSet',
        'quicksight:DescribeDataSetPermissions',
        'quicksight:PassDataSet',
    ];
    private const DATASOURCE_READER_ACTIONS = [
        'quicksight:DescribeDataSource',
        'quicksight:DescribeDataSourcePermissions',
    ];
    private const ANALYSIS_READER_ACTIONS = [
        'quicksight:DescribeAnalysis',
        'quicksight:DescribeAnalysisPermissions',
        'quicksight:QueryAnalysis',
    ];

    public function __construct(
        QuickSightClient $qs,
        string $awsAccountId,
        callable $outputCallback
    ) {
        $this->qs             = $qs;
        $this->awsAccountId   = $awsAccountId;
        $this->outputCallback = $outputCallback;
    }

    /**
     * Grants owner or reader permissions for the given principal across selected QuickSight assets.
     *
     * @param string $principalArn
     * @param bool   $dryRun
     * @param string $assetType   one of 'dashboards','datasets','datasources','analyses','all'
     * @param string $role        'owner' or 'reader'
     * @return array<string,int>
     */
    public function grantPermissionsForPrincipal(
        string $principalArn,
        bool $dryRun,
        string $assetType = 'all',
        string $role = 'owner'
    ): array {
        $stats = [
            'dashboards'          => 0,
            'dashboards_updated'  => 0,
            'datasets'            => 0,
            'datasets_updated'    => 0,
            'datasources'         => 0,
            'datasources_updated' => 0,
            'analyses'            => 0,
            'analyses_updated'    => 0,
        ];

        $shouldRun = fn(string $type) => $assetType === 'all' || $assetType === $type;

        // pick action sets
        $dashActions = $role === 'owner'
            ? self::DASHBOARD_OWNER_ACTIONS
            : self::DASHBOARD_READER_ACTIONS;
        $dsActions   = $role === 'owner'
            ? self::DATASET_OWNER_ACTIONS
            : self::DATASET_READER_ACTIONS;
        $srcActions  = $role === 'owner'
            ? self::DATASOURCE_OWNER_ACTIONS
            : self::DATASOURCE_READER_ACTIONS;
        $anActions   = $role === 'owner'
            ? self::ANALYSIS_OWNER_ACTIONS
            : self::ANALYSIS_READER_ACTIONS;

        // DASHBOARDS
        if ($shouldRun('dashboards')) {
            foreach (
                QuickSightHelper::paginate(
                    $this->qs,
                    $this->awsAccountId,
                    'listDashboards',
                    'DashboardSummaryList'
                ) as $item
            ) {
                $stats['dashboards']++;
                $id = $item['DashboardId'];
                try {
                    $resp    = QuickSightHelper::executeWithRetry(
                        $this->qs,
                        'describeDashboardPermissions',
                        ['AwsAccountId' => $this->awsAccountId,'DashboardId' => $id]
                    );
                    $current = array_column($resp['Permissions'] ?? [], 'Principal');
                    if (in_array($principalArn, $current, true)) {
                        ($this->outputCallback)("✔ Already has {$role} rights on dashboard {$id}", 'comment');
                    } else {
                        ($this->outputCallback)("→ Granting {$role} rights on dashboard {$id}", 'comment');
                        if (! $dryRun) {
                            QuickSightHelper::executeWithRetry(
                                $this->qs,
                                'updateDashboardPermissions',
                                [
                                    'AwsAccountId'     => $this->awsAccountId,
                                    'DashboardId'      => $id,
                                    'GrantPermissions' => [
                                        ['Principal' => $principalArn,'Actions' => $dashActions],
                                    ],
                                ]
                            );
                        }
                        $stats['dashboards_updated']++;
                    }
                } catch (AwsException $e) {
                    ($this->outputCallback)("Error on dashboard {$id}: {$e->getMessage()}", 'error');
                }
            }
        }

        // DATASETS
        if ($shouldRun('datasets')) {
            foreach (
                QuickSightHelper::paginate(
                    $this->qs,
                    $this->awsAccountId,
                    'listDataSets',
                    'DataSetSummaries'
                ) as $item
            ) {
                $stats['datasets']++;
                $id = $item['DataSetId'];
                try {
                    $resp    = QuickSightHelper::executeWithRetry(
                        $this->qs,
                        'describeDataSetPermissions',
                        ['AwsAccountId' => $this->awsAccountId,'DataSetId' => $id]
                    );
                    $current = array_column($resp['Permissions'] ?? [], 'Principal');
                    if (in_array($principalArn, $current, true)) {
                        ($this->outputCallback)("✔ Already has {$role} rights on dataset {$id}", 'comment');
                    } else {
                        ($this->outputCallback)("→ Granting {$role} rights on dataset {$id}", 'comment');
                        if (! $dryRun) {
                            QuickSightHelper::executeWithRetry(
                                $this->qs,
                                'updateDataSetPermissions',
                                [
                                    'AwsAccountId'     => $this->awsAccountId,
                                    'DataSetId'        => $id,
                                    'GrantPermissions' => [
                                        ['Principal' => $principalArn,'Actions' => $dsActions],
                                    ],
                                ]
                            );
                        }
                        $stats['datasets_updated']++;
                    }
                } catch (AwsException $e) {
                    ($this->outputCallback)("Error on dataset {$id}: {$e->getMessage()}", 'error');
                }
            }
        }

        // DATASOURCES
        if ($shouldRun('datasources')) {
            foreach (
                QuickSightHelper::paginate(
                    $this->qs,
                    $this->awsAccountId,
                    'listDataSources',
                    'DataSources'
                ) as $item
            ) {
                $stats['datasources']++;
                $id = $item['DataSourceId'];
                try {
                    $resp    = QuickSightHelper::executeWithRetry(
                        $this->qs,
                        'describeDataSourcePermissions',
                        ['AwsAccountId' => $this->awsAccountId,'DataSourceId' => $id]
                    );
                    $current = array_column($resp['Permissions'] ?? [], 'Principal');
                    if (in_array($principalArn, $current, true)) {
                        ($this->outputCallback)("✔ Already has {$role} rights on datasource {$id}", 'comment');
                    } else {
                        ($this->outputCallback)("→ Granting {$role} rights on datasource {$id}", 'comment');
                        if (! $dryRun) {
                            QuickSightHelper::executeWithRetry(
                                $this->qs,
                                'updateDataSourcePermissions',
                                [
                                    'AwsAccountId'     => $this->awsAccountId,
                                    'DataSourceId'     => $id,
                                    'GrantPermissions' => [
                                        ['Principal' => $principalArn,'Actions' => $srcActions],
                                    ],
                                ]
                            );
                        }
                        $stats['datasources_updated']++;
                    }
                } catch (AwsException $e) {
                    ($this->outputCallback)("Error on datasource {$id}: {$e->getMessage()}", 'error');
                }
            }
        }

        // ANALYSES
        if ($shouldRun('analyses')) {
            foreach (
                QuickSightHelper::paginate(
                    $this->qs,
                    $this->awsAccountId,
                    'listAnalyses',
                    'AnalysisSummaryList'
                ) as $item
            ) {
                $stats['analyses']++;
                $id = $item['AnalysisId'];
                try {
                    $resp    = QuickSightHelper::executeWithRetry(
                        $this->qs,
                        'describeAnalysisPermissions',
                        ['AwsAccountId' => $this->awsAccountId,'AnalysisId' => $id]
                    );
                    $current = array_column($resp['Permissions'] ?? [], 'Principal');
                    if (in_array($principalArn, $current, true)) {
                        ($this->outputCallback)("✔ Already has {$role} rights on analysis {$id}", 'comment');
                    } else {
                        ($this->outputCallback)("→ Granting {$role} rights on analysis {$id}", 'comment');
                        if (! $dryRun) {
                            QuickSightHelper::executeWithRetry(
                                $this->qs,
                                'updateAnalysisPermissions',
                                [
                                    'AwsAccountId'     => $this->awsAccountId,
                                    'AnalysisId'       => $id,
                                    'GrantPermissions' => [
                                        ['Principal' => $principalArn,'Actions' => $anActions],
                                    ],
                                ]
                            );
                        }
                        $stats['analyses_updated']++;
                    }
                } catch (AwsException $e) {
                    ($this->outputCallback)("Error on analysis {$id}: {$e->getMessage()}", 'error');
                }
            }
        }

        return $stats;
    }
}
