<?php

namespace QSAssetManager\Command\Tagging;

use QSAssetManager\Manager\Tagging\AssetTaggingManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Aws\QuickSight\QuickSightClient;

class ScanAssetsCommand extends Command
{
    protected static $defaultName = 'assets:scan';

    protected function configure()
    {
        $this
            ->setDescription('Scan QuickSight assets and interactively tag them with groups')
            ->setHelp(
                'This command scans your QuickSight assets and helps you assign group tags to them'
            )
            ->addOption(
                'dashboard',
                'd',
                InputOption::VALUE_NONE,
                'Scan only dashboards'
            )
            ->addOption(
                'dataset',
                's',
                InputOption::VALUE_NONE,
                'Scan only datasets'
            )
            ->addOption(
                'analysis',
                'a',
                InputOption::VALUE_NONE,
                'Scan only analyses'
            )
            ->addOption(
                'report',
                'r',
                InputOption::VALUE_NONE,
                'Just generate a report without interactive tagging'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('QuickSight Asset Scanner');

        // Load configuration
        $config       = require PROJECT_ROOT . '/config/global.php';
        $awsRegion    = $config['awsRegion']    ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        // If account ID is not in config, ask for it
        if (empty($awsAccountId)) {
            $awsAccountId = $io->ask('Enter AWS Account ID');
            if (empty($awsAccountId)) {
                $io->error('AWS Account ID is required');
                return Command::FAILURE;
            }
        }

        // Create QuickSight client
        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        // Create tagging manager
        $manager = new AssetTaggingManager($config, $qsClient, $awsAccountId, $awsRegion, $io);

        // Check which assets to scan
        $dashboards = $input->getOption('dashboard');
        $datasets   = $input->getOption('dataset');
        $analyses   = $input->getOption('analysis');

        // If none specified, scan all
        if (!$dashboards && !$datasets && !$analyses) {
            $dashboards = $datasets = $analyses = true;
        }

        // If report only, generate a report
        if ($input->getOption('report')) {
            if ($manager->exportAssetList()) {
                $io->success('Asset report generated successfully');
                return Command::SUCCESS;
            } else {
                $io->error('Failed to generate asset report');
                return Command::FAILURE;
            }
        }

        // Interactive scan
        $stats = $manager->interactiveScan($dashboards, $datasets, $analyses);

        // Output overall summary
        $totalAssets   = 0;
        $totalTagged   = 0;
        $totalUntagged = 0;

        foreach ($stats as $type => $typeStat) {
            $totalAssets   += $typeStat['total'];
            $totalTagged   += $typeStat['tagged'];
            $totalUntagged += count($typeStat['untagged']);
        }

        $io->section('Overall Summary');
        $io->table(
            ['Asset Type', 'Total', 'Tagged', 'Untagged'],
            [
                [
                    'Dashboards',
                    $stats['dashboards']['total'],
                    $stats['dashboards']['tagged'],
                    count($stats['dashboards']['untagged'])
                ],
                [
                    'Datasets',
                    $stats['datasets']['total'],
                    $stats['datasets']['tagged'],
                    count($stats['datasets']['untagged'])
                ],
                [
                    'Analyses',
                    $stats['analyses']['total'],
                    $stats['analyses']['tagged'],
                    count($stats['analyses']['untagged'])
                ],
                ['TOTAL', $totalAssets, $totalTagged, $totalUntagged]
            ]
        );

        if ($totalUntagged > 0) {
            $io->warning("$totalUntagged assets remain untagged");
            $io->note('Run with --report option to generate a full asset report');
        } else {
            $io->success('All assets tagged successfully');
        }

        return Command::SUCCESS;
    }
}
