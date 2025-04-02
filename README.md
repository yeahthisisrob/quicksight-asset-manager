# QuickSight Asset Manager

A command-line toolkit for AWS QuickSight asset administration, built with PHP and Symfony Console.

## Overview

QSAssetManager streamlines QuickSight maintenance operations through API automation, enabling administrators to efficiently modify, deploy, and manage QuickSight resources from the command line.

## Core Capabilities

- **Asset Modification**
  - Update dashboard and analysis names via API
  - Clean up invalid dataset references with automatic detection
  - Preserve asset history while creating new versions

- **Deployment Automation**
  - Deploy dashboards and analyses from JSON templates
  - Map dataset identifiers between environments
  - Apply permissions, tags, and custom string replacements

- **Asset Administration**
  - Interactive and automated asset tagging
  - CloudTrail-based dashboard usage analytics
  - Comprehensive asset inventory reporting
  - Folder-based organization scanning

## Requirements

- PHP 8.0 or higher
- Composer
- AWS SDK for PHP
- Symfony Console
- AWS credentials with appropriate QuickSight permissions

## Installation

```bash
# Clone repository
git clone https://github.com/yeahthisisrob/qs-asset-manager.git
cd qs-asset-manager

# Install dependencies
composer install

# Configure your environment
cp config/global.example.php config/global.php
# Edit global.php with your AWS account details
```

For tagging functionality (optional):

```bash
cp config/groups.example.php config/groups.php
# Customize groups.php with your tagging structure
```

## Command Reference

You can view all available commands by running:

```bash
php bin/console list
```

This will display output similar to:

```
QSAssetManager 1.0.0
Usage:
  command [options] [arguments]
Options:
  -h, --help            Display help for the given command. When no command is given display help for the list command
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
Available commands:
  completion                              Dump the shell completion script
  help                                    Display help for a command
  list                                    List commands
 analysis
  analysis:deploy                         Deploy a QuickSight analysis using a combined deployment config JSON file.
  analysis:remove-broken-datasets         Remove broken DataSetIdentifiers from a QuickSight analysis.
  analysis:rename                         Rename an existing QuickSight analysis.
 assets
  assets:scan                             Scan QuickSight assets and interactively tag them with groups
 dashboard
  dashboard:deploy                        Deploy a QuickSight dashboard using a combined deployment config JSON file.
  dashboard:remove-broken-datasets        Remove broken DataSetIdentifiers from a QuickSight dashboard.
  dashboard:rename                        Rename an existing QuickSight dashboard.
 reporting
  reporting:asset-report                  Generate a CSV report of QuickSight assets with folder, permission, and tag details.
  reporting:export-dashboard-view-counts  Export dashboard view counts report based on CloudTrail events.
  reporting:user-report                   Generate a CSV report of QuickSight users with metadata and embed stats.
```

For detailed help on any specific command, use:

```bash
php bin/console help [command]
```

### Dashboard Operations

```bash
# Update dashboard name (creates a new version via API)
php bin/console dashboard:rename [dashboardId] [newName]

# Clean up broken dataset references in a dashboard
php bin/console dashboard:remove-broken-datasets [dashboardId]

# Deploy dashboard from a JSON template (create new or update existing)
php bin/console dashboard:deploy [path/to/deployment.json]

# Export dashboard usage analytics from CloudTrail
php bin/console dashboard:export-view-counts [outputPath]
```

### Analysis Operations

```bash
# Update analysis name (creates a new version via API)
php bin/console analysis:rename [analysisId] [newName]

# Clean up broken dataset references in an analysis
php bin/console analysis:remove-broken-datasets [analysisId]

# Deploy analysis from a JSON template
php bin/console analysis:deploy [path/to/deployment.json]
```

### Asset Management

```bash
# Interactive asset tagging
php bin/console assets:scan

# Selective asset scanning
php bin/console assets:scan --dashboard  # Only dashboards
php bin/console assets:scan --dataset    # Only datasets
php bin/console assets:scan --analysis   # Only analyses

# Generate comprehensive asset report
php bin/console assets:scan --report
```

## Technical Details

### How Dashboard/Analysis Renaming Works

When renaming a dashboard or analysis, the tool:

1. Retrieves the current asset definition via the QuickSight API
2. Creates a new version with the updated name via the API
3. For dashboards, updates the published version to the latest

This process preserves the asset ID and all other properties while creating a new version in the version history.

### Broken Dataset Reference Cleanup

