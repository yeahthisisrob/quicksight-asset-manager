<?php

namespace QSAssetManager\Command\Exploration\Dashboard;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\QuickSight\QuickSightClient;
use QSAssetManager\Manager\Exploration\Dashboard\DashboardManager;

class DeployDashboardCommand extends Command
{
    protected static $defaultName = 'dashboard:deploy';

    protected function configure()
    {
        $this
            ->setDescription(
                'Deploy a QuickSight dashboard using a combined deployment config JSON file.'
            )
            ->addArgument(
                'configFile',
                InputArgument::REQUIRED,
                'Path to the combined dashboard deployment JSON file.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configFile = $input->getArgument('configFile');
        if (!file_exists($configFile)) {
            $output->writeln("<error>Cannot read config file: $configFile</error>");
            return Command::FAILURE;
        }
        $deploymentConfig = json_decode(file_get_contents($configFile), true);
        if (!$deploymentConfig) {
            $output->writeln("<error>Invalid JSON in config file.</error>");
            return Command::FAILURE;
        }
        // Load global config.
        $globalConfig = require PROJECT_ROOT . '/config/global.php';
        $awsRegion = $globalConfig['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $globalConfig['awsAccountId'] ?? '';

        $qsClient = new QuickSightClient([
            'version' => '2018-04-01',
            'region'  => $awsRegion,
        ]);

        $manager = new DashboardManager($globalConfig, $qsClient, $awsAccountId, $awsRegion);

        if ($manager->deployDashboard($deploymentConfig)) {
            $output->writeln("<info>Dashboard deployed successfully.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>Failed to deploy dashboard.</error>");
            return Command::FAILURE;
        }
    }
}
