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
 * @copyright Copyright (C) 2022-2024 by Cancel Send plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsGLPI/moreoptions
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
    public static function getMenuName(): string
    {
        return __('More options', 'moreoptions');
    }

    public static function getTypeName($nb = 0): string
    {
        return __('More options', 'moreoptions');
    }

    /**
     * @return array<string, mixed>
     */
    public function defineTabs($options = []): array
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

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        switch ($item->getType()) {
            case Entity::class:
                return self::createTabEntry(__('More options', 'moreoptions'), 0);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        switch ($item->getType()) {
            case Entity::class:
                if ($item instanceof Entity) {
                    self::showForEntity($item);
                }
                return true;
        }
        return true;
    }

    public static function preItemUpdate(CommonDBTM $item): CommonDBTM
    {
        if (!is_array($item->input)) {
            return $item;
        }

        foreach (self::getItilConfigFields() as $field) {
            if (!isset($item->input[$field])) {
                $item->input[$field] = 0;
            } elseif ($item->input[$field] == 'on') {
                $item->input[$field] = 1;
            }
        }

        // Handle use_parent_entity field
        if (!isset($item->input['use_parent_entity'])) {
            $item->input['use_parent_entity'] = 0;
        } elseif ($item->input['use_parent_entity'] == 'on') {
            $item->input['use_parent_entity'] = 1;
        }

        return $item;
    }

    /**
     * @return array<string>
     */
    public static function getItilConfigFields(): array
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
            'require_technician_to_close_change',
            'require_technicians_group_to_close_change',
            'require_category_to_close_change',
            'require_location_to_close_change',
            'require_solution_to_close_change',
            'require_technician_to_close_problem',
            'require_technicians_group_to_close_problem',
            'require_category_to_close_problem',
            'require_location_to_close_problem',
            'require_solution_to_close_problem',
            'assign_technical_manager_when_changing_category_ticket',
            'assign_technical_group_when_changing_category_ticket',
            'assign_technical_manager_when_changing_category_change',
            'assign_technical_group_when_changing_category_change',
            'assign_technical_manager_when_changing_category_problem',
            'assign_technical_group_when_changing_category_problem',
            'mandatory_task_category',
            'mandatory_task_duration',
            'mandatory_task_user',
            'mandatory_task_group',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getSelectableActorGroup(): array
    {
        return [
            0  => __('No'),
            1  => __('Default', 'moreoptions'),
            2  => __('All'),
        ];
    }

    public static function showForEntity(Entity $item): void
    {
        $moconfig = new self();
        $moconfig->getFromDBByCrit([
            'entities_id' => $item->getID(),
        ]);

        // Get effective configuration to show which entity's config is actually used
        $effectiveConfig = self::getEffectiveConfigForEntity($item->getID());
        $parentEntityInfo = null;

        if (isset($moconfig->fields['use_parent_entity']) && $moconfig->fields['use_parent_entity'] == 1 && $effectiveConfig->fields['entities_id'] != $item->getID()) {
            $parentEntity = new Entity();
            if ($parentEntity->getFromDB($effectiveConfig->fields['entities_id'])) {
                $parentEntityInfo = $parentEntity->getName();
            }
        }

        TemplateRenderer::getInstance()->display(
            '@moreoptions/config.html.twig',
            [
                'item' => $moconfig,
                'dropdown_options' => self::getSelectableActorGroup(),
                'parent_entity_info' => $parentEntityInfo,
                'params' => [
                    'canedit' => true,
                ],
            ],
        );
    }

    public static function getIcon(): string
    {
        return "ti ti-send";
    }

    public static function addConfig(CommonDBTM $item): void
    {
        $moconfig = new self();
        $moconfig->add([
            'is_active' => 0,
            'entities_id' => $item->getID(),
        ]);
    }

    public static function getCurrentConfig(): self
    {
        $moconfig = new self();
        $moconfig->getFromDBByCrit([
            'entities_id' => Session::getActiveEntity(),
        ]);
        return $moconfig;
    }

    /**
     * Get effective configuration for current entity, considering parent entity inheritance
     */
    public static function getEffectiveConfig(): self
    {
        return self::getEffectiveConfigForEntity(Session::getActiveEntity());
    }

    /**
     * Get effective configuration for a specific entity, considering parent entity inheritance
     */
    public static function getEffectiveConfigForEntity(int $entityId): self
    {
        $moconfig = new self();
        $moconfig->getFromDBByCrit([
            'entities_id' => $entityId,
        ]);

        // If use_parent_entity is enabled and we're not at root entity
        if (isset($moconfig->fields['use_parent_entity']) && $moconfig->fields['use_parent_entity'] == 1 && $entityId > 0) {
            $entity = new Entity();
            if ($entity->getFromDB($entityId)) {
                $parentId = $entity->fields['entities_id'];
                return self::getEffectiveConfigForEntity($parentId);
            }
        }

        return $moconfig;
    }

    public static function install(Migration $migration): void
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
                `use_parent_entity` tinyint NOT NULL DEFAULT '0',
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
                `require_technician_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_technicians_group_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_category_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_location_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_solution_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_technician_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `require_technicians_group_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `require_category_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `require_location_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `require_solution_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `assign_technical_manager_when_changing_category_ticket` tinyint NOT NULL DEFAULT '0',
                `assign_technical_group_when_changing_category_ticket` tinyint NOT NULL DEFAULT '0',
                `assign_technical_manager_when_changing_category_change` tinyint NOT NULL DEFAULT '0',
                `assign_technical_group_when_changing_category_change` tinyint NOT NULL DEFAULT '0',
                `assign_technical_manager_when_changing_category_problem` tinyint NOT NULL DEFAULT '0',
                `assign_technical_group_when_changing_category_problem` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_category` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_duration` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_user` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_group` tinyint NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
              ";
            $DB->doQuery($query);
        }

        $entities = new Entity();
        foreach ($entities->find() as $entity) {
            if (is_array($entity) && isset($entity['id'])) {
                $data = [
                    'entities_id' => $entity['id'],
                ];
                $DB->insert(
                    self::getTable(),
                    $data,
                );
            }
        }
    }


    public static function uninstall(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE IF EXISTS `" . self::getTable() . "`");
        }
    }
}
