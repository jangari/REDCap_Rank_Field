<?php
namespace INTERSECT\RankField;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use INTERSECT\RankField\ActionTagHelper;

class RankField extends AbstractExternalModule {

    public function __construct() {
        parent::__construct();
    }

    // This function will be called by both hooks to avoid duplicating code.
    private function initialise_ranking_interface($project_id, $record, $instrument) {
        if (!class_exists('RankItUp\ActionTagHelper')) {
            include_once('classes/ActionTagHelper.php');
        }

        $action_tags = ActionTagHelper::getActionTags('@RANK', null, $instrument);

        if (empty($action_tags['@RANK'])) {
            return;
        }

        foreach ($action_tags['@RANK'] as $field_name => $params_array) {
            $json_params_string = $params_array[0] ?? null;
            if ($json_params_string) {
                // Decode the JSON string into an associative array
                $params = json_decode($json_params_string, true);

                // Check if the 'fields' key exists
                if (isset($params['fields'])) {
                    $rank_vars = array_map('trim', explode(',', $params['fields']));

                    // Get min/max values, defaulting to null if not set
                    $min = $params['min'] ?? null;
                    $max = $params['max'] ?? null;

                    // Pass all values to the render function
                    $this->render_rank_ui($field_name, $rank_vars, $min, $max);
                }
            }
        }
    }

