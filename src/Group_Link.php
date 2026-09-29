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
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/moreoptions
 * @link      https://gitlab.teclib.com/glpi-network/moreoptions/
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Moreoptions;

use CommonDBRelation;
use CommonGLPI;
use DBConnection;
use DBmysql;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use Group;
use Migration;

/**
 * Escalation hierarchy between groups: the destination groups a source group can escalate to.
 * Managed from the "Escalation" tab of the source group.
 */
class Group_Link extends CommonDBRelation
{
    public static $itemtype_1 = Group::class;

    public static $items_id_1 = 'groups_id_source';

    public static $itemtype_2 = Group::class;

    public static $items_id_2 = 'groups_id_destination';

    public static function getTypeName($nb = 0): string
    {
        return __('Escalation', 'moreoptions');
    }

    public static function getIcon(): string
    {
        return 'ti ti-escalator-up';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Group || $item->isNewItem()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(), 0, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Group) {
            self::showForGroup($item);
        }

        return true;
    }

    /**
     * Renders the "Escalation" tab of the given group.
     */
    public static function showForGroup(Group $group): void
    {
        TemplateRenderer::getInstance()->display('@moreoptions/group_link.html.twig');
    }

    /**
     * The links applying in the given entity. For each pair of groups, the link of the closest
     * entity wins, from the given entity up to the root entity: a LinkStrategyEnum::BASIC link
     * only applies in its own entity, a LinkStrategyEnum::INHERITED one also in its sub-entities,
     * and a LinkStrategyEnum::NONE one removes the inherited link from its entity and its
     * sub-entities.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getLinksForEntity(int $entities_id): array
    {
        return [];
    }

    public function getLinkStrategy(): LinkStrategyEnum
    {
        return LinkStrategyEnum::tryFrom((string) ($this->fields['link_type'] ?? '')) ?? LinkStrategyEnum::getDefault();
    }

    public static function install(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage('Installing ' . $table);
            $default_charset   = DBConnection::getDefaultCharset();
            $default_collation = DBConnection::getDefaultCollation();
            $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

            $DB->doQuery("CREATE TABLE `{$table}` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `groups_id_source` int {$default_key_sign} NOT NULL DEFAULT '0',
                `groups_id_destination` int {$default_key_sign} NOT NULL DEFAULT '0',
                `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                `link_type` varchar(30) NOT NULL DEFAULT '',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`entities_id`, `groups_id_source`, `groups_id_destination`),
                KEY `groups_id_source` (`groups_id_source`),
                KEY `groups_id_destination` (`groups_id_destination`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
            ");
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::getTable());
    }
}
