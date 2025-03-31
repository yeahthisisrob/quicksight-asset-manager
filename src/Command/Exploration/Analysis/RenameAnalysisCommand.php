<?php

namespace QSAssetManager\Command\Exploration\Analysis;

use QSAssetManager\Manager\Exploration\Analysis\AnalysisManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\QuickSight\QuickSightClient;

class RenameAnalysisCommand extends Command
{
    protected static $defaultName = 'analysis:rename';

    protected function configure()
    {
        $this
            ->setDescription('Rename an existing QuickSight analysis.')
            ->setHelp(
                'This command renames a QuickSight analysis by retrieving its current definition and updating ' .
                'its name, which creates a new version.'
            )
            ->addArgument(
                'analysisId',
                InputArgument::REQUIRED,
                'The Analysis ID to rename.'
            )
            ->addArgument(
                'newName',
                InputArgument::REQUIRED,
                'The new name for the analysis.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $analysisId = $input->getArgument('analysisId');
        $newName    = $input->getArgument('newName');

        // Load global configuration
        $config       = require PROJECT_ROOT . '/config/global.php';
        $awsRegion    = $config['awsRegion']    ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        // Create QuickSight client
        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        // Instantiate AnalysisManager
        $manager = new AnalysisManager($config, $qsClient, $awsAccountId, $awsRegion);

        if ($manager->renameAnalysis($analysisId, $newName)) {
            $output->writeln("<info>Analysis $analysisId renamed to $newName successfully.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Failed to rename analysis $analysisId.</error>");
            return Command::FAILURE;
        }
    }
}
