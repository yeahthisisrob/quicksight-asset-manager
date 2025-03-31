<?php

namespace QSAssetManager\Manager\Exploration;

use QSAssetManager\Manager\AssetManager;

/**
 * Abstract class for exploration-type assets (dashboards and analyses)
 * that shares common functionality between these related asset types
 */
abstract class ExplorationAssetManager extends AssetManager
{
    /**
     * Removes broken DataSetIdentifiers from the exploration asset
     *
     * @param string $assetId ID of the asset
     * @return bool Success status
     */
    public function removeBrokenDataSetIdentifiers(string $assetId): bool
    {
        // Get asset definition
        $definition = $this->getAssetDefinition($assetId);
        if ($definition === null) {
            return false;
        }

        // Get valid DataSetIdentifiers from declarations
        $validDataSetIds = $this->extractValidDataSetIds($definition);

        echo "Valid DataSetIdentifiers: " . implode(', ', $validDataSetIds) . "\n";

        $modified = false;
        $filtersToCheck = [];  // Store filter IDs that might need to be removed from other places

        // Clean FilterGroups
        if (isset($definition['FilterGroups'])) {
            $this->cleanFilterGroups(
                $definition,
                $validDataSetIds,
                $filtersToCheck,
                $modified
            );
        }

        // Clean filter controls that reference removed filters
        if (!empty($filtersToCheck) && isset($definition['Sheets'])) {
            $this->cleanFilterControls($definition, $filtersToCheck, $modified);
        }

        // Clean visuals
        if (isset($definition['Sheets'])) {
            $this->cleanVisuals($definition, $validDataSetIds, $modified);
        }

        if (!$modified) {
            echo "No broken DataSetIdentifiers found.\n";
            return true;
        }

        // Get asset name if needed
        $name = $this->getAssetName($assetId);

        // Update the asset with the cleaned definition
        return $this->updateAsset($assetId, $definition, $name);
    }

    /**
     * Extract valid DataSetIdentifiers from the definition
     *
     * @param array $definition Asset definition
     * @return array List of valid DataSetIdentifier values
     */
    protected function extractValidDataSetIds(array $definition): array
    {
        $validDataSetIds = [];
        if (isset($definition['DataSetIdentifierDeclarations'])) {
            foreach ($definition['DataSetIdentifierDeclarations'] as $declaration) {
                if (isset($declaration['Identifier'])) {
                    $validDataSetIds[] = $declaration['Identifier'];
                }
            }
        }
        return $validDataSetIds;
    }