    // Hook for data entry forms
    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance = 1) {
        echo "<script>console.log('Data entry form hook called for project $project_id, record $record, instrument $instrument, event $event_id, group $group_id, repeat instance $repeat_instance');</script>";
        $this->initialise_ranking_interface($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance);
    }

    // Hook for survey pages
    public function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1) {
        echo "<script>console.log('Survey page hook called for project $project_id, record $record, instrument $instrument, event $event_id, group $group_id, repeat instance $repeat_instance');</script>";
        $this->initialise_ranking_interface($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance);
    }
    
    // This function generates the actual HTML, CSS, and JS to be injected
    private function render_rank_ui($descriptive_field, $rank_vars, $min = null, $max = null) {
        global $Proj;

        $fields_to_hide = array_merge([$descriptive_field], $rank_vars);

        $min_attr = is_numeric($min) ? "data-original-min='{$min}'" : "";
        $max_attr = is_numeric($max) ? "data-original-max='{$max}'" : "";

        $rank_vars_json = json_encode($rank_vars);
        $all_fields_json = json_encode($fields_to_hide);

?>
    <script src="https://cdn.tailwindcss.com/"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <style>
        .rank-field-hidden-field { display: none !important; }
        .rank-container { display: flex; flex-wrap: wrap; gap: 20px; font-family: sans-serif; }
        .rank-box { flex: 1; min-width: 250px; padding: 15px; border: 2px dashed #ccc; border-radius: 8px; background-color: #f9f9f9; min-height: 150px; transition: border-color 0.3s, opacity 0.3s; }
        .rank-box h3 { margin-top: 0; margin-bottom: 15px; color: #555; text-align: center; }
        .rank-item { padding: 10px 15px; background-color: #fff; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 8px; cursor: grab; user-select: none; }
        .rank-box.invalid { border-color: #dc3545; }
        .rank-box.valid { border-color: #28a745; }
        .rank-status-message { color: #666; font-size: 0.9em; margin-top: 10px; height: 1em; text-align: center; transition: color 0.3s; width: 100%; }
        .rank-status-message.invalid-text { color: #dc3545; }
        .rank-status-message.invalid-text::after {
            font-family: "Font Awesome 5 Free"; /* Use the Font Awesome font */
            font-weight: 900; /* Required for solid (fas) icons */
            content: "\f071"; /* The Unicode for the exclamation-triangle icon */
            margin-left: 8px; /* Add some space between the icon and text */
        }
        .rank-status-message.valid-text { color: #28a745; }
        .rank-status-message.valid-text::after {
            font-family: "Font Awesome 5 Free";
            font-weight: 900;
            content: "\f058"; /* The Unicode for the check-circle icon */
            margin-left: 8px;
        }
    </style>

        <script>
        $(document).ready(function() {
            // --- SETUP ---
            const descriptiveFieldTr = $('#<?php echo $descriptive_field; ?>-tr');
            const allPossibleRankVars = <?php echo $rank_vars_json; ?>;
            const allManagedFields = <?php echo $all_fields_json; ?>;
            const hidingClass = 'rank-field-hidden-field';

            allManagedFields.forEach(fieldName => {
            $('#' + fieldName + '-tr').addClass(hidingClass);
            });

            const rankUIHTML = `
                <tr id="rank-ui-<?php echo $descriptive_field; ?>" class="rank-field-container">
                <td colspan="2">
                    <div class="rank-container" <?php echo $min_attr; ?> <?php echo $max_attr; ?>>
                        <div id="source-pane-<?php echo $descriptive_field; ?>" class="rank-box">
                            <h3>Available Options</h3>
                        </div>
                        <div id="ranking-pane-<?php echo $descriptive_field; ?>" class="rank-box">
                            <h3>Your Ranking</h3>
                        </div>
                        <div class="rank-status-message" id="status-<?php echo $descriptive_field; ?>"></div>
                    </div>
                </td>
            </tr>
        `;
            descriptiveFieldTr.after(rankUIHTML);

            const sourcePane = $('#source-pane-<?php echo $descriptive_field; ?>');
            const rankingPane = $('#ranking-pane-<?php echo $descriptive_field; ?>');
            const statusMessage = $('#status-<?php echo $descriptive_field; ?>');
            const mainContainer = rankingPane.closest('.rank-container');

            new Sortable(sourcePane[0], { group: 'ranking-group-<?php echo $descriptive_field; ?>', animation: 150, onSort: updateWarningAndValidationRules });
            new Sortable(rankingPane[0], { group: 'ranking-group-<?php echo $descriptive_field; ?>', animation: 150, onSort: updateWarningAndValidationRules });

            // --- CORE LOGIC ---

            function updateWarningAndValidationRules() {
                let visibleFields = [];
                allPossibleRankVars.forEach(fieldName => {
                const fieldTr = $('#' + fieldName + '-tr');

                // --- FIX: A more robust way to check for REDCap's branching logic visibility ---
                // 1. Temporarily remove our hiding class to isolate REDCap's behavior.
                fieldTr.removeClass(hidingClass);
                // 2. Use jQuery's :visible selector to see if REDCap is showing the element.
                const isVisibleByRedcap = fieldTr.is(':visible');

                if (isVisibleByRedcap) {
                    visibleFields.push(fieldName);
                    // 3. Immediately add our hiding class back.
                    fieldTr.addClass(hidingClass);
                } else {
                    rankingPane.find(`.rank-item[data-var="${fieldName}"]`).remove();
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
                    message = 'There are no items to rank';
                    sourcePane.hide();
                    rankingPane.hide();
                } else {
                    sourcePane.show();
                    rankingPane.show();
                    if (mustRankAll && visibleCount > 0) {
                        message = 'Please rank all items';
                        effectiveMin = visibleCount;
                        effectiveMax = visibleCount;
                    } else {
                        sourcePane.show();
                        rankingPane.show();
                        if (originalMin !== null) effectiveMin = Math.min(parseInt(originalMin), visibleCount);
                        if (originalMax !== null) effectiveMax = Math.min(parseInt(originalMax), visibleCount);

                        if (effectiveMin !== null && effectiveMax !== null && effectiveMin === effectiveMax && effectiveMin > 0) {
                            message = `Please rank exactly ${effectiveMin} items`;
                        } else if (effectiveMin !== null && effectiveMax !== null) {
                            message = `Please rank at least ${effectiveMin} and at most ${effectiveMax} items`;
                        } else if (effectiveMin !== null) {
                            message = `Please rank at least ${effectiveMin} items`;
                        } else if (effectiveMax !== null) {
                            message = `Please rank at most ${effectiveMax} items`;
                        }
                    }
                }

                rankingPane.data('effective-min', effectiveMin).data('effective-max', effectiveMax);
                statusMessage.text(message);

                let availableOptionsHTML = '';
                visibleFields.forEach(fieldName => {
                if (rankingPane.find(`.rank-item[data-var="${fieldName}"]`).length === 0) {
                    const label = $('#' + fieldName + '-tr').find('.labelrc').text().trim();
                    availableOptionsHTML += `<div class='rank-item' data-var='${fieldName}'>${label}</div>`;
                }
                });
                sourcePane.html(availableOptionsHTML);

                updateRanksAndValidation();
            }

            function updateRanksAndValidation() {
                const rankedItems = rankingPane.find('.rank-item');
                const rankedCount = rankedItems.length;
                const effectiveMin = rankingPane.data('effective-min');
                const effectiveMax = rankingPane.data('effective-max');

                allPossibleRankVars.forEach(varName => { $('input[name="' + varName + '"]').val(''); });
                rankedItems.each(function(index) {
                    $(this).closest('form').find('input[name="' + $(this).data('var') + '"]').val(index + 1).trigger('change');
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
                statusMessage.toggleClass('invalid-text', !isValid).toggleClass('valid-text', isValid && statusMessage.text() !== '');
            }

            const observer = new MutationObserver(updateWarningAndValidationRules);
            allManagedFields.forEach(fieldName => {
            const fieldTr = document.getElementById(fieldName + '-tr');
            if(fieldTr) {
                observer.observe(fieldTr, { attributes: true, attributeFilter: ['style'] });
            }
            });

            updateWarningAndValidationRules();
        });
    </script>
<?php
    }
}
