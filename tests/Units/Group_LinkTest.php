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
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Moreoptions\Tests\Units;

use Dropdown;
use Entity;
use GlpiPlugin\Moreoptions\EscalationTree\EscalationLink;
use GlpiPlugin\Moreoptions\Group_Link;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use GlpiPlugin\Moreoptions\Tests\EscalationTestCase;
use Group;

final class Group_LinkTest extends EscalationTestCase
{
    /**
     * @param list<array{int, int, LinkStrategyEnum}> $links Source, destination and strategy of each link
     * @return list<EscalationLink>
     */
    private function toLinks(array $links): array
    {
        $objects = [];
        foreach ($links as $link) {
            $objects[] = new EscalationLink(...$link);
        }

        return $objects;
    }

    /**
     * @param list<EscalationLink> $links
     * @return list<string> Keys of the links (see EscalationLink::key())
     */
    private function getKeys(array $links): array
    {
        $keys = [];
        foreach ($links as $link) {
            $keys[] = $link->getKey();
        }

        return $keys;
    }

    /**
     * @return array<string, int|null> Entity of each link applying in the given entity, by link key, sorted
     */
    private function getApplyingEntities(int $entities_id): array
    {
        $entities = [];
        foreach (Group_Link::getLinksForEntity($entities_id) as $link) {
            $entities[$link->getKey()] = $link->entities_id;
        }

        ksort($entities);

        return $entities;
    }

    public function testSaveLinksForEntity(): void
    {
        $this->login();
        $entities_id = $this->getRootEntityId();
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $entities_id);
        $not_assignable = $this->createGroups(['Not assignable'], $entities_id, assignable: false)['Not assignable'];

        // A link to itself, or to a group that cannot be linked, is ignored.
        Group_Link::saveLinksForEntity($entities_id, $this->toLinks([
            [$a, $b, LinkStrategyEnum::BASIC],
            [$b, $c, LinkStrategyEnum::INHERITED],
            [$a, $a, LinkStrategyEnum::BASIC],
            [$a, $not_assignable, LinkStrategyEnum::BASIC],
        ]));
        $this->assertSame([
            sprintf('%d-%d', $a, $b) => LinkStrategyEnum::BASIC->value,
            sprintf('%d-%d', $b, $c) => LinkStrategyEnum::INHERITED->value,
        ], $this->getEntityLinks($entities_id));

        $link = new Group_Link();
        $this->assertTrue($link->getFromDBByCrit(['groups_id_source' => $a, 'groups_id_destination' => $b]));
        $link_id = $link->getID();

        // Updated in place, the other one is deleted.
        Group_Link::saveLinksForEntity($entities_id, $this->toLinks([[$a, $b, LinkStrategyEnum::INHERITED]]));
        $this->assertSame([sprintf('%d-%d', $a, $b) => LinkStrategyEnum::INHERITED->value], $this->getEntityLinks($entities_id));
        $this->assertTrue($link->getFromDB($link_id));

