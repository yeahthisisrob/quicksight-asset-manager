<?php

namespace QSAssetManager\Command\Exploration\Dashboard;

use QSAssetManager\Manager\Exploration\Dashboard\DashboardManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\QuickSight\QuickSightClient;

class RemoveBrokenDashboardDataSetsCommand extends Command
{
    protected static $defaultName = 'dashboard:remove-broken-datasets';

    protected function configure()
    {
        $this
            ->setDescription('Remove broken DataSetIdentifiers from a QuickSight dashboard.')
            ->setHelp(
                'This command identifies and removes DataSetIdentifiers that are referenced in the dashboard ' .
                'but not declared in the DataSetIdentifierDeclarations section.'
            )
            ->addArgument(
                'dashboardId',
                InputArgument::REQUIRED,
                'The Dashboard ID to clean.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dashboardId = $input->getArgument('dashboardId');

        // Load global configuration
        $config       = require PROJECT_ROOT . '/config/global.php';
        $awsRegion    = $config['awsRegion']    ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        // Create QuickSight client
        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        // Instantiate DashboardManager
        $manager = new DashboardManager(
            $config,
            $qsClient,
            $awsAccountId,
            $awsRegion
        );

        if ($manager->removeBrokenDataSetIdentifiers($dashboardId)) {
            $output->writeln(
                "<info>Successfully removed broken DataSetIdentifiers from dashboard {$dashboardId}.</info>"
            );
            return Command::SUCCESS;
        } else {
            $output->writeln(
                "<error>Failed to remove broken DataSetIdentifiers from dashboard {$dashboardId}.</error>"
            );
            return Command::FAILURE;
        }
    }
}
