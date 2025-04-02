<?php

namespace QSAssetManager\Command\Reporting;

use QSAssetManager\Manager\Reporting\UserReportingManager;
use Aws\QuickSight\QuickSightClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ExportUserReportCommand extends Command
{
    protected static $defaultName = 'reporting:user-report';

    protected function configure(): void
    {
        $this
            ->setDescription(
                description: 'Generate a CSV report of QuickSight users with metadata and embed stats.'
            )
            ->setHelp(
                'This command exports a CSV report listing all QuickSight users with details from ' .
                'listUsers, tags, and embed call statistics (total calls and last embed time over ' .
                'the past 90 days). Optionally, include user group memberships with --with-groups.'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Specify the output directory for the CSV file'
            )
            ->addOption(
                'with-groups',
                null,
                InputOption::VALUE_NONE,
                'Include a "Groups" column with user group memberships'
            )
            ->addOption(
                'skip-tags',
                null,
                InputOption::VALUE_NONE,
                'Skip fetching user tags (speeds up processing significantly)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $io = new SymfonyStyle($input, $output);
        $io->title('QuickSight User Report Generator');

        $config       = require PROJECT_ROOT . '/config/global.php';
        $awsRegion    = $config['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        if (empty($awsAccountId)) {
            $io->error("AWS Account ID is required in the global configuration.");
            return Command::FAILURE;
        }

        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        $manager = new UserReportingManager(
            config:       $config,
            quickSight:   $qsClient,
            awsAccountId: $awsAccountId,
            awsRegion:    $awsRegion,
            io:           $io
        );

        $outputPath = $input->getOption('output') ?: null;
        $withGroups = $input->getOption('with-groups');
        $withTags   = !$input->getOption('skip-tags');

        $options = [];
        if ($withGroups) {
            $options[] = 'with user groups';
        }
        if ($withTags) {
            $options[] = 'with user tags';
        }
        if (!$withTags) {
            $options[] = 'tags SKIPPED (faster)';
        }

        $io->section('Export Settings');
        $io->listing($options);

        if (!$withTags) {
            $io->note("User tags will be skipped to speed up processing");
        }

        $result = $manager->exportUserReport(
            outputPath: $outputPath,
            withGroups: $withGroups,
            withTags: $withTags
        );

        if ($result) {
            $totalTime = microtime(true) - $startTime;
            $io->success([
                "User report generated successfully: $result",
                sprintf("Total execution time: %.2f seconds", $totalTime)
            ]);
            return Command::SUCCESS;
        } else {
            $io->error("Failed to generate user report.");
            return Command::FAILURE;
        }
    }
}
