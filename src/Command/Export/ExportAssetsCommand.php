<?php

namespace QSAssetManager\Command\Export;

use QSAssetManager\Manager\Export\ExportManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Aws\QuickSight\QuickSightClient;

class ExportAssetsCommand extends Command
{
    protected static $defaultName = 'assets:export';

    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Export QuickSight dashboards and datasets')
            ->setHelp('Export all or specific QuickSight assets')
            ->addOption(
                'type',
                't',
                InputOption::VALUE_OPTIONAL,
                'Export type (dashboards, datasets, all)',
                'all'
            )
            ->addOption(
                'cleanup',
                'c',
                InputOption::VALUE_NONE,
                'Perform cleanup of exported assets'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('QuickSight Asset Exporter');

        // Load global configuration
        $config       = $this->config;
        $awsRegion    = $config['awsRegion']    ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        // Validate AWS account ID
        if (empty($awsAccountId)) {
            $io->error('AWS Account ID is not configured');
            return Command::FAILURE;
        }

        // Create QuickSight client
        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        // Create export manager
        $exportManager = new ExportManager(
            $config,
            $qsClient,
            $awsAccountId,
            $awsRegion,
            $io
        );

        // Determine export type
        $type           = $input->getOption('type');
        $performCleanup = $input->getOption('cleanup');

        try {
            switch ($type) {
                case 'dashboards':
                    $exported = $exportManager->exportAllDashboards();
                    $io->success(sprintf('Exported %d dashboards', count($exported)));

                    if ($performCleanup) {
                        $exportManager->cleanupAssets('dashboards', $exported);
                    }
                    break;

                case 'datasets':
                    $exported = $exportManager->exportAllDatasets();
                    $io->success(sprintf('Exported %d datasets', count($exported)));

                    if ($performCleanup) {
                        $exportManager->cleanupAssets('datasets', $exported);
                    }
                    break;

                case 'all':
                default:
                    $exportManager->exportAll(
                        forceExport:    false,
                        performCleanup: $performCleanup
                    );
                    break;
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Export failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
