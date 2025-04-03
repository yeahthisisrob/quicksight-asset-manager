<?php

namespace QSAssetManager\Command\User;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Aws\QuickSight\QuickSightClient;
use Aws\CloudTrail\CloudTrailClient;
use QSAssetManager\Manager\User\UserManager;

/**
 * Command to find and delete inactive QuickSight users
 */
class DeleteInactiveUsersCommand extends Command
{
    protected static $defaultName = 'users:delete-inactive';

    protected function configure()
    {
        $this
            ->setDescription('Find and delete inactive QuickSight users')
            ->setHelp(
                help: 'Identifies and optionally deletes QuickSight users who have been inactive for a specified period'
            )
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Consider users inactive after this many days of no activity',
                90
            )
            ->addOption(
                'identity-types',
                'i',
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of identity types to check (QUICKSIGHT,IAM)',
                'QUICKSIGHT'
            )
            ->addOption(
                'user-roles',
                'r',
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of user roles to check (ADMIN,AUTHOR,READER,PRO_USER)',
                'READER'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NEGATABLE,
                'Run in dry-run mode (no actual deletions)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force deletion without confirmation (use with caution)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('QuickSight Inactive User Management');

        // Load configuration
        $config = require PROJECT_ROOT . '/config/global.php';
        $awsRegion = $config['awsRegion'] ?? 'us-west-2';
        $awsAccountId = $config['awsAccountId'] ?? '';

        if (empty($awsAccountId)) {
            $awsAccountId = $io->ask('Enter AWS Account ID');
        }

        // Load user management config
        $userConfigFile = $config['user_management']['config_file'] ?? null;
        $userConfig = is_file($userConfigFile) ? require $userConfigFile : [];

        // Parse command options
        $inactiveDays = (int) $input->getOption('days');

        $identityTypesString = $input->getOption('identity-types');
        $identityTypes = explode(',', $identityTypesString);

        $userRolesString = $input->getOption('user-roles');
        $userRoles = explode(',', $userRolesString);

        // Check if the user explicitly provided --dry-run or --no-dry-run
        $optionProvided = $input->hasParameterOption('--dry-run') || $input->hasParameterOption('--no-dry-run');
        if ($optionProvided) {
            $dryRun = $input->getOption('dry-run');
        } else {
            // Fall back to the default defined in config (true or false)
            $dryRun = $userConfig['default_dry_run'] ?? true;
        }
        $force = $input->getOption('force');

        // Initialize AWS clients
        $quickSight = new QuickSightClient([
            'version' => 'latest',
            'region'  => $awsRegion,
        ]);

        $cloudTrail = new CloudTrailClient([
            'version' => 'latest',
            'region'  => $awsRegion,
        ]);

        // Create UserManager instance with output callback
        $userManager = new UserManager(
            $quickSight,
            $cloudTrail,
            $awsAccountId,
            $awsRegion,
            $userConfig,
            function ($message, $type = 'info') use ($io) {
                switch ($type) {
                    case 'success':
                        $io->success($message);
                        break;
                    case 'error':
                        $io->error($message);
                        break;
                    case 'warning':
                        $io->warning($message);
                        break;
                    case 'comment':
                        $io->comment($message);
                        break;
                    default:
                        $io->text($message);
                }
            }
        );

        // Display run configuration
        $io->section('Configuration');
        $io->definitionList(
            ['Inactive Days Threshold' => $inactiveDays],
            ['Identity Types' => implode(', ', $identityTypes)],
            ['User Roles' => implode(', ', $userRoles)],
            ['Dry Run' => $dryRun ? 'Yes' : 'No']
        );

        if (!$dryRun && !$force) {
            $confirmed = $io->confirm(
                'This will delete inactive QuickSight users. Are you sure you want to continue?',
                false
            );

            if (!$confirmed) {
                $io->warning('Operation cancelled by user.');
                return Command::SUCCESS;
            }
        }

        // Execute the inactive user scan and deletion
        $stats = $userManager->findAndDeleteInactiveUsers(
            $inactiveDays,
            $identityTypes,
            $userRoles,
            $dryRun
        );

        // Display results
        $io->section('Results');
        $io->definitionList(
            ['Total Users Scanned' => $stats['total_users']],
            ['Inactive Users Found' => $stats['inactive_users']],
            ['Protected Users' => $stats['protected_users'] ?? 0],
            ['Users Deleted' => $stats['deleted_users']],
            ['Failed Deletions' => $stats['failed_deletions']],
            ['Dry Run' => $stats['dry_run'] ? 'Yes' : 'No']
        );

        return Command::SUCCESS;
    }
}
