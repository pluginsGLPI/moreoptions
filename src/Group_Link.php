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
use CommonDBTM;
use DBConnection;
use DBmysql;
use Dropdown;
use Entity;
use GlpiPlugin\Moreoptions\EscalationTree\EscalationGraph;
use GlpiPlugin\Moreoptions\EscalationTree\EscalationLink;
use GlpiPlugin\Moreoptions\EscalationTree\TreeEditor;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use Group;
use Migration;
use Session;

use function Safe\filemtime;

/**
 * Links of the escalation hierarchy between groups: the destination groups a source group can
 * escalate to, in an entity. Edited as a tree (see EscalationTree\TreeEditor) from the
 * "Escalate" tab of the configuration of the entity (see Config::showForEntity()).
 *
 * The links are only changed from this tab, which checks the rights and keeps the tree
 * consistent (no loop, entity of the links): never directly, from the generic form, list,
 * massive actions or API of GLPI, which only check the rights on the groups.
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

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public function canCreateItem(): bool
    {
        return false;
    }

    public function canViewItem(): bool
    {
        return false;
    }

    public function canUpdateItem(): bool
    {
        return false;
    }

    public function canDeleteItem(): bool
    {
        return false;
    }

    public function canPurgeItem(): bool
    {
        return false;
    }

    /**
     * Variables of the editor of the escalation tree of the given entity (see
     * templates/group_link.html.twig), shown in the "Escalate" tab of its configuration (see
     * Config::showForEntity()).
     *
     * @return array<string, mixed>
     */
    public static function getEditorVariables(Entity $entity): array
    {
        return TreeEditor::fromDatabase($entity->getID())->getTemplateVariables() + [
            // Changes with the script, for the browser not to keep an outdated one in its cache.
            'script_version' => PLUGIN_MOREOPTIONS_VERSION . '-' . filemtime(dirname(__DIR__) . '/public/js/escalation_graph.js'),
        ];
    }

    /**
     * Groups a ticket can be assigned to in the given entity: the groups that can be linked there
     * (see isAssignableIn()).
     *
     * @return array<int, string> Names, by group id
     */
    public static function getGroupsForEntity(int $entities_id): array
    {
        $parents = self::getParentEntities($entities_id);
        $groups  = (new Group())->find(['is_assign' => 1, 'entities_id' => [...$parents, $entities_id]], 'completename');
        $scopes  = self::toScopes($groups);
        $parents = array_flip($parents);

        $names = [];
        foreach ($groups as $id => $group) {
            if (self::isAssignableIn($id, $scopes, $entities_id, $parents)) {
                $names[$id] = (string) $group['completename'];
            }
        }

        return $names;
    }

    /**
     * Names of the given groups, as the user may see them: the name of a group of an entity the
     * user has no access to is hidden.
     *
     * @param list<int> $ids
     * @return array<int, array{name: string, visible: bool}> By group id
     */
    public static function getGroupNames(array $ids): array
    {
        $names = [];
        foreach ($ids as $id) {
            $names[$id] = ['name' => sprintf(__('Hidden group #%d', 'moreoptions'), $id), 'visible' => false];
        }
        $groups = $ids !== [] ? (new Group())->find(['id' => $ids]) : [];
        foreach ($groups as $id => $group) {
            if (Session::haveAccessToEntity((int) $group['entities_id'], (bool) $group['is_recursive'])) {
                $names[$id] = ['name' => (string) $group['completename'], 'visible' => true];
            }
        }

        return $names;
    }

    /**
     * Name of the given entity, as the user may see it: hidden for an entity the user has no access to.
     */
    public static function getEntityName(int $entities_id): string
    {
        return Session::haveAccessToEntity($entities_id)
            ? Dropdown::getDropdownName(Entity::getTable(), $entities_id)
            : sprintf(__('Hidden entity #%d', 'moreoptions'), $entities_id);
    }

    /**
     * The links applying in the given entity, with the entity each one is stored in. For each pair
     * of groups, the link applying is searched from the entity up to the root entity (see
     * EscalationLink::resolve()). A link only applies between groups that can be assigned in the
     * entity (see isAssignableIn()).
     *
     * @return list<EscalationLink>
     */
    public static function getLinksForEntity(int $entities_id): array
    {
        return self::getApplyingLinks($entities_id, true);
    }

    /**
     * Groups of the next level of the escalation, from the given group in the given entity: the
     * groups it escalates to directly, through the links applying in the entity (see
     * getLinksForEntity()). Whatever the rights of the user: for the escalation itself.
     *
     * @return list<int> Ids of the groups, in ascending order
     */
    public static function getNextLevelGroups(int $groups_id, int $entities_id): array
    {
        $destinations = EscalationGraph::fromLinks([], self::getLinksForEntity($entities_id))->getChildren($groups_id);
        sort($destinations);

        return $destinations;
    }

    /**
     * The links the parent entities pass down to the given entity, ignoring its own links: the
     * links it would get if it had none (see getLinksForEntity()).
     *
     * @return list<EscalationLink>
     */
    public static function getInheritedLinks(int $entities_id): array
    {
        return self::getApplyingLinks($entities_id, false);
    }

    /**
     * Stored links of the given entity, in creation order.
     *
     * @return list<array<string, mixed>>
     */
    public static function getRowsOfEntity(int $entities_id): array
    {
        return array_values((new self())->find(['entities_id' => $entities_id], 'id'));
    }

    /**
     * Version of the stored links of an entity, changing each time they are saved: a draft made
     * from an older version would overwrite the links saved since.
     *
     * @param list<array<string, mixed>> $rows See getRowsOfEntity()
     */
    public static function getVersion(array $rows): string
    {
        $fields = [];
        foreach ($rows as $row) {
            $fields[] = [$row['id'], ...array_values(EscalationLink::fromRow($row)->toRow())];
        }

        return md5(serialize($fields));
    }

    /**
     * Replaces the links of the given entity by the given ones, as drawn in the "Escalate" tab of its configuration.
     *
     * Only the links between groups that can be linked in the entity, and the ones inherited from
     * its parent entities, are managed: the given ones between other groups are ignored, and the
     * stored ones between other groups (a group that cannot be assigned any more, for instance)
     * are left as they are, unless all are replaced.
     *
     * The LinkStrategyEnum::NONE links of the entity are kept unless the given links define the
     * same pair of groups, or the inherited link they remove applies again in the entity. With
     * $replace_all, all the links of the entity are replaced (the graph was reset).
     *
     * The groups that can be linked in the entity, and the inherited links, are read from the
     * database unless given (as already known by an EscalationTree, for instance).
     *
     * @param list<EscalationLink>    $links     Links of the entity, LinkStrategyEnum::NONE ones included
     * @param list<string>            $applying  Keys (see EscalationLink::key()) of the inherited links applying in the entity
     * @param array<int, string>|null $groups    See getGroupsForEntity()
     * @param list<string>|null       $inherited Keys of the links inherited from the parent entities, see getInheritedLinks()
     */
    public static function saveLinksForEntity(
        int $entities_id,
        array $links,
        array $applying = [],
        bool $replace_all = false,
        ?array $groups = null,
        ?array $inherited = null,
    ): void {
        $groups ??= self::getGroupsForEntity($entities_id);
        if ($inherited === null) {
            $inherited = [];
            foreach (self::getInheritedLinks($entities_id) as $link) {
                $inherited[] = $link->getKey();
            }
        }
        $inherited = array_flip($inherited);
        $applying  = array_flip($applying);

        $wanted = [];
        foreach ($links as $link) {
            if ($link->source !== $link->destination && self::manages($link, $groups, $inherited)) {
                $wanted[$link->getKey()] = $link;
            }
        }

        $group_link = new self();
        foreach (self::getRowsOfEntity($entities_id) as $row) {
            $stored = EscalationLink::fromRow($row);
            $key    = $stored->getKey();
            if (!$replace_all && !self::manages($stored, $groups, $inherited)) {
                continue;
            }
            if (!isset($wanted[$key])) {
                if ($replace_all || $stored->strategy !== LinkStrategyEnum::NONE || isset($applying[$key])) {
                    $group_link->delete(['id' => $row['id'], '_no_message' => true]);
                }
                continue;
            }
            if ($stored->strategy !== $wanted[$key]->strategy) {
                $group_link->update(['id' => $row['id'], 'link_type' => $wanted[$key]->strategy->value, '_no_message' => true]);
            }
            unset($wanted[$key]);
        }

        foreach ($wanted as $link) {
            $group_link->add($link->toRow() + ['entities_id' => $entities_id, '_no_message' => true]);
        }
    }

    /**
     * A loop in the links applying in the given entity or in one of its sub-entities: groups
     * escalating, directly or not, to themselves. Links of different entities can make one: a
     * link inherited from a parent entity, the other way of a link of a sub-entity.
     *
     * @return array{entities_id: int, groups: list<int>}|null The first entity with a loop, and the groups of the loop, in order
     *
     * @phpstan-impure Read from the database
     */
    public static function findLoop(int $entities_id): ?array
    {
        $sons    = array_map('intval', array_values(getSonsOf(Entity::getTable(), $entities_id)));
        $by_pair = self::getLinksByPair([...self::getParentEntities($entities_id), ...$sons]);
        $scopes  = self::getGroupScopes($by_pair);
        foreach ($sons as $entity) {
            $links = self::resolveLinks($by_pair, $scopes, $entity, self::getParentEntities($entity), true);
            $loop  = EscalationGraph::fromLinks([], $links)->findCycle();
            if ($loop !== null) {
                return ['entities_id' => $entity, 'groups' => $loop];
            }
        }

        return null;
    }

    /**
     * Deletes the links of a purged group (see setup.php).
     */
    public static function cleanForGroup(CommonDBTM $group): void
    {
        (new self())->deleteByCriteria([
            'OR' => ['groups_id_source' => $group->getID(), 'groups_id_destination' => $group->getID()],
        ], true);
    }

    /**
     * Deletes the links of a purged entity (see setup.php).
     */
    public static function cleanForEntity(CommonDBTM $entity): void
    {
        (new self())->deleteByCriteria(['entities_id' => $entity->getID()], true);
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

    /**
     * The links applying in the given entity (see getLinksForEntity()), or only the ones its parent
     * entities pass down to it (see getInheritedLinks()).
     *
     * @return list<EscalationLink>
     */
    private static function getApplyingLinks(int $entities_id, bool $with_own): array
    {
        $parents = self::getParentEntities($entities_id);
        $by_pair = self::getLinksByPair($with_own ? [...$parents, $entities_id] : $parents);

        return self::resolveLinks($by_pair, self::getGroupScopes($by_pair), $entities_id, $parents, $with_own);
    }

    /**
     * Parent entities of the given entity, up to the root entity.
     *
     * @return list<int>
     */
    private static function getParentEntities(int $entities_id): array
    {
        // getAncestorsOf() gives the root entity as its own ancestor.
        return array_values(array_diff(
            array_map('intval', array_values(getAncestorsOf(Entity::getTable(), $entities_id))),
            [$entities_id],
        ));
    }

    /**
     * Stored links of the given entities, by pair of groups (see EscalationLink::key()), from the
     * deepest entity: as EscalationLink::resolve() wants them.
     *
     * @param list<int> $entities
     * @return array<string, list<EscalationLink>>
     */
    private static function getLinksByPair(array $entities): array
    {
        if ($entities === []) {
            return [];
        }

        $by_entity = [];
        foreach ((new self())->find(['entities_id' => $entities]) as $row) {
            $by_entity[(int) $row['entities_id']][] = EscalationLink::fromRow($row);
        }

        // The entities from the deepest one, so that the links of each pair are added in this order
        $by_pair = [];
        foreach (array_keys((new Entity())->find(['id' => $entities], 'level DESC')) as $entity) {
            foreach ($by_entity[$entity] ?? [] as $link) {
                $by_pair[$link->getKey()][] = $link;
            }
        }

        return $by_pair;
    }

    /**
     * Where the groups of the given links can be assigned (see isAssignableIn()): the groups that
     * cannot be assigned are left out.
     *
     * @param array<string, list<EscalationLink>> $by_pair See getLinksByPair()
     * @return array<int, array{entities_id: int, is_recursive: bool}> By group id
     */
    private static function getGroupScopes(array $by_pair): array
    {
        $ids = [];
        foreach ($by_pair as $links) {
            $ids[$links[0]->source]      = true;
            $ids[$links[0]->destination] = true;
        }

        return self::toScopes($ids !== [] ? (new Group())->find(['id' => array_keys($ids), 'is_assign' => 1]) : []);
    }

    /**
     * Where the given groups can be assigned (see isAssignableIn()).
     *
     * @param array<int, array<string, mixed>> $groups Rows of the groups, by id
     * @return array<int, array{entities_id: int, is_recursive: bool}> By group id
     */
    private static function toScopes(array $groups): array
    {
        $scopes = [];
        foreach ($groups as $id => $group) {
            $scopes[$id] = [
                'entities_id'  => (int) $group['entities_id'],
                'is_recursive' => (bool) $group['is_recursive'],
            ];
        }

        return $scopes;
    }

    /**
     * Whether a link is managed by saveLinksForEntity(): between groups that can be linked in the
     * entity, or inherited from its parent entities.
     *
     * @param array<int, string> $groups    See getGroupsForEntity()
     * @param array<string, int> $inherited Keys of the inherited links, as keys
     */
    private static function manages(EscalationLink $link, array $groups, array $inherited): bool
    {
        return isset($groups[$link->source], $groups[$link->destination]) || isset($inherited[$link->getKey()]);
    }

    /**
     * The links applying in an entity (see getLinksForEntity()), among the given ones.
     *
     * @param array<string, list<EscalationLink>>                     $by_pair  See getLinksByPair()
     * @param array<int, array{entities_id: int, is_recursive: bool}> $scopes   See getGroupScopes()
     * @param list<int>                                               $parents  Parent entities of the entity
     * @param bool                                                    $with_own Whether the links of the entity itself are searched too: if not, the links the parent entities pass down to it
     * @return list<EscalationLink>
     */
    private static function resolveLinks(array $by_pair, array $scopes, int $entities_id, array $parents, bool $with_own): array
    {
        // Entities whose links are searched
        $entities = array_flip($with_own ? [...$parents, $entities_id] : $parents);
        $parents  = array_flip($parents);

        $applying = [];
        foreach ($by_pair as $links) {
            if (
                !self::isAssignableIn($links[0]->source, $scopes, $entities_id, $parents)
                || !self::isAssignableIn($links[0]->destination, $scopes, $entities_id, $parents)
            ) {
                continue;
            }

            $link = EscalationLink::resolve($links, $entities_id, $entities);
            if ($link instanceof EscalationLink) {
                $applying[] = $link;
            }
        }

        return $applying;
    }

    /**
     * Whether the group can be assigned in the given entity: assignable (not a purged one), of the
     * entity, or of a parent entity and recursive.
     *
     * @param array<int, array{entities_id: int, is_recursive: bool}> $scopes  See toScopes()
     * @param array<int, int>                                         $parents Parent entities of the entity, as keys
     */
    private static function isAssignableIn(int $group, array $scopes, int $entities_id, array $parents): bool
    {
        if (!isset($scopes[$group])) {
            return false;
        }

        ['entities_id' => $group_entity, 'is_recursive' => $is_recursive] = $scopes[$group];

        return $group_entity === $entities_id || ($is_recursive && isset($parents[$group_entity]));
    }
}
