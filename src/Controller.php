<?php

/**
 * -------------------------------------------------------------------------
 * More Options plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of More Options.
 *
 * More Options is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * More Options is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with More Options. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2022-2024 by More Options plugin team.
 * @copyright Copyright (C) 2022-2024 by Cloud Inventory plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://gitlab.teclib.com/glpi-network/cancelsend/
 * @link      https://gitlab.teclib.com/glpi-network/cloudinventory/
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Moreoptions;

use Change;
use Change_Group;
use Change_User;
use ChangeTask;
use CommonDBTM;
use CommonITILActor;
use CommonITILObject;
use CommonITILValidation;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Moreoptions\Config;
use Group_Change;
use Group_Problem;
use Group_Ticket;
use Html;
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
    public static function getTypeName($nb = 0)
    {
        return __("Controller", "moreoptions");
    }
    public static function getIcon()
    {
        return "ti ti-server-2";
    }

    public static function useConfig($item)
    {
        if ($item->fields['type'] == \CommonITILActor::OBSERVER) {
            return;
        }
        $moconfig = new Config();
        $moconfig->getFromDBByCrit([
            'entities_id' => Session::getActiveEntity(),
        ]);

        if ($moconfig->fields['is_active'] != 1) {
            return;
        }

        switch ($item) {
            case $item instanceof Ticket_User:
                if ($moconfig->fields['take_item_group_ticket'] == 1) {
                    $test = "OK";
                }
                if ($item->fields['type'] == \CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_ticket'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::REQUESTER, 'take_requester_group_ticket', 'Ticket');
                    }
                } else if ($item->fields['type'] == \CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_ticket'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::ASSIGN, 'take_technician_group_ticket', 'Ticket');
                    }
                }
                break;
            case $item instanceof Change_User:
                if ($moconfig->fields['take_item_group_change'] == 1) {
                    $test = "OK";
                }
                if ($item->fields['type'] == \CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_change'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::REQUESTER, 'take_requester_group_change', 'Change');
                    }
                } else if ($item->fields['type'] == \CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_change'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::ASSIGN, 'take_technician_group_change', 'Change');
                    }
                }
                break;
            case $item instanceof Problem_User:
                if ($moconfig->fields['take_item_group_problem'] == 1) {
                    $test = "OK";
                }
                if ($item->fields['type'] == \CommonITILActor::REQUESTER) {
                    if ($moconfig->fields['take_requester_group_problem'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::REQUESTER, 'take_requester_group_problem', 'Problem');
                    }
                } else if ($item->fields['type'] == \CommonITILActor::ASSIGN) {
                    if ($moconfig->fields['take_technician_group_problem'] != 0) {
                        self::addGroupsForActorType($item, $moconfig, \CommonITILActor::ASSIGN, 'take_technician_group_problem', 'Problem');
                    }
                }
                break;
            default:
                return;
        }
    }

    /**
     * Ajoute les groupes d'un type d'acteur donné au ticket/change/problem
     */
    private static function addGroupsForActorType($item, $moconfig, $actorType, $configField, $itemType)
    {
        // Déterminer le type d'objet et les classes appropriées
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
            // Ne garder que les acteurs de type User
            if ($actor['itemtype'] !== 'User') {
                continue;
            }

            if ($moconfig->fields[$configField] == 1) {
                // Utiliser le groupe principal de l'utilisateur
                $user = new User();
                $user->getFromDB($actor['items_id']);
                $t_group = new $groupClass();
                $criteria = [
                    'groups_id' => $user->fields['groups_id'],
                    $idField => $object->fields['id']
                ];

                // Ajouter le type pour les techniciens assignés
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
                // Utiliser tous les groupes de l'utilisateur
                $users_groups = new \Group_User();
                $u_groups = $users_groups->find([
                    'users_id' => $actor['items_id'],
                ]);
                foreach ($u_groups as $ug) {
                    $t_group = new $groupClass();
                    $criteria = [
                        'groups_id' => $ug['groups_id'],
                        $idField => $object->fields['id']
                    ];

                    // Ajouter le type pour les techniciens assignés
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

    public static function beforeCloseTicket($item)
    {
        if (
            $item->input['status'] == CommonITILObject::CLOSED
            || $item->input['status'] == CommonITILObject::SOLVED
            || $item->fields['status'] == CommonITILObject::CLOSED
            || $item->fields['status'] == CommonITILObject::SOLVED
        ) {
            self::requireFieldsToClose($item);
            self::preventClosure($item);
        }
    }

    public static function preventClosure($item)
    {
        $conf = Config::getCurrentConfig();
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
            if ($t['state'] == Planning::TODO) {
                Session::addMessageAfterRedirect(__('The ticket you wish to close has tasks that need to be completed.', 'moreoptions'), false, ERROR);
                return $item->input = false;
            }
        }
    }

    public static function requireFieldsToClose($item)
    {
        $conf = Config::getCurrentConfig();
        if ($conf->fields['is_active'] != 1) {
            return;
        }

        $message = '';
        $itemtype = get_class($item);
        if ($conf->fields['require_technician_to_close_ticket'] == 1) {
            $tech = new Ticket_User();
            $techs = $tech->find([
                'tickets_id' => $item->fields['id'],
                'type'       => Ticket_User::ASSIGN,
            ]);
            if (count($techs) == 0) {
                $message .= '- ' . __('Technician') . '<br>';
            }
        }
        if ($conf->fields['require_technicians_group_to_close_ticket'] == 1) {
            $group = new Group_Ticket();
            $groups = $group->find([
                'tickets_id' => $item->fields['id'],
                'type'       => Ticket_User::ASSIGN,
            ]);
            if (count($groups) == 0) {
                $message .= '- ' . __('Technician group') . '<br>';
            }
        }
        if ($conf->fields['require_category_to_close_ticket'] == 1) {
            if ((isset($item->input['itilcategories_id']) && empty($item->input['itilcategories_id']))) {
                $message .= '- ' . __('Category') . '<br>';
            }
        }
        if ($conf->fields['require_location_to_close_ticket'] == 1) {
            if ((isset($item->input['locations_id']) && empty($item->input['locations_id']))) {
                $message .= '- ' . __('Location') . '<br>';
            }
        }

        // Check if solution exist before closing the ticket
        if ($conf->fields['require_solution_to_close_ticket'] == 1 && $item->input['status'] == CommonITILObject::CLOSED) {
            $solution = new ITILSolution();
            $solutions = $solution->find([
                'itemtype' => Ticket::class,
                'items_id' => $item->fields['id'],
                'NOT' => [
                    'status' => CommonITILValidation::REFUSED,
                ],
            ]);
            if (count($solutions) == 0) {
                $message .= '- ' . __('Solution') . '<br>';
            }
        }

        if (!empty($message)) {
            $message = __('To close this ticket, you must fill in the following fields:', 'moreoptions') . '<br>' . $message;
            Session::addMessageAfterRedirect($message, false, ERROR);
            return $item->input = false;
        }
    }

    public static function checkTaskRequirements($item)
    {
        $conf = Config::getCurrentConfig();
        if ($conf->fields['is_active'] != 1) {
            return $item;
        }

        $message = '';
        if ($conf->fields['mandatory_task_category'] == 1) {
            if (empty($item->input['taskcategories_id'])) {
                $message .= '- ' . __('Category') . '<br>';
            }
        }

        if ($conf->fields['mandatory_task_duration'] == 1) {
            if (empty($item->input['actiontime'])) {
                $message .= '- ' . __('Duration') . '<br>';
            }
        }

        if ($conf->fields['mandatory_task_user'] == 1) {
            if (empty($item->input['users_id_tech'])) {
                $message .= '- ' . __('User') . '<br>';
            }
        }

        if ($conf->fields['mandatory_task_group'] == 1) {
            if (empty($item->input['groups_id_tech'])) {
                $message .= '- ' . __('Group') . '<br>';
            }
        }

        if (!empty($message)) {
            $message = __('To create this task, you must fill in the following fields:', 'moreoptions') . '<br>' . $message;
            Session::addMessageAfterRedirect($message, false, ERROR);
            return $item->input = false;
        }
    }
}
