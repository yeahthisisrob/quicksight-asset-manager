<?php

namespace QSAssetManager\Command\Exploration\Dashboard;

use QSAssetManager\Manager\Exploration\Dashboard\DashboardManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\QuickSight\QuickSightClient;

class RenameDashboardCommand extends Command
{
    protected static $defaultName = 'dashboard:rename';

    protected function configure()
    {
        $this
            ->setDescription('Rename an existing QuickSight dashboard.')
            ->setHelp(
                'This command renames a QuickSight dashboard by retrieving its current definition and ' .
                'updating its name, which creates a new version.'
            )
            ->addArgument(
                'dashboardId',
                InputArgument::REQUIRED,
                'The Dashboard ID to rename.'
            )
            ->addArgument(
                'newName',
                InputArgument::REQUIRED,
                'The new name for the dashboard.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dashboardId = $input->getArgument('dashboardId');
        $newName     = $input->getArgument('newName');

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
        $manager = new DashboardManager($config, $qsClient, $awsAccountId, $awsRegion);

        if ($manager->renameDashboard($dashboardId, $newName)) {
            $output->writeln("<info>Dashboard $dashboardId renamed to $newName successfully.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Failed to rename dashboard $dashboardId.</error>");
            return Command::FAILURE;
        }
    }
}
