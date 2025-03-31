<?php

namespace QSAssetManager\Command\Exploration\Analysis;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\QuickSight\QuickSightClient;
use QSAssetManager\Manager\Exploration\Analysis\AnalysisManager;

class DeployAnalysisCommand extends Command
{
    protected static $defaultName = 'analysis:deploy';

    protected function configure(): void
    {
        $this
            ->setDescription(
                description: 'Deploy a QuickSight analysis using a combined deployment config JSON file.'
            )
            ->addArgument(
                name: 'configFile',
                mode: InputArgument::REQUIRED,
                description: 'Path to the deployment JSON file.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configFileArg = $input->getArgument('configFile');

        // Type assertion for PHPStan
        if (!is_string($configFileArg)) {
            $output->writeln("<error>Config file path must be a string.</error>");
            return Command::FAILURE;
        }

        $configFile = $configFileArg; // Now $configFile is known to be string

        if (!file_exists($configFile)) {
            $output->writeln("<error>Cannot read config file: {$configFile}</error>");
            return Command::FAILURE;
        }

        $fileContents = file_get_contents($configFile);
        if ($fileContents === false) {
            $output->writeln("<error>Failed to read config file content.</error>");
            return Command::FAILURE;
        }

        $deploymentConfig = json_decode($fileContents, true);
        if (!is_array($deploymentConfig)) {
            $output->writeln("<error>Invalid JSON in config file.</error>");
            return Command::FAILURE;
        }

        // Load global config
        $globalConfigPath = dirname(__DIR__, 3) . '/config/global.php';
        $globalConfig = require $globalConfigPath;
        $awsRegion = $globalConfig['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $globalConfig['awsAccountId'] ?? '';

        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region' => $awsRegion,
        ]);

        $manager = new AnalysisManager($globalConfig, $qsClient, $awsAccountId, $awsRegion);

        if ($manager->deployAnalysis($deploymentConfig)) {
            $output->writeln("<info>Analysis deployed successfully.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Failed to deploy analysis.</error>");
            return Command::FAILURE;
        }
    }
}
