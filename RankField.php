<?php
namespace INTERSECT\RankField;

use ExternalModules\AbstractExternalModule;
use INTERSECT\RankField\ActionTagHelper;

class RankField extends AbstractExternalModule {

    private static $assets_loaded = false;

    private function initialise_ranking_interface($project_id, $record, $instrument) {
        if (!class_exists('INTERSECT\RankField\ActionTagHelper')) {
            include_once('classes/ActionTagHelper.php');
        }

        $action_tags = ActionTagHelper::getActionTags('@RANK', null, $instrument);
        if (empty($action_tags['@RANK'])) {
            return;
        }

        if (!self::$assets_loaded) {
            self::$assets_loaded = true; // Load resources, but only the first time.

            ?>
            <link rel="stylesheet" href="<?= $this->getUrl('assets/css/tailwind.min.css') ?>">
            <script src="<?= $this->getUrl('assets/js/sortable.min.js') ?>"></script>

            <style>
                .rank-field-hidden { position: absolute; left: -9999px;}
                .rank-container { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px; }
                .rank-box { flex: 1; min-width: 250px; padding: 15px; border: 2px dashed #ccc; border-radius: 8px; background-color: #f9f9f9; min-height: 150px; transition: border-color 0.3s, opacity 0.3s; }
                .rank-box h3 { margin-top: 0; margin-bottom: 15px; color: #555; text-align: center; }
                .rank-items-container { min-height: 100px; }
                .rank-item { display: flex; align-items: center; gap: 10px; padding: 10px 15px; background-color: #fff; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 8px; cursor: grab; user-select: none; }
                .sortable-ghost { opacity: 0.0; }
                .rank-badge { display: none; flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background-color: #007bff; color: white; font-size: 12px; font-weight: bold; text-align: center; line-height: 24px; }
                .rank-label { flex-grow: 1; }
                .rank-items-container .rank-badge { display: inline-block; }
                .rank-box.invalid { border-color: #dc3545; }
                .rank-box.valid { border-color: #28a745; }
                .rank-status-message { color: #666; font-size: 0.9em; margin-top: 10px; height: 1em; text-align: center; transition: color 0.3s; width: 100%; }
                .rank-status-message.invalid-text::after { font-family: "Font Awesome 5 Free"; font-weight: 900; content: "\f071"; margin-left: 8px; }
                .rank-status-message.valid-text::after { font-family: "Font Awesome 5 Free"; font-weight: 900; content: "\f058"; margin-left: 8px; }
                .rank-status-message.invalid-text { color: #dc3545; }
                .rank-status-message.valid-text { color: #28a745; }
            </style>
            <?php
        }

        foreach ($action_tags['@RANK'] as $field_name => $params_array) {
            $json_params_string = $params_array[0] ?? null;
            if ($json_params_string) {
                // Attempt to decode the string as JSON.
                $params = json_decode($json_params_string, true);
                if (is_array($params)) {
                    // If it's an array, we are using the JSON object syntax.
                    // Now we can safely check for the 'fields' key.
                    if (isset($params['fields'])) {
                        $rank_vars = array_map('trim', explode(',', $params['fields']));
                        $min = $params['min'] ?? null;
                        $max = $params['max'] ?? null;
                        $showMessage = ($params['message'] ?? true) !== false;
                        $randomise = ($params['randomize'] ?? $params['randomise'] ?? false) === true;
                        // If randomize is true, shuffle the array of field names
                        if ($randomise) {
                            shuffle($rank_vars);
                        }
                        $this->render_rank_ui($field_name, $rank_vars, $min, $max, $showMessage);
                    }
                    // If it's an array but 'fields' is missing, we do nothing, as it's an invalid format.
                } else {
                    // If it's NOT an array, the string was not JSON. Treat it as a simple comma-separated list.
                    // First remove any double quotation marks that might cause the explode function to fail (unfortunately single quotes still fail)
                    $json_params_string = str_replace('"', '', $json_params_string);
                    $rank_vars = array_map('trim', explode(',', $json_params_string));
                    // Call the render function with default values for min, max, etc.
                    $this->render_rank_ui($field_name, $rank_vars);
                }
            }
        }
    }

    public function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1) {
        $this->initialise_ranking_interface($project_id, $record, $instrument);
    }
    
    private function render_rank_ui($descriptive_field, $rank_vars, $min = null, $max = null, $showMessage = true) {
        global $Proj;
        $min_attr = is_numeric($min) ? "data-original-min='{$min}'" : "";
        $max_attr = is_numeric($max) ? "data-original-max='{$max}'" : "";
        $rank_vars_json = json_encode($rank_vars);
        $all_fields_json = json_encode($rank_vars);
        $js_text = [
            "msg_none" => $this->tt('msg_none'),
            "msg_all" => $this->tt('msg_all'),
            "msg_exact" => $this->tt('msg_exact'),
            "msg_min_max" => $this->tt('msg_min_max'),
            "msg_min" => $this->tt('msg_min'),
            "msg_max" => $this->tt('msg_max'),
            "source_pane_header" => $this->tt('source_pane_header'),
            "rank_pane_header" => $this->tt('rank_pane_header')
        ];
        ?>

        <script>
        $(document).ready(function() {
            const descriptiveFieldTr = $('#<?php echo $descriptive_field; ?>-tr');
            const lang = <?= json_encode($js_text) ?>;

            // If the trigger field is not on this page, stop execution for this instance.
            if (descriptiveFieldTr.length === 0) {
                return;
            }

            const allPossibleRankVars = <?php echo $rank_vars_json; ?>;
            const allManagedFields = <?php echo $all_fields_json; ?>;
            const hidingClass = 'rank-field-hidden';

            allManagedFields.forEach(fieldName => {
                $('#' + fieldName + '-tr').addClass(hidingClass);
            });

            const rankUIHTML = `
                <div id="rank-ui-<?php echo $descriptive_field; ?>" class="rank-field-container choicevert" style="text-indent: 0px !important; margin-left: 0px !important; margin-right: 0px !important;">
                        <div class="rank-container" <?php echo $min_attr; ?> <?php echo $max_attr; ?>>
                            <div id="source-pane-<?php echo $descriptive_field; ?>" class="rank-box">
                                <h3><?php echo $this->tt('source_pane_header'); ?></h3>
                                <div id="source-items-<?php echo $descriptive_field; ?>" class="source-items-container"></div>
                            </div>
                            <div id="rank-pane-<?php echo $descriptive_field; ?>" class="rank-box">
                                <h3><?php echo $this->tt('rank_pane_header'); ?></h3>
                                <div id="rank-items-<?php echo $descriptive_field; ?>" class="rank-items-container"></div>
                            </div>
                            <?php if ($showMessage): ?>
                                <div class="rank-status-message" id="status-<?php echo $descriptive_field; ?>"></div>
                            <?php endif; ?>
                        </div>
                </div>
            `;
            // If the descriptiveFieldTR contains a div#rank-ui-<descriptive_field>, replace it with the rank UI.
            // Otherwise, Insert the rank UI into the descriptive field, after the div[data-kind='field-label']
            if (descriptiveFieldTr.find('#rank-ui-<?php echo $descriptive_field; ?>').length) {
                descriptiveFieldTr.find('#rank-ui-<?php echo $descriptive_field; ?>').replaceWith(rankUIHTML);
            } else {
                descriptiveFieldTr.find('div[data-kind="field-label"]').after(rankUIHTML);
            }

            const sourcePane = $('#source-pane-<?php echo $descriptive_field; ?>');
            const rankingPane = $('#rank-pane-<?php echo $descriptive_field; ?>');
            const sourceItemsContainer = $('#source-items-<?php echo $descriptive_field; ?>');
            const rankingItemsContainer = $('#rank-items-<?php echo $descriptive_field; ?>');
            const statusMessage = $('#status-<?php echo $descriptive_field; ?>');
            const mainContainer = rankingPane.closest('.rank-container');

            // Populate all tiles first, based on original fields
            allPossibleRankVars.forEach(fieldName => {
                const fieldTr = $('#' + fieldName + '-tr');
                if (fieldTr.length) {
                    const label = fieldTr.find('.labelrc div[data-mlm-type="label"]').text().trim();
                    const tile = $(`<div class='rank-item' data-var='${fieldName}'><span class="rank-badge"></span><span class="rank-label">${label}</span></div>`);
                    sourceItemsContainer.append(tile);
                }
            });

            // Check for saved data and arrange tiles
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
                    message = lang.msg_none;
                    sourcePane.hide();
                    rankingPane.hide();
                } else {
                    sourcePane.show();
                    rankingPane.show();
                    if (mustRankAll && visibleCount > 0) {
                        message = lang.msg_all;
                        effectiveMin = visibleCount;
                        effectiveMax = visibleCount;
                    } else {
                        if (originalMin !== null) effectiveMin = Math.min(parseInt(originalMin), visibleCount);
                        if (originalMax !== null) effectiveMax = Math.min(parseInt(originalMax), visibleCount);
                        if (effectiveMin !== null && effectiveMax !== null && effectiveMin === effectiveMax && effectiveMin > 0) {
                            message = lang.msg_exact.replace('{0}', effectiveMin);
                        } else if (effectiveMin !== null && effectiveMax !== null) {
                            message = lang.msg_min_max.replace('{0}', effectiveMin).replace('{1}', effectiveMax);
                        } else if (effectiveMin !== null) {
                            message = lang.msg_min.replace('{0}', effectiveMin);
                        } else if (effectiveMax !== null) {
                            message = lang.msg_max.replace('{0}', effectiveMax);
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

            // Call the population function once before the first validation run
            populateInitialRanks();
            updateWarningAndValidationRules();
        });
        </script>
        <?php
    }
}

