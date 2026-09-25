<?php

/**
 * -------------------------------------------------------------------------
 * MoreOptions plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 by the MoreOptions plugin team.
 * @copyright Copyright (C) 2022-2024 by More Options plugin team.
 * @copyright Copyright (C) 2022-2024 by Cloud Inventory plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsGLPI/moreoptions
 * @link      https://gitlab.teclib.com/glpi-network/cancelsend/
 * @link      https://gitlab.teclib.com/glpi-network/cloudinventory/
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Moreoptions;

use Group_User;
use CommonITILTask;
use Change;
use Change_Group;
use Change_Item;
use Change_User;
use ChangeTask;
use CommonDBTM;
use CommonITILActor;
use CommonITILObject;
use CommonITILValidation;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Moreoptions\Config;
use GlpiPlugin\Moreoptions\Escalation;
use Group_Item;
use Group_Problem;
use Group_Ticket;
use Item_Problem;
use Item_Ticket;
use ITILCategory;
use ITILSolution;
use Planning;
use Problem;
use Problem_User;
use ProblemTask;
use Session;
use Ticket;
use Ticket_User;
use TicketTask;
use User;

class Controller extends CommonDBTM
{
    public $dohistory = true;

    public static $rightname = 'config';

    private static bool $solution_check_done = false;

    public static function getTypeName($nb = 0): string
    {
        return __s("Controller", "moreoptions");
    }

    public static function getIcon(): string
    {
        return "ti ti-server-2";
    }

    public static function useConfig(CommonDBTM $item): void
    {
        if ($item->fields['type'] == CommonITILActor::OBSERVER) {
            return;
        }

        $moconfig = Config::getConfig();

        switch ($item) {
            case $item instanceof Ticket_User:
                if ($item->fields['type'] == CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_ticket'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, CommonITILActor::REQUESTER, 'take_requester_group_ticket', 'Ticket');
                    }
                } elseif ($item->fields['type'] == CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_ticket'] != 0) {
                        if (Config::isTechnicianGroupHandledByEscalade()) {
                            // Escalade handles the same feature with a global
                            // configuration: it takes precedence over ours, so we
                            // just skip our own processing.
                            return;
                        }

                        self::addGroupsForActorType($item, $moconfig, CommonITILActor::ASSIGN, 'take_technician_group_ticket', 'Ticket');
                    }
                }

                break;
            case $item instanceof Change_User:
                if ($item->fields['type'] == CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_change'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, CommonITILActor::REQUESTER, 'take_requester_group_change', 'Change');
                    }
                } elseif ($item->fields['type'] == CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_change'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, CommonITILActor::ASSIGN, 'take_technician_group_change', 'Change');
                    }
                }

                break;
            case $item instanceof Problem_User:
                if ($item->fields['type'] == CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_problem'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, CommonITILActor::REQUESTER, 'take_requester_group_problem', 'Problem');
                    }
                } elseif ($item->fields['type'] == CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_problem'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, CommonITILActor::ASSIGN, 'take_technician_group_problem', 'Problem');
                    }
                }

                break;
            default:
                return;
        }
    }

    public static function addItemGroups(CommonDBTM $item): void
    {
        $conf = Config::getConfig();

        // Mapping of item types to their configuration fields and group classes
        $itemMappings = [
            Item_Ticket::class => [
                'config_field' => 'take_item_group_ticket',
                'group_class' => Group_Ticket::class,
                'foreign_key' => 'tickets_id',
            ],
            Change_Item::class => [
                'config_field' => 'take_item_group_change',
                'group_class' => Change_Group::class,
                'foreign_key' => 'changes_id',
            ],
            Item_Problem::class => [
                'config_field' => 'take_item_group_problem',
                'group_class' => Group_Problem::class,
                'foreign_key' => 'problems_id',
            ],
        ];

        $itemClass = $item::class;

        // Check if the item is supported and the configuration is enabled
        if (!isset($itemMappings[$itemClass]) || $conf->fields[$itemMappings[$itemClass]['config_field']] != 1) {
            return;
        }

        $mapping = $itemMappings[$itemClass];

        // Get the groups associated with the item
        $gitems = new Group_Item();
        $groups = $gitems->find([
            'itemtype' => $item->fields['itemtype'],
            'items_id' => $item->fields['items_id'],
        ]);

        // Add each group to the ticket/change/problem
        foreach ($groups as $g) {
            $groupClass = $mapping['group_class'];
            $gitem = new $groupClass();

            $criteria = [
                'groups_id' => $g['groups_id'],
                $mapping['foreign_key'] => $item->fields[$mapping['foreign_key']],
                'type' => CommonITILActor::ASSIGN,
            ];

            if (!$gitem->getFromDBByCrit($criteria)) {
                $gitem->add($criteria);
            }
        }
    }

    /**
     * Add groups for the given actor type based on the configuration
     */
    private static function addGroupsForActorType(CommonDBTM $item, Config $moconfig, int $actorType, string $configField, string $itemType): void
    {
        // Determine the class to use
        switch ($itemType) {
            case 'Ticket':
                $object = new Ticket();
                $groupClass = Group_Ticket::class;
                $idField = 'tickets_id';
                break;
            case 'Change':
                $object = new Change();
                $groupClass = Change_Group::class;
                $idField = 'changes_id';
                break;
            case 'Problem':
                $object = new Problem();
                $groupClass = Group_Problem::class;
                $idField = 'problems_id';
                break;
            default:
                return;
        }

        $object->getFromDB($item->fields[$idField]);

        // Escalade reacts to every technician group assignment: it would keep only
        // the last group added and unassign the technicians. This flag asks it to
        // skip its own processing for the assignments we make here, which also means
        // no group cleanup, no escalation history entry and no automatic status
        // change on its side.
        $escalade_options = $groupClass === Group_Ticket::class
            ? ['_plugin_escalade_rules_only' => true]
            : [];

        $actors = $object->getActorsForType($actorType);
        foreach ($actors as $actor) {
            if (!is_array($actor) || !isset($actor['itemtype']) || $actor['itemtype'] !== 'User') {
                continue;
            }

            if ($moconfig->fields[$configField] == 1) {
                // Use only the main group of the user
                $user = new User();
                if (isset($actor['items_id'])) {
                    $user->getFromDB($actor['items_id']);
                }

                $t_group = new $groupClass();
                $criteria = [
                    'groups_id' => $user->fields['groups_id'],
                    $idField => $object->fields['id'],
                    'type' => $actorType,
                ];

                if (!$t_group->getFromDBByCrit($criteria)) {
                    $t_group->add($criteria + $escalade_options);
                }
            } else {
                // Use all groups of the user
                $users_groups = new Group_User();
                if (isset($actor['items_id'])) {
                    $u_groups = $users_groups->find([
                        'users_id' => $actor['items_id'],
                    ]);
                    foreach ($u_groups as $ug) {
                        if (!is_array($ug) || !isset($ug['groups_id'])) {
                            continue;
                        }

                        $t_group = new $groupClass();
                        $criteria = [
                            'groups_id' => $ug['groups_id'],
                            $idField => $object->fields['id'],
                            'type' => $actorType,
                        ];

                        if (!$t_group->getFromDBByCrit($criteria)) {
                            $t_group->add($criteria + $escalade_options);
                        }
                    }
                }
            }
        }
    }

    public static function beforeCloseITILObject(CommonDBTM $item): void
    {
        if (!is_array($item->input)) {
            return;
        }

        $closed = true;

        if ($item instanceof ITILSolution) {
            $itemtype = $item->input['itemtype'] ?? null;

            $parent_item = getItemForItemType($itemtype);

            if (!$parent_item || !$parent_item->getFromDB($item->input['items_id'])) {
                return;
            }

            $closed = self::requireFieldsToClose($parent_item, true);
            $closed = self::preventClosure($parent_item) && $closed;
            self::$solution_check_done = true;
        } elseif (
            $item instanceof CommonITILObject
            && isset($item->input['status'])
            && ($item->input['status'] == CommonITILObject::CLOSED || $item->input['status'] == CommonITILObject::SOLVED)
        ) {
            // Consume the one-shot bypass as soon as it is read: it is only meant to let
            // through the single status update that ITILSolution::post_addItem() performs
            // on its parent right after the solution itself was validated and saved. Leaving
            // it at `true` would silently skip this check for every later status change too.
            $bypass = self::$solution_check_done;
            self::$solution_check_done = false;

            if ($bypass && $item->input['status'] == CommonITILObject::SOLVED) {
                return;
            }

            $closed = self::requireFieldsToClose($item);
            $closed = self::preventClosure($item) && $closed;
        }

        if (!$closed) {
            $item->input = false;
        }
    }

    public static function preventClosure(CommonDBTM $item): bool
    {
        $conf = Config::getConfig();

        $tasks = [];

        if ($item instanceof Ticket && $conf->fields['prevent_closure_ticket'] == 1) {
            $task = new TicketTask();
            $tasks = $task->find([
                'tickets_id' => $item->fields['id'],
            ]);
        }

        if ($item instanceof Change && $conf->fields['prevent_closure_change'] == 1) {
            $task = new ChangeTask();
            $tasks = $task->find([
                'changes_id' => $item->fields['id'],
            ]);
        }

        if ($item instanceof Problem && $conf->fields['prevent_closure_problem'] == 1) {
            $task = new ProblemTask();
            $tasks = $task->find([
                'problems_id' => $item->fields['id'],
            ]);
        }

        foreach ($tasks as $t) {
            if (is_array($t) && isset($t['state']) && $t['state'] == Planning::TODO) {
                Session::addMessageAfterRedirect(__s('The ticket you wish to close has tasks that need to be completed.', 'moreoptions'), false, ERROR);
                $item->input = false;
                return false;
            }
        }

        return true;
    }

    /**
     * Determine which fields configured as required to close are missing on the given item.
     *
     * @return string[]|null Labels of the missing fields, an empty array if none are missing,
     *                        or null if the check could not be performed (invalid actor class).
     */
    private static function getMissingCloseFields(CommonDBTM $item, bool $is_solution): ?array
    {
        $conf = Config::getConfig();

        $missing = [];
        $itemtype = $item::class;

        $data = array_merge($item->fields, is_array($item->input) ? $item->input : []);

        // Determine the configuration suffix and actor classes based on item type
        $configSuffix = '_' . strtolower($itemtype);
        $userClass = $item->userlinkclass ?? '';
        $groupClass = $item->grouplinkclass ?? '';
        $itemIdField = $item->getForeignKeyField();

        // Check for required technician
        if ($conf->fields['require_technician_to_close' . $configSuffix] == 1) {
            if ($is_solution && $itemtype === 'Ticket' && !empty($_SESSION['glpiset_solution_tech'])) {
                // GLPI will auto-assign the solution author as technician in post_addItem
            } elseif (is_a($userClass, CommonDBTM::class, true)) {
                $tech = new $userClass();
                $techs = $tech->find([
                    $itemIdField => $data['id'],
                    'type'       => CommonITILActor::ASSIGN,
                ]);
                if (count($techs) === 0) {
                    $missing[] = __s('Technician');
                }
            } else {
                // If the user class is not valid, skip this check
                return null;
            }
        }

        // Check for required technician group
        if ($conf->fields['require_technicians_group_to_close' . $configSuffix] == 1) {
            if (is_a($groupClass, CommonDBTM::class, true)) {
                $group = new $groupClass();
            } else {
                // If the group class is not valid, skip this check
                return null;
            }

            $groups = $group->find([
                $itemIdField => $data['id'],
                'type'       => CommonITILActor::ASSIGN,
            ]);
            if (count($groups) === 0) {
                $missing[] = __s('Technician group');
            }
        }

        // Check for required category
        if ($conf->fields['require_category_to_close' . $configSuffix] == 1) {
            if ((!isset($data['itilcategories_id']) || empty($data['itilcategories_id']))) {
                $missing[] = __s('Category');
            }
        }

        // Check for required location
        if ($conf->fields['require_location_to_close' . $configSuffix] == 1) {
            if ((!isset($data['locations_id']) || empty($data['locations_id']))) {
                $missing[] = __s('Location');
            }
        }

        // Check if solution exists before resolving/closing.
        if (
            !$is_solution
            && $conf->fields['require_solution_to_close' . $configSuffix] == 1
            && isset($data['status'])
            && in_array($data['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)
        ) {
            $solution = new ITILSolution();
            $solutions = $solution->find([
                'itemtype' => $itemtype,
                'items_id' => $data['id'],
                'NOT' => [
                    'status' => CommonITILValidation::REFUSED,
                ],
            ]);
            if (count($solutions) === 0) {
                $missing[] = __s('Solution');
            }
        }

        return $missing;
    }

    public static function requireFieldsToClose(CommonDBTM $item, bool $is_solution = false): bool
    {
        $missing = self::getMissingCloseFields($item, $is_solution);

        if ($missing === null) {
            return false;
        }

        if ($missing !== []) {
            $itemTypeLabel = $item->getTypeName();

            $message = sprintf(__s('To close this %s, you must fill in the following fields:', 'moreoptions'), $itemTypeLabel) . '<br>';
            foreach ($missing as $field) {
                $message .= '- ' . $field . '<br>';
            }

            Session::addMessageAfterRedirect($message, false, ERROR);
            return false;
        }

        return true;
    }

    /**
     * Hooked on {@link \Glpi\Plugin\Hooks::TIMELINE_ACTIONS}, which takes a single callback per
     * plugin: renders everything MoreOptions adds to the ticket/change/problem timeline footer.
     *
     * @param array<string, mixed> $params
     */
    public static function showTimelineActions(array $params): void
    {
        self::showSolutionRequirementsWarning($params);
        Escalation::showEscalateButton($params);
        Escalation::showTimelineScripts($params);
    }

    /**
     * Called from {@see self::showTimelineActions()}. Renders, into the ticket/change/
     * problem timeline footer, a script that mutes the "Add a solution" action, adds a lock icon
     * to it, and attaches a popover listing the missing fields, as soon as one of the fields
     * required to close the item (technician, group, category, location...) is missing.
     *
     * This is purely client-side: it does not replace the server-side block already performed by
     * {@see self::beforeCloseITILObject()} on actual submission, it just gives the user a visual
     * hint before they even open the solution form.
     *
     * @param array<string, mixed> $params
     */
    public static function showSolutionRequirementsWarning(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!($item instanceof CommonITILObject) || !$item->canSolve()) {
            return;
        }

        $missing = self::getMissingCloseFields($item, true);
        if (empty($missing)) {
            // Nothing configured as required, or everything is already filled: let the
            // normal "Add a solution" action be usable.
            return;
        }

        $count = count($missing);
        $header = sprintf(
            _n(
                '%1$d required field is missing, so this %2$s can\'t be solved yet.',
                '%1$d required fields are missing, so this %2$s can\'t be solved yet.',
                $count,
                'moreoptions',
            ),
            $count,
            $item->getTypeName(1),
        );

        TemplateRenderer::getInstance()->display('@moreoptions/timeline_solution_warning.html.twig', [
            'marker_id'      => 'moreoptions-solution-warning-' . $item->getType() . '-' . $item->getID(),
            'header'         => $header,
            'missing_fields' => $missing,
        ]);
    }

    public static function checkTaskRequirements(CommonDBTM $item): CommonDBTM
    {
        $conf = Config::getConfig();

        $message = '';
        if ($conf->fields['mandatory_task_category'] == 1) {
            if (empty($item->input['taskcategories_id'])) {
                $message .= '- ' . __s('Category') . '<br>';
            }
        }

        if ($conf->fields['mandatory_task_duration'] == 1) {
            if (empty($item->input['actiontime'])) {
                $message .= '- ' . __s('Duration') . '<br>';
            }
        }

        if ($conf->fields['mandatory_task_user'] == 1) {
            if (empty($item->input['users_id_tech'])) {
                $message .= '- ' . __s('User') . '<br>';
            }
        }

        if ($conf->fields['mandatory_task_group'] == 1) {
            if (empty($item->input['groups_id_tech'])) {
                $message .= '- ' . __s('Group') . '<br>';
            }
        }

        if ($message !== '' && $message !== '0') {
            $message = __s('To create this task, you must fill in the following fields:', 'moreoptions') . '<br>' . $message;
            Session::addMessageAfterRedirect($message, false, ERROR);
            $item->input = false;
        }

        return $item;
    }

    /**
     * Hooked on {@link \Glpi\Plugin\Hooks::POST_ITEM_FORM}. Renders, into the task creation/edit
     * form, a script that marks the fields configured as mandatory in moreoptions (category,
     * duration, technician, technician group) with the usual red "required" marker and blocks
     * client-side submission of the form until they are filled.
     *
     * The server-side block already performed by {@see self::checkTaskRequirements()} on actual
     * submission (PRE_ITEM_ADD) is kept as-is; this only prevents the user from submitting an
     * incomplete task in the first place.
     *
     * @param array<string, mixed> $params
     */
    public static function markMandatoryTaskFields(array $params): void
    {
        $item = $params['item'] ?? null;
        if (
            !($item instanceof TicketTask)
            && !($item instanceof ChangeTask)
            && !($item instanceof ProblemTask)
        ) {
            return;
        }

        $conf = Config::getConfig();

        $labels = [];
        if ($conf->fields['mandatory_task_category'] == 1) {
            $labels['taskcategories_id'] = __('Category');
        }

        if ($conf->fields['mandatory_task_duration'] == 1) {
            $labels['actiontime'] = __('Duration');
        }

        if ($conf->fields['mandatory_task_user'] == 1) {
            $labels['users_id_tech'] = __('User');
        }

        if ($conf->fields['mandatory_task_group'] == 1) {
            $labels['groups_id_tech'] = __('Group');
        }

        if (empty($labels)) {
            return;
        }

        TemplateRenderer::getInstance()->display('@moreoptions/timeline_task_mandatory_fields.html.twig', [
            // Unique per-call anchor: lets the injected script find its own <form> reliably.
            'marker_id'     => 'moreoptions-task-mandatory-' . bin2hex(random_bytes(6)),
            'field_labels'  => $labels,
            'error_message' => __('To create this task, you must fill in the following fields:', 'moreoptions'),
        ]);
    }

    public static function updateItemActors(CommonITILObject $item): CommonITILObject
    {
        $conf = Config::getConfig();

        switch ($item::class) {
            case 'Ticket':
                $assign_tech_manager = $conf->fields['assign_technical_manager_when_changing_category_ticket'];
                $assign_tech_group = $conf->fields['assign_technical_group_when_changing_category_ticket'];
                break;
            case 'Change':
                $assign_tech_manager = $conf->fields['assign_technical_manager_when_changing_category_change'];
                $assign_tech_group = $conf->fields['assign_technical_group_when_changing_category_change'];
                break;
            case 'Problem':
                $assign_tech_manager = $conf->fields['assign_technical_manager_when_changing_category_problem'];
                $assign_tech_group = $conf->fields['assign_technical_group_when_changing_category_problem'];
                break;
            default:
                return $item;
        }

        if ($assign_tech_manager || $assign_tech_group) {

            $itemIdField = strtolower($item::class) . 's_id';
            $category = new ITILCategory();
            $fund = $category->getFromDB($item->fields['itilcategories_id']);
            if ($fund) {
                if ($assign_tech_manager) {
                    if (is_a($item->userlinkclass, CommonDBTM::class, true)) {
                        $user_link = new $item->userlinkclass();
                        $criteria = [
                            'users_id' => $category->fields['users_id'],
                            'type'     => CommonITILActor::ASSIGN,
                            $itemIdField => $item->fields['id'],
                        ];
                        if (!$user_link->getFromDBByCrit($criteria)) {
                            $user_link->add($criteria);
                        }
                    }
                }

                if ($assign_tech_group) {
                    if (is_a($item->grouplinkclass, CommonDBTM::class, true)) {
                        $group_link = new $item->grouplinkclass();
                        $criteria = [
                            'groups_id' => $category->fields['groups_id'],
                            'type'     => CommonITILActor::ASSIGN,
                            $itemIdField => $item->fields['id'],
                        ];
                        if (!$group_link->getFromDBByCrit($criteria)) {
                            $group_link->add($criteria);
                        }
                    }
                }
            }
        }

        return $item;
    }

    /**
     * Assign technician from task to parent ITIL object
     * When a task is created with a technician assigned, this method will
     * automatically assign that technician to the parent ticket/change/problem
     *
     * @param CommonITILTask $item The task item (TicketTask, ChangeTask, or ProblemTask)
     */
    public static function assignTechnicianFromTask(CommonITILTask $item): void
    {
        $conf = Config::getConfig();

        // Check if a technician is assigned to the task
        if (empty($item->fields['users_id_tech'])) {
            return;
        }

        $users_id_tech = $item->fields['users_id_tech'];

        // Determine the parent ITIL object and user link class based on task type
        switch ($item::class) {
            case TicketTask::class:
                if ($conf->fields['assign_technician_from_task_ticket'] != 1 || empty($item->fields['tickets_id'])) {
                    return;
                }

                $itilObject = new Ticket();
                $userLinkClass = Ticket_User::class;
                $itilIdField = 'tickets_id';
                $itilId = $item->fields['tickets_id'];
                break;

            case ChangeTask::class:
                if ($conf->fields['assign_technician_from_task_change'] != 1 || empty($item->fields['changes_id'])) {
                    return;
                }

                $itilObject = new Change();
                $userLinkClass = Change_User::class;
                $itilIdField = 'changes_id';
                $itilId = $item->fields['changes_id'];
                break;

            case ProblemTask::class:
                if ($conf->fields['assign_technician_from_task_problem'] != 1 || empty($item->fields['problems_id'])) {
                    return;
                }

                $itilObject = new Problem();
                $userLinkClass = Problem_User::class;
                $itilIdField = 'problems_id';
                $itilId = $item->fields['problems_id'];
                break;

            default:
                return;
        }

        // Get the parent ITIL object
        if (!$itilObject->getFromDB($itilId)) {
            return;
        }

        // Check if the technician is already assigned to the parent ITIL object
        $userLink = new $userLinkClass();
        $criteria = [
            'users_id' => $users_id_tech,
            'type' => CommonITILActor::ASSIGN,
            $itilIdField => $itilId,
        ];

        // If the technician is not already assigned, add them
        if (!$userLink->getFromDBByCrit($criteria)) {
            $userLink->add($criteria);
        }
    }
}