        Group_Link::saveLinksForEntity($entities_id, []);
        $this->assertSame([], $this->getEntityLinks($entities_id));
    }

    public function testSaveLinksForEntityKeepsNoneLinks(): void
    {
        $this->login();
        $entities_id = $this->getRootEntityId();
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], $entities_id);
        $this->createLinks($entities_id, [[$a, $b, LinkStrategyEnum::NONE]]);

        Group_Link::saveLinksForEntity($entities_id, []);
        $this->assertSame([sprintf('%d-%d', $a, $b) => LinkStrategyEnum::NONE->value], $this->getEntityLinks($entities_id));

        // Drawing the link again replaces it.
        Group_Link::saveLinksForEntity($entities_id, $this->toLinks([[$a, $b, LinkStrategyEnum::BASIC]]));
        $this->assertSame([sprintf('%d-%d', $a, $b) => LinkStrategyEnum::BASIC->value], $this->getEntityLinks($entities_id));
    }

    public function testRootEntityInheritsNothing(): void
    {
        $this->login();
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], 0);
        $this->createLinks(0, [[$a, $b, LinkStrategyEnum::INHERITED]]);

        // The links of the root entity are its own, not replicated from itself.
        $this->assertNotContains(sprintf('%d-%d', $a, $b), $this->getKeys(Group_Link::getInheritedLinks(0)));
        $this->assertContains(sprintf('%d-%d', $a, $b), $this->getKeys(Group_Link::getLinksForEntity(0)));
    }

    public function testGetLinksForEntity(): void
    {
        $this->login();
        $root_id       = $this->getRootEntityId();
        $child_id      = $this->createChildEntity('Child', $root_id);
        $grandchild_id = $this->createChildEntity('Grandchild', $child_id);
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $root_id, true);
        $this->createLinks($root_id, [
            [$a, $b, LinkStrategyEnum::INHERITED],
            [$a, $c, LinkStrategyEnum::BASIC],
            [$b, $c, LinkStrategyEnum::INHERITED],
        ]);
        $this->createLinks($child_id, [
            [$b, $c, LinkStrategyEnum::NONE],
            [$c, $a, LinkStrategyEnum::BASIC],
        ]);

        $expected = [sprintf('%d-%d', $a, $b) => $root_id, sprintf('%d-%d', $a, $c) => $root_id, sprintf('%d-%d', $b, $c) => $root_id];
        ksort($expected);
        $this->assertSame($expected, $this->getApplyingEntities($root_id));

        // Only the inherited links of the root apply, minus the one removed by the "none" link.
        $expected = [sprintf('%d-%d', $a, $b) => $root_id, sprintf('%d-%d', $c, $a) => $child_id];
        ksort($expected);
        $this->assertSame($expected, $this->getApplyingEntities($child_id));

        // The basic link of the child does not apply to its sub-entities, and the "none" link
        // still stops the inherited one.
        $this->assertSame([sprintf('%d-%d', $a, $b) => $root_id], $this->getApplyingEntities($grandchild_id));
    }

    public function testBasicLinkOfAMiddleEntityDoesNotHideAnInheritedOne(): void
    {
        $this->login();
        $root_id       = $this->getRootEntityId();
        $child_id      = $this->createChildEntity('Child', $root_id);
        $grandchild_id = $this->createChildEntity('Grandchild', $child_id);
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], $root_id, true);
        $this->createLinks($root_id, [[$a, $b, LinkStrategyEnum::INHERITED]]);
        $this->createLinks($child_id, [[$a, $b, LinkStrategyEnum::BASIC]]);

        // The basic link only applies in the child entity: the grandchild gets the one of the root.
        $this->assertSame([$child_id], array_column(Group_Link::getLinksForEntity($child_id), 'entities_id'));
        $this->assertSame([$root_id], array_column(Group_Link::getLinksForEntity($grandchild_id), 'entities_id'));
    }

    public function testSaveLinksForEntityRestoresAndKeepsUnmanagedLinks(): void
    {
        $this->login();
        $entities_id = $this->getRootEntityId();
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $entities_id);
        $this->createLinks($entities_id, [
            [$a, $b, LinkStrategyEnum::NONE],
            [$a, $c, LinkStrategyEnum::BASIC],
        ]);
        // C cannot be assigned any more: its link is not managed by the graph, and stays.
        $this->updateItem(Group::class, $c, ['is_assign' => 0]);

        Group_Link::saveLinksForEntity($entities_id, [], [EscalationLink::key($a, $b)]);
        $this->assertSame([sprintf('%d-%d', $a, $c) => LinkStrategyEnum::BASIC->value], $this->getEntityLinks($entities_id));
    }

    public function testSaveLinksForEntityReplacingAllDeletesUnmanagedLinks(): void
    {
        $this->login();
        $entities_id = $this->getRootEntityId();
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], $entities_id);
        $this->createLinks($entities_id, [[$a, $b, LinkStrategyEnum::BASIC]]);
        $this->updateItem(Group::class, $b, ['is_assign' => 0]);

        // The graph was reset: no link is left, even between groups it does not manage.
        Group_Link::saveLinksForEntity($entities_id, [], [], true);
        $this->assertSame([], $this->getEntityLinks($entities_id));
    }

    public function testFindLoop(): void
    {
        $this->login();
        $root_id  = $this->getRootEntityId();
        $child_id = $this->createChildEntity('Child', $root_id);
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $root_id, true);
        $this->assertNull(Group_Link::findLoop($root_id));

        // A -> B -> C in the child entity, then C -> A inherited from the root entity
        Group_Link::saveLinksForEntity($child_id, $this->toLinks([[$a, $b, LinkStrategyEnum::BASIC], [$b, $c, LinkStrategyEnum::BASIC]]));
        $this->assertNull(Group_Link::findLoop($root_id));
        Group_Link::saveLinksForEntity($root_id, $this->toLinks([[$c, $a, LinkStrategyEnum::INHERITED]]));

        $loop = Group_Link::findLoop($root_id);
        $this->assertNotNull($loop);
        $this->assertSame($child_id, $loop['entities_id']);
        $groups = $loop['groups'];
        sort($groups);
        $this->assertSame([$a, $b, $c], $groups);
    }

    public function testVersion(): void
    {
        $this->login();
        $entities_id = $this->getRootEntityId();
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], $entities_id);
        $before = Group_Link::getVersion(Group_Link::getRowsOfEntity($entities_id));
        $this->assertSame($before, Group_Link::getVersion(Group_Link::getRowsOfEntity($entities_id)));
        Group_Link::saveLinksForEntity($entities_id, $this->toLinks([[$a, $b, LinkStrategyEnum::BASIC]]));
        $this->assertNotSame($before, Group_Link::getVersion(Group_Link::getRowsOfEntity($entities_id)));
    }

    public function testLinksOfPurgedItemsAreDeleted(): void
    {
        $this->login();
        $root_id  = $this->getRootEntityId();
        $child_id = $this->createChildEntity('Child', $root_id);
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $root_id, true);
        Group_Link::saveLinksForEntity($root_id, $this->toLinks([[$a, $b, LinkStrategyEnum::BASIC], [$b, $c, LinkStrategyEnum::BASIC]]));
        Group_Link::saveLinksForEntity($child_id, $this->toLinks([[$a, $c, LinkStrategyEnum::BASIC]]));

        $this->deleteItem(Group::class, $b, true);
        $this->assertSame([], $this->getEntityLinks($root_id));
        $this->assertCount(1, $this->getEntityLinks($child_id));

        $this->deleteItem(Entity::class, $child_id, true);
        $this->assertSame([], $this->getEntityLinks($child_id));
    }

    public function testLinksAreOnlyChangedFromTheEscalationTab(): void
    {
        $this->login();
        $entities_id = $this->getRootEntityId();
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], $entities_id);
        $this->createLinks($entities_id, [[$a, $b, LinkStrategyEnum::BASIC]]);

        // Not from the generic form, list, massive actions or API of GLPI, even as super-admin
        $this->assertFalse(Group_Link::canCreate());
        $this->assertFalse(Group_Link::canView());
        $this->assertFalse(Group_Link::canUpdate());
        $this->assertFalse(Group_Link::canDelete());
        $this->assertFalse(Group_Link::canPurge());

        $link = new Group_Link();
        $this->assertTrue($link->getFromDBByCrit(['groups_id_source' => $a, 'groups_id_destination' => $b]));
        $this->assertFalse($link->canCreateItem());
        $this->assertFalse($link->canViewItem());
        $this->assertFalse($link->canUpdateItem());
        $this->assertFalse($link->canDeleteItem());
        $this->assertFalse($link->canPurgeItem());
    }

    public function testNamesOfWhatTheUserCannotSee(): void
    {
        $this->login();
        $root_id  = $this->getRootEntityId();
        $child_id = $this->createChildEntity('Child', $root_id);
        ['Recursive' => $recursive] = $this->createGroups(['Recursive'], $root_id, true);
        ['Local' => $local]         = $this->createGroups(['Local'], $root_id);
        ['Own' => $own]             = $this->createGroups(['Own'], $child_id);
        $this->setEntity($child_id, true);

        // From the child entity, a non recursive group of the root entity is hidden.
        $this->assertSame([
            $recursive => ['name' => 'Recursive', 'visible' => true],
            $local     => ['name' => sprintf('Hidden group #%d', $local), 'visible' => false],
            $own       => ['name' => 'Own', 'visible' => true],
        ], Group_Link::getGroupNames([$recursive, $local, $own]));
        $this->assertSame([], Group_Link::getGroupNames([]));

        // So is the root entity.
        $this->assertSame(sprintf('Hidden entity #%d', $root_id), Group_Link::getEntityName($root_id));
        $this->assertSame(Dropdown::getDropdownName(Entity::getTable(), $child_id), Group_Link::getEntityName($child_id));
        $this->assertStringContainsString('Child', Group_Link::getEntityName($child_id));
    }

    public function testNextLevelGroups(): void
    {
        $this->login();
        $root_id  = $this->getRootEntityId();
        $child_id = $this->createChildEntity('Child', $root_id);
        $ids      = $this->createGroups(['A', 'B', 'C', 'D', 'E'], $root_id, true);
        // In the root entity: A -> B (inherited), A -> C (basic), B -> D (inherited)
        $this->createLinks($root_id, [
            [$ids['A'], $ids['B'], LinkStrategyEnum::INHERITED],
            [$ids['A'], $ids['C'], LinkStrategyEnum::BASIC],
            [$ids['B'], $ids['D'], LinkStrategyEnum::INHERITED],
        ]);
        // In the child entity: A -> B removed, A -> E added
        $this->createLinks($child_id, [
            [$ids['A'], $ids['B'], LinkStrategyEnum::NONE],
            [$ids['A'], $ids['E'], LinkStrategyEnum::BASIC],
        ]);

        // Only the next level
        $this->assertSame([$ids['B'], $ids['C']], Group_Link::getNextLevelGroups($ids['A'], $root_id));
        // The links applying in the entity: the inherited ones, not the ones removed
        $this->assertSame([$ids['E']], Group_Link::getNextLevelGroups($ids['A'], $child_id));
        $this->assertSame([$ids['D']], Group_Link::getNextLevelGroups($ids['B'], $child_id));
        // The last level escalates to no group.
        $this->assertSame([], Group_Link::getNextLevelGroups($ids['D'], $child_id));
    }

    public function testLinksOnlyApplyBetweenGroupsAssignableInTheEntity(): void
    {
        $this->login();
        $root_id  = $this->getRootEntityId();
        $child_id = $this->createChildEntity('Child', $root_id);
        $a        = $this->createGroups(['A'], $root_id, true)['A'];
        // Not recursive: not visible in the child entity
        $local = $this->createGroups(['Local'], $root_id)['Local'];
        $this->createLinks($root_id, [[$local, $a, LinkStrategyEnum::INHERITED], [$a, $local, LinkStrategyEnum::INHERITED]]);

        // It applies in the root entity only.
        $this->assertCount(2, Group_Link::getLinksForEntity($root_id));
        $this->assertSame([$local], Group_Link::getNextLevelGroups($a, $root_id));
        $this->assertSame([], Group_Link::getLinksForEntity($child_id));
        $this->assertSame([], Group_Link::getInheritedLinks($child_id));
        $this->assertSame([], Group_Link::getNextLevelGroups($a, $child_id));

        // Recursive, it applies in the child entity too.
        $this->updateItem(Group::class, $local, ['is_recursive' => 1]);
        $this->assertCount(2, Group_Link::getLinksForEntity($child_id));
        $this->assertSame([$local], Group_Link::getNextLevelGroups($a, $child_id));

        // Not assignable any more, it applies nowhere: no ticket can be escalated to it.
        $this->updateItem(Group::class, $local, ['is_assign' => 0]);
        $this->assertSame([], Group_Link::getLinksForEntity($root_id));
        $this->assertSame([], Group_Link::getLinksForEntity($child_id));
        $this->assertSame([], Group_Link::getNextLevelGroups($a, $root_id));
    }

    public function testLinksArePurgedWithTheirGroupOrEntity(): void
    {
        $this->login();
        $root_id  = $this->getRootEntityId();
        $child_id = $this->createChildEntity('Child', $root_id);
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $root_id, true);
        $this->createLinks($root_id, [[$a, $b, LinkStrategyEnum::INHERITED], [$c, $a, LinkStrategyEnum::BASIC], [$b, $c, LinkStrategyEnum::BASIC]]);
        $this->createLinks($child_id, [[$b, $c, LinkStrategyEnum::NONE]]);

        // The links of a purged group, from it or to it, are deleted.
        $this->deleteItem(Group::class, $a, true);
        $this->assertSame([sprintf('%d-%d', $b, $c) => LinkStrategyEnum::BASIC->value], $this->getEntityLinks($root_id));

        // The links of a purged entity are deleted.
        $this->deleteItem(Entity::class, $child_id, true);
        $this->assertSame([], $this->getEntityLinks($child_id));
        $this->assertSame([sprintf('%d-%d', $b, $c) => LinkStrategyEnum::BASIC->value], $this->getEntityLinks($root_id));
    }
}
