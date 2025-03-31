# QuickSight Deployment Templates

This folder contains example templates for deploying QuickSight Dashboards and Datasets using the Asset Manager.

---

## Dashboard Template

The `template` block inside the dashboard export **IS** the [DescribeDashboardDefinition](https://docs.aws.amazon.com/quicksight/latest/APIReference/API_DescribeDashboardDefinition.html) response payload (the `Definition` object specifically).

When you export a dashboard using the asset manager, the tool wraps the API's `Definition` into the `template` block of the deployment JSON.

See `dashboard_template.json` for an example.

---

## Dataset Template

The dataset export is based on the [DescribeDataSet](https://docs.aws.amazon.com/quicksight/latest/APIReference/API_DescribeDataSet.html) API response.

However, **the export command will also append**:
- `DataSetRefreshProperties`
- `RefreshSchedules`

These do NOT come from the standard `DescribeDataSet` response but are added by the export tool to preserve refresh settings.

See `dataset_template.json` for an example.

---

## Usage Notes

1. Customize the templates by replacing ARNs, IDs, names, and other environment-specific values.
2. These templates are intended to be used with the `qsassetmanager deploy` command.
3. You may include additional fields or metadata depending on your internal conventions.

