<?php

namespace QSAssetManager\Command\Reporting;

use QSAssetManager\Manager\Reporting\AssetReportingManager;
use Aws\QuickSight\QuickSightClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportAssetReportCommand extends Command
{
    protected static $defaultName = 'reporting:asset-report';

    protected function configure(): void
    {
        $this
            ->setDescription(
                description: 'Generate a CSV report of QuickSight assets with folder, ' .
                             'permission, and tag details.'
            )
            ->setHelp(
                'This command creates a CSV report listing all QuickSight assets, ' .
                'including dashboards, datasets, and analyses. Options allow filtering ' .
                'by asset type and group tag, and to export only untagged assets or ' .
                'assets with no folder.'
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_OPTIONAL,
                'Export type (dashboards, datasets, analyses, all)',
                'all'
            )
            ->addOption(
                'tag',
                'g',
                InputOption::VALUE_REQUIRED,
                'Filter assets by the specified group tag value'
            )
            ->addOption(
                'untagged',
                'u',
                InputOption::VALUE_NONE,
                'Export only assets that are untagged'
            )
            ->addOption(
                'nofolders',
                'f',
                InputOption::VALUE_NONE,
                'Export only assets that are not in any folder'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Specify the output directory for the CSV file'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config      = require PROJECT_ROOT . '/config/global.php';
        $awsRegion   = $config['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        if (empty($awsAccountId)) {
            $output->writeln(
                "<error>AWS Account ID is required in the global configuration.</error>"
            );
            return Command::FAILURE;
        }

        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        $manager = new AssetReportingManager(
            config:       $config,
            quickSight:   $qsClient,
            awsAccountId: $awsAccountId,
            awsRegion:    $awsRegion
        );

        $onlyUntagged  = $input->getOption('untagged');
        $onlyNoFolders = $input->getOption('nofolders');
        $tagFilter     = $input->getOption('tag') ?: null;
        $outputPath    = $input->getOption('output') ?: null;
        $typeFilter    = strtolower($input->getOption('type') ?: 'all');

        $result = $manager->exportAssetReport(
            outputPath:   $outputPath,
            onlyUntagged: $onlyUntagged,
            onlyNoFolders:$onlyNoFolders,
            tagFilter:    $tagFilter,
            assetType:    $typeFilter
        );

        if ($result) {
            $output->writeln("<info>Asset report generated successfully: $result</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Failed to generate asset report.</error>");
            return Command::FAILURE;
        }
    }
}
