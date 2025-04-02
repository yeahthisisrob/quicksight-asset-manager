<?php

namespace QSAssetManager\Command\Reporting;

use QSAssetManager\Manager\Reporting\ReportingManager;
use Aws\QuickSight\QuickSightClient;
use Aws\CloudTrail\CloudTrailClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExportDashboardViewCountsCommand extends Command
{
    protected static $defaultName = 'reporting:export-dashboard-view-counts';

    protected function configure()
    {
        $this
            ->setDescription('Export dashboard view counts report based on CloudTrail events.')
            ->setHelp(
                'This command aggregates QuickSight dashboard view counts from CloudTrail events and ' .
                'exports the report as a CSV file to the configured report export path.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Load global configuration.
        $output->writeln("Loading global configuration...");
        $config = require PROJECT_ROOT . '/config/global.php';
        $awsRegion = $config['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        // Create AWS clients.
        $output->writeln("Creating QuickSight client...");
        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        $output->writeln("Creating CloudTrail client...");
        $cloudTrailClient = new CloudTrailClient([
            'version' => '2013-11-01',
            'region'  => $awsRegion,
        ]);

        // Instantiate the ReportingManager.
        $output->writeln("Initializing Reporting Manager...");
        $manager = new ReportingManager($config, $qsClient, $awsAccountId, $awsRegion);

        // Execute the export.
        $output->writeln("Exporting dashboard view counts report...");
        $result = $manager->exportDashboardViewCounts($cloudTrailClient);

        if ($result) {
            $output->writeln("<info>Dashboard view counts report exported successfully.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Failed to export dashboard view counts report.</error>");
            return Command::FAILURE;
        }
    }
}
