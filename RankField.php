<?php
namespace INTERSECT\RankField;

use ExternalModules\AbstractExternalModule;
use INTERSECT\RankField\ActionTagHelper;

class RankField extends AbstractExternalModule {

    public function __construct() {
        parent::__construct();
    }

    private function initialise_ranking_interface($project_id, $record, $instrument) {
        if (!class_exists('INTERSECT\RankField\ActionTagHelper')) {
            include_once('classes/ActionTagHelper.php');
        }
        $action_tags = ActionTagHelper::getActionTags('@RANK', null, $instrument);
        if (empty($action_tags['@RANK'])) {
            return;
        }
        foreach ($action_tags['@RANK'] as $field_name => $params_array) {
            $json_params_string = $params_array[0] ?? null;
            if ($json_params_string) {
                // Attempt to decode the string as JSON.
                $params = json_decode($json_params_string, true);

                // --- FIX: First, check if the decode was successful and resulted in an array ---
                if (is_array($params)) {
                    // If it's an array, we are using the JSON object syntax.
                    // Now we can safely check for the 'fields' key.
                    if (isset($params['fields'])) {
                        $rank_vars = array_map('trim', explode(',', $params['fields']));
                        $min = $params['min'] ?? null;
                        $max = $params['max'] ?? null;
                        $showMessage = ($params['message'] ?? true) !== false;
                        $this->render_rank_ui($field_name, $rank_vars, $min, $max, $showMessage);
                    }
                    // If it's an array but 'fields' is missing, we do nothing, as it's an invalid format.

                } else {
                    // If it's NOT an array, the string was not JSON. Treat it as a simple comma-separated list.
                    $rank_vars = array_map('trim', explode(',', $json_params_string));
                    // Call the render function with default values for min, max, etc.
                    $this->render_rank_ui($field_name, $rank_vars);
                }
            }
        }
    }

    /* public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance = 1) { */
    /*     $this->initialise_ranking_interface($project_id, $record, $instrument); */
    /* } */

    public function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1) {
        $this->initialise_ranking_interface($project_id, $record, $instrument);
    }
    
    // NOTE: The signature was updated to accept the $showMessage parameter
