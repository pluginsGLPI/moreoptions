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

use Change;
use Change_Group;
use Change_Item;
use Change_User;
use ChangeTask;
use CommonDBTM;
use CommonITILActor;
use CommonITILObject;
use CommonITILValidation;
use Glpi\Form\Category;
use GlpiPlugin\Moreoptions\Config;
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
        if ($item->fields['type'] == \CommonITILActor::OBSERVER) {
            return;
        }
        $moconfig = Config::getEffectiveConfig();

        if ($moconfig->fields['is_active'] != 1) {
            return;
        }

        switch ($item) {
            case $item instanceof Ticket_User:
                if ($item->fields['type'] == \CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_ticket'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::REQUESTER, 'take_requester_group_ticket', 'Ticket');
                    }
                } elseif ($item->fields['type'] == \CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_ticket'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::ASSIGN, 'take_technician_group_ticket', 'Ticket');
                    }
                }
                break;
            case $item instanceof Change_User:
                if ($item->fields['type'] == \CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_change'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::REQUESTER, 'take_requester_group_change', 'Change');
                    }
                } elseif ($item->fields['type'] == \CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_change'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::ASSIGN, 'take_technician_group_change', 'Change');
                    }
                }
                break;
            case $item instanceof Problem_User:
                if ($item->fields['type'] == \CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_problem'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::REQUESTER, 'take_requester_group_problem', 'Problem');
                    }
                } elseif ($item->fields['type'] == \CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_problem'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::ASSIGN, 'take_technician_group_problem', 'Problem');
                    }
                }
                break;
            default:
                return;
        }
    }

    public static function addItemGroups(CommonDBTM $item): void
    {
        $conf = Config::getEffectiveConfig();
        if ($conf->fields['is_active'] != 1) {
            return;
        }

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

        $itemClass = get_class($item);

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
                ];

                // Add type for assigned technicians
                if ($actorType == \CommonITILActor::ASSIGN) {
                    $criteria['type'] = \CommonITILActor::ASSIGN;
                }

                if (!$t_group->getFromDBByCrit($criteria)) {
                    if ($actorType == \CommonITILActor::ASSIGN) {
                        $criteria['type'] = \CommonITILActor::ASSIGN;
                    }

                    $t_group->add($criteria);
                }
            } else {
                // USe all groups of the user
                $users_groups = new \Group_User();
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
                        ];

                        // Add type for assigned technicians
                        if ($actorType == \CommonITILActor::ASSIGN) {
                            $criteria['type'] = \CommonITILActor::ASSIGN;
                        }

                        if (!$t_group->getFromDBByCrit($criteria)) {
                            $groupData = [
                                'groups_id' => $ug['groups_id'],
                                $idField => $object->fields['id'],
                            ];

                            if ($actorType == \CommonITILActor::ASSIGN) {
                                $groupData['type'] = \CommonITILActor::ASSIGN;
                            }

                            $t_group->add($groupData);
                        }
                    }
                }
            }
        }
    }

    public static function beforeCloseITILObject(CommonITILObject $item): void
    {
        if (!is_array($item->input)) {
            return;
        }

        if (
            (isset($item->input['status']) && ($item->input['status'] == CommonITILObject::CLOSED || $item->input['status'] == CommonITILObject::SOLVED))
            || $item->fields['status'] == CommonITILObject::CLOSED
            || $item->fields['status'] == CommonITILObject::SOLVED
        ) {
            self::requireFieldsToClose($item);
            self::preventClosure($item);
        }
    }

    public static function preventClosure(CommonDBTM $item): void
    {
        $conf = Config::getEffectiveConfig();
        if ($conf->fields['is_active'] != 1) {
            return;
        }

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
                return;
            }
        }
    }

    public static function requireFieldsToClose(CommonITILObject $item): void
    {
        $conf = Config::getEffectiveConfig();
        if ($conf->fields['is_active'] != 1) {
            return;
        }

        $message = '';
        $itemtype = get_class($item);

        // Determine the configuration suffix and actor classes based on item type
        $configSuffix = '_' . strtolower($itemtype);
        $userClass = $item->userlinkclass;
        $groupClass = $item->grouplinkclass;
        $itemIdField = $item->getForeignKeyField();

        // Check for required technician
        if ($conf->fields['require_technician_to_close' . $configSuffix] == 1) {
            if (is_a($userClass, CommonDBTM::class, true)) {
                $tech = new $userClass();
            } else {
                // If the user class is not valid, skip this check
                return;
            }
            $techs = $tech->find([
                $itemIdField => $item->fields['id'],
                'type'       => CommonITILActor::ASSIGN,
            ]);
            if (count($techs) == 0) {
                $message .= '- ' . __s('Technician') . '<br>';
            }
        }

        // Check for required technician group
        if ($conf->fields['require_technicians_group_to_close' . $configSuffix] == 1) {
            if (is_a($groupClass, CommonDBTM::class, true)) {
                $group = new $groupClass();
            } else {
                // If the group class is not valid, skip this check
                return;
            }
            $groups = $group->find([
                $itemIdField => $item->fields['id'],
                'type'       => CommonITILActor::ASSIGN,
            ]);
            if (count($groups) == 0) {
                $message .= '- ' . __s('Technician group') . '<br>';
            }
        }

        // Check for required category
        if ($conf->fields['require_category_to_close' . $configSuffix] == 1) {
            if ((!isset($item->input['itilcategories_id']) || empty($item->input['itilcategories_id']))) {
                $message .= '- ' . __s('Category') . '<br>';
            }
        }

        // Check for required location
        if ($conf->fields['require_location_to_close' . $configSuffix] == 1) {
            if ((!isset($item->input['locations_id']) || empty($item->input['locations_id']))) {
                $message .= '- ' . __s('Location') . '<br>';
            }
        }

        // Check if solution exists before closing
        if ($conf->fields['require_solution_to_close' . $configSuffix] == 1
            && is_array($item->input)
            && isset($item->input['status'])
            && $item->input['status'] == CommonITILObject::CLOSED) {
            $solution = new ITILSolution();
            $solutions = $solution->find([
                'itemtype' => $itemtype,
                'items_id' => $item->fields['id'],
                'NOT' => [
                    'status' => CommonITILValidation::REFUSED,
                ],
            ]);
            if (count($solutions) == 0) {
                $message .= '- ' . __s('Solution') . '<br>';
            }
        }

        if (!empty($message)) {
            $itemTypeLabel = $item->getTypeName();

            $message = sprintf(__s('To close this %s, you must fill in the following fields:', 'moreoptions'), $itemTypeLabel) . '<br>' . $message;
            Session::addMessageAfterRedirect($message, false, ERROR);
            $item->input = false;
            return;
        }
    }

    public static function checkTaskRequirements(CommonDBTM $item): CommonDBTM
    {
        $conf = Config::getEffectiveConfig();
        if ($conf->fields['is_active'] != 1) {
            return $item;
        }

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

        if (!empty($message)) {
            $message = __s('To create this task, you must fill in the following fields:', 'moreoptions') . '<br>' . $message;
            Session::addMessageAfterRedirect($message, false, ERROR);
            $item->input = false;
        }

        return $item;
    }

    public static function updateItemActors(CommonITILObject $item): CommonITILObject
    {
        $conf = Config::getEffectiveConfig();
        if ($conf->fields['is_active'] != 1) {
            return $item;
        }

        switch (get_class($item)) {
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

            $itemIdField = strtolower(get_class($item)) . 's_id';
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
}
