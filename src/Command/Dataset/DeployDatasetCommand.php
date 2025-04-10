<?php

namespace QSAssetManager\Command\Dataset;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\QuickSight\QuickSightClient;
use QSAssetManager\Manager\Dataset\DatasetManager;

class DeployDatasetCommand extends Command
{
    protected static $defaultName = 'dataset:deploy';

    protected function configure(): void
    {
        $this
            ->setDescription('Deploy a QuickSight dataset using a combined deployment config JSON file.')
            ->addArgument(
                'configFile',
                InputArgument::REQUIRED,
                'Path to the dataset deployment JSON file.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configFile = $input->getArgument('configFile');

        if (!file_exists($configFile)) {
            $output->writeln("<error>Cannot read config file: $configFile</error>");
            return Command::FAILURE;
        }

        $configJson = file_get_contents($configFile);
        if ($configJson === false) {
            $output->writeln("<error>Failed to read config file.</error>");
            return Command::FAILURE;
        }

        $deploymentConfig = json_decode($configJson, true);
        if (!is_array($deploymentConfig)) {
            $output->writeln("<error>Invalid JSON format in config file.</error>");
            return Command::FAILURE;
        }

        $globalConfig = require PROJECT_ROOT . '/config/global.php';
        $awsRegion = $globalConfig['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $globalConfig['awsAccountId'] ?? '';

        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        $manager = new DatasetManager($globalConfig, $qsClient, $awsAccountId, $awsRegion);

        if ($manager->deployDataset($deploymentConfig)) {
            $output->writeln("<info>✅ Dataset deployed successfully.</info>");
            return Command::SUCCESS;
        }

        $output->writeln("<error>❌ Failed to deploy dataset.</error>");
        return Command::FAILURE;
    }
}
