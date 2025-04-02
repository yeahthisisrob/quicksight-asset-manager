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
                'This command scans your QuickSight assets and helps you assign group tags to them.'
            )
            ->addOption(
                name: 'dashboard',
                shortcut: 'd',
                mode: InputOption::VALUE_NONE,
                description: 'Scan only dashboards'
            )
            ->addOption(
                name: 'dataset',
                shortcut: 's',
                mode: InputOption::VALUE_NONE,
                description: 'Scan only datasets'
            )
            ->addOption(
                name: 'analysis',
                shortcut: 'a',
                mode: InputOption::VALUE_NONE,
                description: 'Scan only analyses'
            )
            ->addOption(
                name: 'users',
                shortcut: 'u',
                mode: InputOption::VALUE_NONE,
                description: 'Scan and tag users based on email domains'
            )
            ->addOption(
                name: 'auto',
                shortcut: 'A',
                mode: InputOption::VALUE_NONE,
                description: 'Automatically apply suggested tags without prompting'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('QuickSight Asset Scanner');

        // Load configuration
        $config       = require PROJECT_ROOT . '/config/global.php';
        $awsRegion    = $config['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        if (empty($awsAccountId)) {
            $awsAccountId = $io->ask('Enter AWS Account ID');
        }

        // Create QuickSight client
        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        // Create tagging manager
        $manager = new AssetTaggingManager(
            config:       $config,
            quickSight:   $qsClient,
            awsAccountId: $awsAccountId,
            awsRegion:    $awsRegion,
            io:           $io
        );

        // Determine which asset types to scan
        $dashboards = $input->getOption('dashboard');
        $datasets   = $input->getOption('dataset');
        $analyses   = $input->getOption('analysis');
        $users      = $input->getOption('users');
        $auto       = $input->getOption('auto');

        if ($auto) {
            $io->info("Auto-tagging mode enabled - suggested tags will be applied without prompting");
        }

        // If only users is specified
        if ($users && !$dashboards && !$datasets && !$analyses) {
            $stats = $manager->scanAndTagUsers(autoApply: $auto);

            // Output summary specific to users
            $io->section('User Tagging Summary');
            $io->success("User tagging completed: {$stats['total']} total, {$stats['tagged']} tagged");
            return Command::SUCCESS;
        }

        // If none specified, scan all asset types (not including users)
        if (!$dashboards && !$datasets && !$analyses) {
            $dashboards = $datasets = $analyses = true;
        }

        // Run interactive scan and tagging
        $stats = $manager->interactiveScan(
            tagDashboards: $dashboards,
            tagDatasets: $datasets,
            tagAnalyses: $analyses,
            autoApply: $auto
        );

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
            $io->note('Use the reporting:asset-report command to generate a full asset report.');
        } else {
            $io->success('All assets tagged successfully');
        }

        return Command::SUCCESS;
    }
}