The tool detects and removes references to datasets that are declared in the dashboard/analysis but not properly defined in the DataSetIdentifierDeclarations section:

1. Scans the entire asset definition for all DataSetIdentifier values
2. Compares against the officially declared identifiers
3. Removes invalid references from FilterGroups, Visuals, and other components
4. Creates a new cleaned version of the asset via API

### Deployment Process

The deployment commands use AWS API operations to:

1. Read a JSON deployment configuration
2. Handle both create and update operations
3. Map dataset identifiers between environments
4. Apply defined permissions and tags
5. Update published versions (for dashboards)

## Configuration Reference

### Main Configuration (global.php)

```php
return [
    'awsRegion'    => 'us-west-2',     // Your AWS region
    'awsAccountId' => '123456789012',  // Your AWS account ID
    'paths' => [
        'template_path'     => __DIR__ . '/../templates',
        'export_path'       => __DIR__ . '/../exports/groups',
        'group_config_path' => __DIR__,
    ],
    'tagging' => [
        'default_tag'         => 'ungrouped',
        'groups_config_file'  => __DIR__ . '/groups.php',
    ],
];
```

### Deployment JSON Structure

```json
{
  "Name": "Dashboard Name",
  "DashboardId": "[optional-id]",
  "DestinationAwsAccountId": "123456789012",
  "AwsRegion": "us-west-2",
  "DataSetIdentifierDeclarations": [
    {
      "Identifier": "dataset1",
      "DataSetArn": "arn:aws:quicksight:region:account:dataset/datasetId"
    }
  ],
  "StringReplacements": {
    "OldText": "NewText"
  },
  "DefaultPermissions": [
    {
      "Principal": "arn:aws:quicksight:region:account:user/default/username",
      "Actions": ["quicksight:DescribeDashboard", "quicksight:ListDashboardVersions"]
    }
  ],
  "Tags": {
    "Environment": "Production",
    "Department": "Marketing"
  },
  "template": {
    // Full dashboard/analysis definition
  }
}
```

## Common Workflows

### Broken Dataset Cleanup & Renaming

```bash
# Clean up invalid dataset references
php bin/console dashboard:remove-broken-datasets [dashboardId]

# Update dashboard name
php bin/console dashboard:rename [dashboardId] "Updated Dashboard Name"
```

### Cross-Environment Deployment

```bash
# Export dashboard definition from source environment
# (Use AWS QuickSight API, not included in this tool)

# Create deployment JSON with target environment details
# Deploy to target environment
php bin/console dashboard:deploy [path/to/deployment.json]
```

### Asset Audit & Tagging

```bash
# Generate asset inventory report
php bin/console assets:scan --report

# Interactive tagging session
php bin/console assets:scan

# Export usage analytics
php bin/console dashboard:export-view-counts [outputPath]
```

## Project Structure

```
qs-asset-manager/
├── bin/
│   └── console                  # Main command-line entry point
├── config/
│   ├── global.example.php       # Template configuration
│   ├── global.php               # Your custom configuration
│   ├── groups.example.php       # Template for tagging groups
│   └── groups.php               # Your tagging configuration
├── src/
│   ├── Command/                 # CLI commands
│   │   ├── Exploration/         # Dashboard/Analysis commands
│   │   └── Tagging/             # Asset tagging commands
│   ├── Manager/                 # Core business logic
│   │   ├── Exploration/
│   │   └── Tagging/
│   └── Utils/                   # Helper utilities
├── exports/                     # Default output directory
├── templates/                   # Template storage
├── vendor/                      # Composer dependencies
├── composer.json
└── README.md
```

## Best Practices

- **Backup Important Assets**: Always ensure critical dashboards are backed up before modifications
- **Test Deployments**: Test deployment templates in a non-production environment first
- **Tagging Standards**: Establish consistent tagging conventions for organization-wide use
- **Regular Maintenance**: Schedule periodic runs of broken dataset cleanup operations
- **Audit Trail**: Use CloudTrail-based analytics for compliance and usage monitoring

## Troubleshooting

### Common Issues

- **AWS Authentication Errors**: Verify AWS credentials and permissions
- **API Throttling**: For large batch operations, add delays between API calls
- **Missing Dataset Declarations**: Ensure all referenced datasets are properly declared
- **Version Conflicts**: When updating assets, verify you're working with the latest version

### Debug Mode

For detailed execution information, use the verbose flag:

```bash
php bin/console [command] -vvv
```

## License

MIT License - See `LICENSE` file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.