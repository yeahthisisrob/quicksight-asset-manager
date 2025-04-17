<?php

namespace QSAssetManager\Command\Permissions;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Aws\QuickSight\QuickSightClient;
use QSAssetManager\Manager\Permissions\PermissionsManager;

class AddPrincipalPermissionsCommand extends Command
{
    protected static $defaultName = 'permissions:add-principal';

    protected function configure(): void
    {
        $this
            ->setDescription('Grant owner or reader permissions to a principal on QS assets')
            ->addArgument('principal-arn', InputArgument::REQUIRED, 'QuickSight principal ARN (user or group)')
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                'Asset type: dashboards,datasets,datasources,analyses,all',
                'all'
            )
            ->addOption(
                'role',
                'r',
                InputOption::VALUE_REQUIRED,
                'Permission role: owner or reader',
                'owner'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NEGATABLE,
                'Dry‑run mode (no actual API calls)',
                true
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip confirmation prompt'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $arn           = $input->getArgument('principal-arn');
        $type          = $input->getOption('type');
        $role          = $input->getOption('role');
        $validTypes    = ['dashboards','datasets','datasources','analyses','all'];
        $validRoles    = ['owner','reader'];
        if (!in_array($type, $validTypes, true)) {
            $io->error("Invalid type '{$type}'.");
            return Command::INVALID;
        }
        if (!in_array($role, $validRoles, true)) {
            $io->error("Invalid role '{$role}'.");
            return Command::INVALID;
        }
        $hasDryRun = $input->hasParameterOption(['--dry-run','--no-dry-run']);
        $dryRun    = $hasDryRun ? $input->getOption('dry-run') : true;
        $force     = $input->getOption('force');

        $config     = require PROJECT_ROOT . '/config/global.php';
        $region     = $config['awsRegion']    ?? 'us-west-2';
        $account    = $config['awsAccountId'] ?? $io->ask('AWS Account ID');

        $io->title('QS Permissions: Add Principal');
        $io->section('Config');
        $io->text("Account ARN   : {$account}");
        $io->text("Region        : {$region}");
        $io->text("Principal ARN : {$arn}");
        $io->text("Asset Type    : {$type}");
        $io->text("Role          : {$role}");
        $io->text("Dry Run       : " . ($dryRun ? 'Yes' : 'No'));

        if (!$dryRun && !$force && !$io->confirm('Proceed?', false)) {
            $io->warning('Cancelled.');
            return Command::SUCCESS;
        }

        $qsClient = new QuickSightClient([
            'version' => 'latest','region' => $region
        ]);
        $mgr = new PermissionsManager(
            $qsClient,
            $account,
            fn(string $msg, string $lvl = 'info') => match ($lvl) {
                'success'=> $io->success($msg),
                'error'  => $io->error($msg),
                'warning'=> $io->warning($msg),
                default  => $io->comment($msg),
            }
        );

        $stats = $mgr->grantPermissionsForPrincipal($arn, $dryRun, $type, $role);

        $io->section('Summary');
        $io->definitionList(
            ['Dashboards Scanned'  => $stats['dashboards']],
            ['Dashboards Updated'  => $stats['dashboards_updated']],
            ['Datasets Scanned'    => $stats['datasets']],
            ['Datasets Updated'    => $stats['datasets_updated']],
            ['DataSources Scanned' => $stats['datasources']],
            ['DataSources Updated' => $stats['datasources_updated']],
            ['Analyses Scanned'    => $stats['analyses']],
            ['Analyses Updated'    => $stats['analyses_updated']],
            ['Type'                => ucfirst($type)],
            ['Role'                => ucfirst($role)],
            ['Dry Run'             => $dryRun ? 'Yes' : 'No']
        );

        return Command::SUCCESS;
    }
}