    /**
     * Clean FilterGroups in the definition
     *
     * @param array &$definition The asset definition to modify
     * @param array $validDataSetIds List of valid DataSetIdentifier values
     * @param array &$filtersToCheck List to store filter IDs that might need to be removed from other places
     * @param bool &$modified Reference to a flag that will be set to true if changes were made
     */
    protected function cleanFilterGroups(
        array &$definition,
        array $validDataSetIds,
        array &$filtersToCheck,
        bool &$modified
    ): void {

        $filterGroupsToRemove = [];

        foreach ($definition['FilterGroups'] as $index => $filterGroup) {
            $filterGroupId = $filterGroup['FilterGroupId'] ?? '';
            $filtersToRemove = [];

            // Check each filter in the group
            if (isset($filterGroup['Filters'])) {
                foreach ($filterGroup['Filters'] as $filterIndex => $filter) {
                    $filterValid = true;
                    $filterId = '';

                    foreach ($filter as $filterType => $filterDetails) {
                        if (isset($filterDetails['FilterId'])) {
                            $filterId = $filterDetails['FilterId'];
                        }

                        if (
                            isset($filterDetails['Column']['DataSetIdentifier']) &&
                            !in_array($filterDetails['Column']['DataSetIdentifier'], $validDataSetIds)
                        ) {
                            // This filter uses an invalid DataSetIdentifier
                            $invalidDataSetId = $filterDetails['Column']['DataSetIdentifier'];
                            echo "Found invalid DataSetIdentifier '{$invalidDataSetId}' in FilterGroup " .
                                "'{$filterGroupId}', Filter '{$filterId}'" .
                                "\n";
                            $filterValid = false;
                            $filtersToCheck[] = $filterId;
                            $modified = true;
                        }
                    }

                    if (!$filterValid) {
                        $filtersToRemove[] = $filterIndex;
                    }
                }

                // Remove invalid filters (in reverse order to maintain indices)
                rsort($filtersToRemove);
                foreach ($filtersToRemove as $filterIndex) {
                    array_splice($definition['FilterGroups'][$index]['Filters'], $filterIndex, 1);
                    echo "Removed invalid filter from FilterGroup '$filterGroupId'\n";
                }

                // If all filters were removed, mark the filter group for removal
                if (empty($definition['FilterGroups'][$index]['Filters'])) {
                    $filterGroupsToRemove[] = $index;
                }
            }
        }

        // Remove filter groups that have no valid filters (in reverse order to maintain indices)
        rsort($filterGroupsToRemove);
        foreach ($filterGroupsToRemove as $index) {
            $filterGroupId = $definition['FilterGroups'][$index]['FilterGroupId'] ?? '';
            array_splice($definition['FilterGroups'], $index, 1);
            echo "Removed empty FilterGroup '$filterGroupId'\n";
        }

        // If all FilterGroups are removed, remove the FilterGroups key
        if (empty($definition['FilterGroups'])) {
            unset($definition['FilterGroups']);
            echo "Removed empty FilterGroups array\n";
        }
    }

    /**
     * Clean filter controls that reference removed filters
     *
     * @param array &$definition The asset definition to modify
     * @param array $filtersToCheck List of filter IDs that might need to be removed
     * @param bool &$modified Reference to a flag that will be set to true if changes were made
     */
    protected function cleanFilterControls(array &$definition, array $filtersToCheck, bool &$modified): void
    {
        foreach ($definition['Sheets'] as $sheetIndex => $sheet) {
            if (isset($sheet['FilterControls'])) {
                $controlsToRemove = [];

                foreach ($sheet['FilterControls'] as $controlIndex => $control) {
                    foreach ($control as $controlType => $controlDetails) {
                        if (
                            isset($controlDetails['SourceFilterId']) &&
                            in_array($controlDetails['SourceFilterId'], $filtersToCheck)
                        ) {
                            $controlsToRemove[] = $controlIndex;
                            $controlId = $controlDetails['FilterControlId'] ?? '';
                            echo "Removing FilterControl '$controlId' that " .
                                "references a removed filter\n";
                            $modified = true;
                        }
                    }
                }

                // Remove controls (in reverse order to maintain indices)
                rsort($controlsToRemove);
                foreach ($controlsToRemove as $controlIndex) {
                    array_splice($definition['Sheets'][$sheetIndex]['FilterControls'], $controlIndex, 1);
                }

                // If all filter controls were removed, remove the FilterControls key
                if (empty($definition['Sheets'][$sheetIndex]['FilterControls'])) {
                    unset($definition['Sheets'][$sheetIndex]['FilterControls']);
                }
            }
        }
    }

    /**
     * Clean visuals from invalid DataSetIdentifiers
     *
     * @param array &$definition The asset definition to modify
     * @param array $validDataSetIds List of valid DataSetIdentifier values
     * @param bool &$modified Reference to a flag that will be set to true if changes were made
     */
    protected function cleanVisuals(array &$definition, array $validDataSetIds, bool &$modified): void
    {
        foreach ($definition['Sheets'] as $sheetIndex => $sheet) {
            if (isset($sheet['Visuals'])) {
                foreach ($sheet['Visuals'] as $visualIndex => $visual) {
                    foreach ($visual as $visualType => $visualDetails) {
                        if (isset($visualDetails['ChartConfiguration']['FieldWells'])) {
                            $sheetRef = &$definition['Sheets'][$sheetIndex];
                            $visualRef = &$sheetRef['Visuals'][$visualIndex];
                            $fieldWells = &$visualRef[$visualType]['ChartConfiguration']['FieldWells'];
                            $this->cleanFieldWells($fieldWells, $validDataSetIds, $modified);
                        }
                    }
                }
            }
        }
    }

