<?php

namespace QSAssetManager\Command\Exploration\Dashboard;

use QSAssetManager\Manager\Exploration\Dashboard\DashboardManager;
use Aws\QuickSight\QuickSightClient;
use Aws\CloudTrail\CloudTrailClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExportDashboardViewCountsCommand extends Command
{
    protected static $defaultName = 'dashboard:export-view-counts';

    protected function configure()
    {
        $this
            ->setDescription('Export dashboard view counts based on CloudTrail events.')
            ->setHelp(
                'This command aggregates QuickSight dashboard view counts from CloudTrail events and ' .
                'exports the report as a CSV file.'
            )
            ->addArgument(
                'outputFile',
                InputArgument::REQUIRED,
                'The output CSV file path for the export.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Load global configuration.
        $output->writeln("Loading global configuration...");
        $config       = require PROJECT_ROOT . '/config/global.php';
        $awsRegion    = $config['awsRegion']    ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        // Create QuickSight client.
        $output->writeln("Creating QuickSight client...");
        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        // Create CloudTrail client.
        $output->writeln("Creating CloudTrail client...");
        $cloudTrailClient = new CloudTrailClient([
            'version' => '2013-11-01',
            'region'  => $awsRegion,
        ]);

        // Instantiate DashboardManager.
        $manager = new DashboardManager($config, $qsClient, $awsAccountId, $awsRegion);

        $outputFile = $input->getArgument('outputFile');
        $output->writeln("Exporting dashboard view counts to {$outputFile}...");

        $result = $manager->exportDashboardViewCounts($cloudTrailClient, $outputFile);

        if ($result) {
            $output->writeln("<info>Dashboard view counts exported successfully to {$outputFile}.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Failed to export dashboard view counts.</error>");
            return Command::FAILURE;
        }
    }
}
