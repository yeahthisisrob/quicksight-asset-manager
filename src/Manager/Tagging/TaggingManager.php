<?php

namespace QSAssetManager\Manager\Tagging;

use Aws\QuickSight\QuickSightClient;
use Aws\Exception\AwsException;
use QSAssetManager\Utils\QuickSightHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class TaggingManager
{
    protected array $config;
    protected QuickSightClient $quickSight;
    protected string $awsAccountId;
    protected string $awsRegion;
    protected array $groups;
    protected array $emailDomains;
    protected ?SymfonyStyle $io;
    protected string $tagKey;

    public function __construct(
        array $config,
        QuickSightClient $quickSight,
        string $awsAccountId,
        string $awsRegion,
        ?SymfonyStyle $io = null
    ) {
        $this->config       = $config;
        $this->quickSight   = $quickSight;
        $this->awsAccountId = $awsAccountId;
        $this->awsRegion    = $awsRegion;

        $groupsConfigPath = $config['tagging']['groups_config_file'] ?? null;
        if ($groupsConfigPath && file_exists($groupsConfigPath)) {
            $groupsConfig       = require $groupsConfigPath;
            $this->groups       = $groupsConfig['groups']        ?? [];
            $this->emailDomains = $groupsConfig['email_domains'] ?? [];
            $this->tagKey       = $groupsConfig['default_key']   ?? 'group';
        } else {
            $this->groups       = [];
            $this->emailDomains = [];
            $this->tagKey       = 'group';
        }
        $this->io = $io;
    }

    /**
     * Output a message with or without SymfonyStyle.
     */
    protected function output(string $message, string $type = 'info'): void
    {
        if ($this->io) {
            switch ($type) {
                case 'error':
                    $this->io->error($message);
                    break;
                case 'warning':
                    $this->io->warning($message);
                    break;
                case 'success':
                    $this->io->success($message);
                    break;
                default:
                    $this->io->text($message);
            }
        } else {
            echo $message . "\n";
        }
    }

    /**
     * Collect folder membership information for assets.
     */
    protected function collectFolderInfo(): array
    {
        $this->output(message: "Collecting folder information...");
        $folders = [];

        try {
            $foldersResponse = QuickSightHelper::executeWithRetry(
                $this->quickSight,
                'listFolders',
                ['AwsAccountId' => $this->awsAccountId]
            );

            foreach ($foldersResponse['FolderSummaryList'] as $folder) {
                $folderMembers = QuickSightHelper::executeWithRetry(
                    $this->quickSight,
                    'listFolderMembers',
                    [
                        'AwsAccountId' => $this->awsAccountId,
                        'FolderId'     => $folder['FolderId'],
                    ]
                );
                foreach ($folderMembers['FolderMemberList'] as $member) {
                    if (!isset($folders[$member['MemberArn']])) {
                        $folders[$member['MemberArn']] = [];
                    }
                    $folders[$member['MemberArn']][] = $folder['Name'];
                }
            }

            return $folders;
        } catch (AwsException $e) {
            $this->output(
                message: "Error collecting folder information: " . $e->getMessage(),
                type:    'error'
            );
            return [];
        }
    }
}
