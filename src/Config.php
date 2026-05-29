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
    public const CONFIG_PARENT = \Entity::CONFIG_PARENT;
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
            if (isset($item->input[$field])) {
                $item->input[$field] = (int) $item->input[$field];
            }
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
            'take_requester_group_ticket',
            'take_requester_group_change',
            'take_requester_group_problem',
            'take_technician_group_ticket',
            'take_technician_group_change',
            'take_technician_group_problem',
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

        $inheritance_labels = [];
        if ($item->getID() > 0) {
            $parentConfig = self::getConfig($item->fields['entities_id'], true);
            foreach (self::getItilConfigFields() as $field) {
                $inheritance_labels[$field] = self::getInheritedValueBadge($parentConfig->fields[$field] ?? 0);
            }
            foreach ([
                'take_requester_group_ticket',
                'take_requester_group_change',
                'take_requester_group_problem',
                'take_technician_group_ticket',
                'take_technician_group_change',
                'take_technician_group_problem',
            ] as $field) {
                $inheritance_labels[$field] = self::getInheritedValueBadgeForActorGroup($parentConfig->fields[$field] ?? 0);
            }
        }

        TemplateRenderer::getInstance()->display(
            '@moreoptions/config.html.twig',
            [
                'item'               => $moconfig,
                'dropdown_options'   => self::getSelectableActorGroup(),
                'inheritance_labels' => $inheritance_labels,
                'params'             => [
                    'canedit' => true,
                ],
            ],
        );
    }

    public static function getIcon(): string
    {
        return "ti ti-send";
    }

    private static function getInheritedValueBadge(mixed $value): string
    {
        $text = match ((int) $value) {
            1       => __('Yes'),
            default => __('No'),
        };
        return Entity::inheritedValue(htmlescape($text), false, false);
    }

    private static function getInheritedValueBadgeForActorGroup(mixed $value): string
    {
        $options = self::getSelectableActorGroup();
        $text = $options[(int) $value] ?? __('No');
        return Entity::inheritedValue(htmlescape($text), false, false);
    }

    public static function addConfig(CommonDBTM $item): void
    {
        $moconfig = new self();
        $entity_id = $item->getID();
        $data = ['entities_id' => $entity_id];
        if ($entity_id > 0) {
            foreach (self::getItilConfigFields() as $field) {
                $data[$field] = self::CONFIG_PARENT;
            }
        }
        $moconfig->add($data);
    }

    /**
     * Get configuration for an entity
     *
     * @param int|null $entityId Entity ID (null = current active entity)
     * @param bool $useInheritance Whether to follow parent entity inheritance (default: true)
     * @return self
     */
    public static function getConfig(?int $entityId = null, bool $useInheritance = true): self
    {
        if ($entityId === null) {
            $entityId = Session::getActiveEntity();
        }

        $moconfig = new self();
        $moconfig->getFromDBByCrit([
            'entities_id' => $entityId,
        ]);

        if ($useInheritance && $entityId > 0) {
            $entity = new Entity();
            if ($entity->getFromDB($entityId)) {
                $parentConfig = self::getConfig((int) $entity->fields['entities_id'], true);
                foreach (self::getItilConfigFields() as $field) {
                    if (($moconfig->fields[$field] ?? 0) == self::CONFIG_PARENT) {
                        $moconfig->fields[$field] = $parentConfig->fields[$field] ?? 0;
                    }
                }
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
                `entities_id` int unsigned NOT NULL DEFAULT '0',
                `take_item_group_ticket` tinyint NOT NULL DEFAULT '0',
                `take_item_group_change` tinyint NOT NULL DEFAULT '0',
                `take_item_group_problem` tinyint NOT NULL DEFAULT '0',
                `take_requester_group_ticket` tinyint NOT NULL DEFAULT '0',
                `take_requester_group_change` tinyint NOT NULL DEFAULT '0',
                `take_requester_group_problem` tinyint NOT NULL DEFAULT '0',
                `take_technician_group_ticket` tinyint NOT NULL DEFAULT '0',
                `take_technician_group_change` tinyint NOT NULL DEFAULT '0',
                `take_technician_group_problem` tinyint NOT NULL DEFAULT '0',
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
                KEY `entities_id` (`entities_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
              ";
            $DB->doQuery($query);
        }

        foreach ([
            'take_requester_group_ticket',
            'take_requester_group_change',
            'take_requester_group_problem',
            'take_technician_group_ticket',
            'take_technician_group_change',
            'take_technician_group_problem',
        ] as $field) {
            if ($DB->fieldExists($table, $field)) {
                $migration->changeField($table, $field, $field, 'bool', ['value' => '0']);
            }
        }

        $migration->executeMigration();

        $entities = new Entity();
        foreach ($entities->find() as $entity) {
            if (is_array($entity) && isset($entity['id'])) {
                $entity_id = (int) $entity['id'];
                if (countElementsInTable(self::getTable(), ['entities_id' => $entity_id]) > 0) {
                    continue;
                }
                $data = ['entities_id' => $entity_id];
                if ($entity_id > 0) {
                    foreach (self::getItilConfigFields() as $field) {
                        $data[$field] = self::CONFIG_PARENT;
                    }
                }
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
