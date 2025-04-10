<?php

namespace QSAssetManager\Command\Dataset;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\QuickSight\QuickSightClient;
use QSAssetManager\Manager\Dataset\DatasetManager;

class RenameDatasetCommand extends Command
{
    protected static $defaultName = 'dataset:rename';

    protected function configure(): void
    {
        $this
            ->setDescription('Rename a QuickSight dataset by ID.')
            ->addArgument(
                'datasetId',
                InputArgument::REQUIRED,
                'The dataset ID to rename.'
            )
            ->addArgument(
                'newName',
                InputArgument::REQUIRED,
                'The new name for the dataset.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $datasetId = $input->getArgument('datasetId');
        $newName = $input->getArgument('newName');

        $globalConfig = require PROJECT_ROOT . '/config/global.php';
        $awsRegion = $globalConfig['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $globalConfig['awsAccountId'] ?? '';

        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        $manager = new DatasetManager($globalConfig, $qsClient, $awsAccountId, $awsRegion);

        if ($manager->renameDataset($datasetId, $newName)) {
            $output->writeln("<info>✅ Dataset renamed successfully to '{$newName}'.</info>");
            return Command::SUCCESS;
        }

        $output->writeln("<error>❌ Failed to rename dataset.</error>");
        return Command::FAILURE;
    }
}
