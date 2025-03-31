<?php

namespace QSAssetManager\Command\Exploration\Analysis;

use QSAssetManager\Manager\Exploration\Analysis\AnalysisManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\QuickSight\QuickSightClient;

class RemoveBrokenAnalysisDataSetsCommand extends Command
{
    protected static $defaultName = 'analysis:remove-broken-datasets';

    protected function configure()
    {
        $this
            ->setDescription('Remove broken DataSetIdentifiers from a QuickSight analysis.')
            ->setHelp(
                'This command identifies and removes DataSetIdentifiers that are referenced in the analysis but ' .
                'not declared in the DataSetIdentifierDeclarations section.'
            )
            ->addArgument(
                'analysisId',
                InputArgument::REQUIRED,
                'The Analysis ID to clean.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $analysisId = $input->getArgument('analysisId');

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

        if ($manager->removeBrokenDataSetIdentifiers($analysisId)) {
            $output->writeln("<info>Successfully removed broken DataSetIdentifiers from analysis $analysisId.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Failed to remove broken DataSetIdentifiers from analysis $analysisId.</error>");
            return Command::FAILURE;
        }
    }
}
