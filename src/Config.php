<?php

/**
 * -------------------------------------------------------------------------
 * Cancel Send plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Cancel Send.
 *
 * Cancel Send is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Cancel Send is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Cancel Send. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2022-2024 by Cancel Send plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://gitlab.teclib.com/glpi-network/cancelsend/
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Moreoptions;

use CommonDBTM;
use CommonGLPI;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use Migration;
use Session;

class Config extends CommonDBTM
{
    public $dohistory = true;
    public static $rightname = 'config';
    public static function getMenuName()
    {
        return __('More options', 'moreoptions');
    }

    public static function getTypeName($nb = 0)
    {
        return __('More options', 'moreoptions');
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        return $ong;
    }

    public static function canView(): bool
    {
        return true;
    }

    public function canEdit($ID): bool
    {
        return true;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        switch ($item->getType()) {
            case Entity::class:
                return self::createTabEntry(__('More options', 'moreoptions'), 0);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        switch ($item->getType()) {
            case Entity::class:
                return self::showForEntity($item);
        }
        return true;
    }

    public static function preItemUpdate(CommonDBTM $item)
    {
        foreach (self::getItilConfigFields() as $field) {
            if (!isset($item->input[$field])) {
                $item->input[$field] = 0;
            } elseif ($item->input[$field] == 'on') {
                $item->input[$field] = 1;
            }
        }

        return $item;
    }

    public static function getItilConfigFields()
    {
        return [
            'take_item_group_ticket',
            'take_item_group_change',
            'take_item_group_problem',
            'prevent_closure_ticket',
            'prevent_closure_change',
            'prevent_closure_problem',
            'require_technician_to_close_ticket',
            'require_technicians_group_to_close_ticket',
            'require_category_to_close_ticket',
            'require_location_to_close_ticket',
            'require_solution_to_close_ticket',
            'require_solution_type_to_close_ticket',
            'mandatory_task_category',
            'mandatory_task_duration',
            'mandatory_task_user',
            'mandatory_task_group',
        ];
    }

    public static function getSelectableActorGroup()
    {
        return [
            0  => __('No'),
            1  => __('Default', 'moreoptions'),
            2  => __('All'),
        ];
    }

    public static function showForEntity($item)
    {
        // $parents = getAncestorsOf(Entity::getTable(), $item->getID());
        // if (!empty($parents)) {
        //     foreach ($parents as $parent) {
        //         $pconfig = new Config();
        //         $pconfig->getFromDBByCrit([
        //             'entities_id' => $parent,
        //         ]);
        //         if ($pconfig->getField('is_active') == 1) {
        //             $pentity = $parent;
        //         }
        //     }
        //     $csconfig = new self();
        //     $csconfig->getFromDBByCrit([
        //         'entities_id' => $pentity ?? 0,
        //     ]);
        // }
        $moconfig = new self();
        $moconfig->getFromDBByCrit([
            'entities_id' => $item->getID(),
        ]);
        TemplateRenderer::getInstance()->display(
            '@moreoptions/config.html.twig',
            [
                'item' => $moconfig,
                'dropdown_options' => self::getSelectableActorGroup(),
                'params' => [
                    'canedit' => true,
                ],
            ],
        );
    }

    public static function getIcon()
    {
        return "ti ti-send";
    }

    public static function addConfig(CommonDBTM $item)
    {
        $moconfig = new self();
        $moconfig->add([
            'is_active' => 0,
            'entities_id' => $item->getID(),
        ]);
    }

    public static function getCurrentConfig()
    {
        $moconfig = new self();
        $moconfig->getFromDBByCrit([
            'entities_id' => Session::getActiveEntity(),
        ]);
        return $moconfig;
    }

    public static function install(Migration $migration)
    {
        /** @var \DBmysql $DB */
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = "CREATE TABLE IF NOT EXISTS `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `is_active`  tinyint NOT NULL DEFAULT '1',
                `entities_id` int unsigned NOT NULL DEFAULT '0',
                `take_item_group_ticket` tinyint NOT NULL DEFAULT '0',
                `take_item_group_change` tinyint NOT NULL DEFAULT '0',
                `take_item_group_problem` tinyint NOT NULL DEFAULT '0',
                `take_requester_group_ticket` int unsigned NOT NULL DEFAULT '0',
                `take_requester_group_change` int unsigned NOT NULL DEFAULT '0',
                `take_requester_group_problem` int unsigned NOT NULL DEFAULT '0',
                `take_technician_group_ticket` int unsigned NOT NULL DEFAULT '0',
                `take_technician_group_change` int unsigned NOT NULL DEFAULT '0',
                `take_technician_group_problem` int unsigned NOT NULL DEFAULT '0',
                `prevent_closure_ticket` tinyint NOT NULL DEFAULT '0',
                `prevent_closure_change` tinyint NOT NULL DEFAULT '0',
                `prevent_closure_problem` tinyint NOT NULL DEFAULT '0',
                `require_technician_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_technicians_group_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_category_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_location_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_solution_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_category` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_duration` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_user` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_group` tinyint NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
              ";
            $DB->doQuery($query);
        }

        $entities = new Entity();
        foreach ($entities->find() as $entity) {
            $data = [
                'entities_id' => $entity['id'],
            ];
            $DB->insert(
                self::getTable(),
                $data,
            );
        }
    }


    public static function uninstall(Migration $migration)
    {
        /** @var \DBmysql $DB */
        global $DB;
        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE IF EXISTS `" . self::getTable() . "`") or die($DB->error());
        }
    }
}
