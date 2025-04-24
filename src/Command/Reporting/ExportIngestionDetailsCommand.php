<?php
// src/Command/Reporting/ExportIngestionDetailsCommand.php

namespace QSAssetManager\Command\Reporting;

use QSAssetManager\Manager\Reporting\IngestionDetailsReportingManager;
use Aws\QuickSight\QuickSightClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ExportIngestionDetailsCommand extends Command
{
    protected static $defaultName = 'reporting:ingestion-details';

    protected function configure(): void
    {
        $this
            ->setDescription('Generate CSV of SPICE dataset ingestions over the last 30 days, with tags.')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for the CSV file'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $config        = require PROJECT_ROOT . '/config/global.php';
        $awsRegion     = $config['awsRegion']    ?? 'us-west-2';
        $awsAccountId  = $config['awsAccountId'] ?? '';

        if (empty($awsAccountId)) {
            $io->error('AWS Account ID is required in the global configuration.');
            return Command::FAILURE;
        }

        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        $manager = new IngestionDetailsReportingManager(
            config:       $config,
            quickSight:   $qsClient,
            awsAccountId: $awsAccountId,
            awsRegion:    $awsRegion,
            io:           $io
        );

        $path = $manager->exportIngestionDetailsReport(
            $input->getOption('output')
        );

        if ($path) {
            $io->success("Ingestion details report generated: $path");
            return Command::SUCCESS;
        } else {
            $io->error("Failed to generate ingestion details report.");
            return Command::FAILURE;
        }
    }
}