    /**
     * Helper method to clean invalid DataSetIdentifiers from FieldWells structures
     *
     * @param array &$fieldWells The FieldWells structure to clean
     * @param array $validDataSetIds List of valid DataSetIdentifier values
     * @param bool &$modified Reference to a flag that will be set to true if changes were made
     */
    protected function cleanFieldWells(array &$fieldWells, array $validDataSetIds, bool &$modified): void
    {
        // Check different types of field wells
        $wellTypes = [
            'TableAggregatedFieldWells',
            'PivotTableAggregatedFieldWells',
            'LineSeriesAggregatedFieldWells',
            'BarSeriesAggregatedFieldWells',
            'CategoricallyAggregatedFieldWells',
            'NumericallyAggregatedFieldWells'
        ];

        foreach ($wellTypes as $wellType) {
            if (isset($fieldWells[$wellType])) {
                $this->cleanFieldWellSection($fieldWells[$wellType], $validDataSetIds, $modified);
            }
        }

        // Check direct field wells
        $directWellTypes = ['Values', 'GroupBy', 'Colors', 'SmallMultiples', 'XAxis', 'YAxis'];
        foreach ($directWellTypes as $directWellType) {
            if (isset($fieldWells[$directWellType])) {
                $this->cleanFieldArray($fieldWells[$directWellType], $validDataSetIds, $modified);
            }
        }
    }

    /**
     * Helper method to clean a section of FieldWells
     *
     * @param array &$section The FieldWells section to clean
     * @param array $validDataSetIds List of valid DataSetIdentifier values
     * @param bool &$modified Reference to a flag that will be set to true if changes were made
     */
    protected function cleanFieldWellSection(array &$section, array $validDataSetIds, bool &$modified): void
    {
        $sectionTypes = ['Values', 'GroupBy', 'Rows', 'Columns', 'Colors', 'SmallMultiples'];

        foreach ($sectionTypes as $sectionType) {
            if (isset($section[$sectionType])) {
                $this->cleanFieldArray($section[$sectionType], $validDataSetIds, $modified);
            }
        }
    }

    /**
     * Helper method to clean an array of fields
     *
     * @param array &$fields The array of fields to clean
     * @param array $validDataSetIds List of valid DataSetIdentifier values
     * @param bool &$modified Reference to a flag that will be set to true if changes were made
     */
    protected function cleanFieldArray(array &$fields, array $validDataSetIds, bool &$modified): void
    {
        $fieldsToRemove = [];

        foreach ($fields as $index => $field) {
            // Check different field types
            $fieldTypes = [
                'CategoricalDimensionField',
                'DateDimensionField',
                'NumericalDimensionField',
                'NumericalMeasureField',
                'CategoricalMeasureField',
                'DateMeasureField'
            ];

            foreach ($fieldTypes as $fieldType) {
                if (
                    isset($field[$fieldType]['Column']['DataSetIdentifier']) &&
                    !in_array(
                        $field[$fieldType]['Column']['DataSetIdentifier'],
                        $validDataSetIds
                    )
                ) {
                    $invalidId = $field[$fieldType]['Column']['DataSetIdentifier'];
                    $fieldId = $field[$fieldType]['FieldId'] ?? 'unknown';

                    echo "Found invalid DataSetIdentifier '$invalidId' in field '$fieldId'\n";

                    $fieldsToRemove[] = $index;
                    $modified = true;
                    break;
                }
            }
        }

        // Remove invalid fields (in reverse order to maintain indices)
        rsort($fieldsToRemove);
        foreach ($fieldsToRemove as $index) {
            array_splice($fields, $index, 1);
        }
    }
}