private function render_rank_ui($descriptive_field, $rank_vars, $min = null, $max = null, $showMessage = true) {
        global $Proj;
        $fields_to_hide = array_merge([$descriptive_field], $rank_vars);
        $min_attr = is_numeric($min) ? "data-original-min='{$min}'" : "";
        $max_attr = is_numeric($max) ? "data-original-max='{$max}'" : "";
        $rank_vars_json = json_encode($rank_vars);
        $all_fields_json = json_encode($fields_to_hide);
        ?>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
        <script src="https://cdn.tailwindcss.com/"></script>
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

        <style>
            .rank-field-hidden { position: absolute; left: -9999px;} /* A hack so that we can hide the original field and yet still inject its value */
            .rank-container { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px; }
            .rank-box { flex: 1; min-width: 250px; padding: 15px; border: 2px dashed #ccc; border-radius: 8px; background-color: #f9f9f9; min-height: 150px; transition: border-color 0.3s, opacity 0.3s; }
            .rank-box h3 { margin-top: 0; margin-bottom: 15px; color: #555; text-align: center; }
            .rank-items-container { min-height: 100px; }
            .rank-item { padding: 10px 15px; background-color: #fff; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 8px; cursor: grab; user-select: none; }
            .rank-box.invalid { border-color: #dc3545; }
            .rank-box.valid { border-color: #28a745; }
            .rank-status-message { color: #666; font-size: 0.9em; margin-top: 10px; height: 1em; text-align: center; transition: color 0.3s; width: 100%; }
            .rank-status-message.invalid-text::after { font-family: "Font Awesome 5 Free"; font-weight: 900; content: "\f071"; margin-left: 8px; }
            .rank-status-message.valid-text::after { font-family: "Font Awesome 5 Free"; font-weight: 900; content: "\f058"; margin-left: 8px; }
            .rank-status-message.invalid-text { color: #dc3545; }
            .rank-status-message.valid-text { color: #28a745; }
            .rank-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 15px; background-color: #fff; border: 1px solid #ddd;
                border-radius: 5px; margin-bottom: 8px; cursor: grab; user-select: none;
            }
            .rank-badge {
                display: none; /* Hidden by default */
                flex-shrink: 0;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                background-color: #007bff;
                color: white;
                font-size: 12px;
                font-weight: bold;
                text-align: center;
                line-height: 24px; /* Vertically center the number */
            }
            .rank-label {
                flex-grow: 1;
            }
            /* Only show the badge when the tile is in the ranking pane */
            #ranking-items-<?php echo $descriptive_field; ?> .rank-badge {
                display: inline-block;
            }
        </style>

        <script>
        $(document).ready(function() {
            const descriptiveFieldTr = $('#<?php echo $descriptive_field; ?>-tr');
            const allPossibleRankVars = <?php echo $rank_vars_json; ?>;
            const allManagedFields = <?php echo $all_fields_json; ?>;
            const hidingClass = 'rank-field-hidden';

            allManagedFields.forEach(fieldName => {
                $('#' + fieldName + '-tr').addClass(hidingClass);
            });

            const rankUIHTML = `
                <tr id="rank-ui-<?php echo $descriptive_field; ?>" class="rank-field-container choicevert" style="text-indent: 0px !important; margin-left: 0px !important; margin-right: 0px !important;">
                    <td colspan="2">
                        <div class="rank-container" <?php echo $min_attr; ?> <?php echo $max_attr; ?>>
                            <div id="source-pane-<?php echo $descriptive_field; ?>" class="rank-box">
                                <h3><?php echo $this->tt('source_pane_header'); ?></h3>
                                <div id="source-items-<?php echo $descriptive_field; ?>" class="rank-items-container"></div>
                            </div>
                            <div id="ranking-pane-<?php echo $descriptive_field; ?>" class="rank-box">
                                <h3><?php echo $this->tt('rank_pane_header'); ?></h3>
                                <div id="ranking-items-<?php echo $descriptive_field; ?>" class="rank-items-container"></div>
                            </div>
                            <?php if ($showMessage): ?>
                                <div class="rank-status-message" id="status-<?php echo $descriptive_field; ?>"></div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            `;
            descriptiveFieldTr.after(rankUIHTML);

            const sourcePane = $('#source-pane-<?php echo $descriptive_field; ?>');
            const rankingPane = $('#ranking-pane-<?php echo $descriptive_field; ?>');
            const sourceItemsContainer = $('#source-items-<?php echo $descriptive_field; ?>');
            const rankingItemsContainer = $('#ranking-items-<?php echo $descriptive_field; ?>');
            const statusMessage = $('#status-<?php echo $descriptive_field; ?>');
            const mainContainer = rankingPane.closest('.rank-container');

            // --- FEATURE: Populate all tiles first, based on original fields ---
            allPossibleRankVars.forEach(fieldName => {
                const fieldTr = $('#' + fieldName + '-tr');
                if (fieldTr.length) {
                    const label = fieldTr.find('.labelrc div[data-mlm-type="label"]').text().trim();
                    /* const tile = $(`<div class='rank-item' data-var='${fieldName}'>${label}</div>`); */
                    const tile = $(`<div class='rank-item' data-var='${fieldName}'><span class="rank-badge"></span><span class="rank-label">${label}</span></div>`);
                    sourceItemsContainer.append(tile);
                }
            });

            // --- NEW: Function to check for saved data and arrange tiles ---
            function populateInitialRanks() {
                let rankedItems = [];
                allPossibleRankVars.forEach(fieldName => {
                    const input = $('input[name="' + fieldName + '"]');
                    const rank = parseInt(input.val(), 10);
                    if (!isNaN(rank) && rank > 0) {
                        rankedItems.push({ fieldName: fieldName, rank: rank });
                    }
                });

                // Sort the items by their saved rank
                rankedItems.sort((a, b) => a.rank - b.rank);

                // Move the tiles for the ranked items to the ranking pane in the correct order
                rankedItems.forEach(item => {
                    const tile = sourceItemsContainer.find(`.rank-item[data-var="${item.fieldName}"]`);
                    rankingItemsContainer.append(tile);
                });
            }

            new Sortable(sourceItemsContainer[0], { group: 'ranking-group-<?php echo $descriptive_field; ?>', animation: 150, onSort: updateWarningAndValidationRules });
            new Sortable(rankingItemsContainer[0], { group: 'ranking-group-<?php echo $descriptive_field; ?>', animation: 150, onSort: updateWarningAndValidationRules });

            function updateWarningAndValidationRules() {
                let visibleFields = [];
                allPossibleRankVars.forEach(fieldName => {
                    const fieldTr = $('#' + fieldName + '-tr');
                    fieldTr.removeClass(hidingClass);
                    const isVisibleByRedcap = fieldTr.is(':visible');
                    if (isVisibleByRedcap) {
                        visibleFields.push(fieldName);
                        fieldTr.addClass(hidingClass);
                    } else {
                        const tile = rankingItemsContainer.find(`.rank-item[data-var="${fieldName}"]`);
                        if(tile.length) sourceItemsContainer.append(tile);
                    }
                });
                const visibleCount = visibleFields.length;
                const originalMin = mainContainer.data('original-min') ?? null;
                const originalMax = mainContainer.data('original-max') ?? null;
                let effectiveMin = originalMin;
                let effectiveMax = originalMax;
                const mustRankAll = (originalMax !== null && originalMax >= visibleCount) || (originalMin === null && originalMax === null);
                let message = '';
                if (visibleCount === 0) {
                    message = '<?php echo $this->tt('msg_none'); ?>';
                    sourcePane.hide();
                    rankingPane.hide();
                } else {
                    sourcePane.show();
                    rankingPane.show();
                    if (mustRankAll && visibleCount > 0) {
                        message = '<?php echo $this->tt('msg_all'); ?>';
                        effectiveMin = visibleCount;
                        effectiveMax = visibleCount;
                    } else {
                        if (originalMin !== null) effectiveMin = Math.min(parseInt(originalMin), visibleCount);
                        if (originalMax !== null) effectiveMax = Math.min(parseInt(originalMax), visibleCount);
                        if (effectiveMin !== null && effectiveMax !== null && effectiveMin === effectiveMax && effectiveMin > 0) {
                            message = '<?php echo $this->tt('msg_exact'); ?>'.replace('{0}', effectiveMin);
                        } else if (effectiveMin !== null && effectiveMax !== null) {
                            message = '<?php echo $this->tt('msg_min_max'); ?>'.replace('{0}', effectiveMin).replace('{1}', effectiveMax);
                        } else if (effectiveMin !== null) {
                            message = '<?php echo $this->tt('msg_min'); ?>'.replace('{0}', effectiveMin);
                        } else if (effectiveMax !== null) {
                            message = '<?php echo $this->tt('msg_max'); ?>'.replace('{0}', effectiveMax);
                        }
                    }
                }
                rankingPane.data('effective-min', effectiveMin).data('effective-max', effectiveMax);
                if (statusMessage.length) {
                    statusMessage.text(message);
                }
                // Rebuild only the tiles that are still visible but unranked
                let availableOptionsHTML = '';
                visibleFields.forEach(fieldName => {
                    if (rankingItemsContainer.find(`.rank-item[data-var="${fieldName}"]`).length === 0) {
                        const label = $('#' + fieldName + '-tr').find('.labelrc div[data-mlm-type="label"]').text().trim();
                        availableOptionsHTML += `<div class='rank-item' data-var='${fieldName}'><span class="rank-badge"></span><span class="rank-label">${label}</span></div>`;
                    }
                });
                sourceItemsContainer.html(availableOptionsHTML);
                updateRanksAndValidation();
            }

            function updateRanksAndValidation() {
                const rankedItems = rankingItemsContainer.find('.rank-item');
                const rankedCount = rankedItems.length;
                const effectiveMin = rankingPane.data('effective-min');
                const effectiveMax = rankingPane.data('effective-max');
                allPossibleRankVars.forEach(varName => { $('input[name="' + varName + '"]').val(''); });
                rankedItems.each(function(index) {
                    $(this).closest('form').find('input[name="' + $(this).data('var') + '"]').val(index + 1).trigger('change');
                });

                rankedItems.each(function(index) {
                    const rank = index + 1;
                    $(this).find('.rank-badge').text(rank);
                    $('input[name="' + $(this).data('var') + '"]').val(rank).trigger('change');
                });

                let isValid = true;
                if ((effectiveMin !== null && rankedCount < effectiveMin) || (effectiveMax !== null && rankedCount > effectiveMax)) {
                    isValid = false;
                }
                if (rankedCount > 0 || (effectiveMin !== null && effectiveMin > 0)) {
                    rankingPane.toggleClass('invalid', !isValid).toggleClass('valid', isValid);
                } else {
                    rankingPane.removeClass('valid invalid');
                }
                if (statusMessage.length){
                    statusMessage.toggleClass('invalid-text', !isValid).toggleClass('valid-text', isValid && statusMessage.text() !== '');
                }
            }

            const observer = new MutationObserver(updateWarningAndValidationRules);
            allManagedFields.forEach(fieldName => {
                const fieldTr = document.getElementById(fieldName + '-tr');
                if(fieldTr) {
                    observer.observe(fieldTr, { attributes: true, attributeFilter: ['style'] });
                }
            });

            // --- NEW: Call the population function once before the first validation run ---
            populateInitialRanks();
            
            updateWarningAndValidationRules();
        });
        </script>
        <?php
    }
}

