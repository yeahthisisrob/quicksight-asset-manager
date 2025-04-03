<?php

namespace QSAssetManager\Command\Export;

use QSAssetManager\Manager\Export\ExportManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Aws\QuickSight\QuickSightClient;

#[AsCommand(
    name: 'assets:export',
    description: 'Export QuickSight dashboards and datasets'
)]
class ExportAssetsCommand extends Command
{
    protected function configure()
    {
        $this
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

        $config = require PROJECT_ROOT . '/config/global.php';
        $awsRegion = $config['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        if (empty($awsAccountId)) {
            $awsAccountId = $io->ask('Enter AWS Account ID');
        }

        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        $exportManager = new ExportManager(
            $config,
            $qsClient,
            $awsAccountId,
            $awsRegion,
            $io
        );

        $type = $input->getOption('type');
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
                        forceExport: false,
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
