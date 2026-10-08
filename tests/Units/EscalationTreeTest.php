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

use GlpiPlugin\Moreoptions\EscalationTree\EscalationLink;
use GlpiPlugin\Moreoptions\EscalationTree\EscalationTree;
use GlpiPlugin\Moreoptions\EscalationTree\GroupNode;
use GlpiPlugin\Moreoptions\Group_Link;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use GlpiPlugin\Moreoptions\Tests\EscalationTestCase;

final class EscalationTreeTest extends EscalationTestCase
{
    /**
     * The tree of the root entity, with the given groups of the root entity placed.
     *
     * @param list<string> $names
     * @return array{EscalationTree, array<string, int>} The tree, and the ids of the groups, by name
     */
    private function createTree(array $names): array
    {
        $entities_id = $this->getRootEntityId();
        $ids         = $this->createGroups($names, $entities_id);

        return [EscalationTree::load($entities_id, array_values($ids)), $ids];
    }

    /**
     * @param array<GroupNode> $nodes
     * @return list<int>
     */
    private function getIds(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = $node->id;
        }

        sort($ids);

        return $ids;
    }

    public function testHierarchy(): void
    {
        $this->login();
        [$tree, $ids] = $this->createTree(['N1a', 'N1b', 'N2', 'N3a', 'N3b']);
        foreach ($ids as $id) {
            $this->assertNotNull($tree->getNode($id));
        }

        // Two groups escalate to N2, which escalates to two groups; N1a also skips a level.
        $this->assertNotNull($tree->link($ids['N1a'], $ids['N2']));
        $this->assertNotNull($tree->link($ids['N1b'], $ids['N2']));
        $this->assertNotNull($tree->link($ids['N2'], $ids['N3a']));
        $this->assertNotNull($tree->link($ids['N2'], $ids['N3b']));
        $this->assertNotNull($tree->link($ids['N1a'], $ids['N3b']));
        $this->assertNull($tree->link($ids['N2'], $ids['N2']));

        $n2 = $tree->getNode($ids['N2']);
        $this->assertNotNull($n2);
        $this->assertSame([$ids['N1a'], $ids['N1b']], $this->getIds($n2->getParents()));
        $this->assertSame([$ids['N3a'], $ids['N3b']], $this->getIds($n2->getChildren()));
        $this->assertTrue($tree->getNode($ids['N1a'])?->isRoot());
        $this->assertFalse($n2->isRoot());

        $this->assertSame([
            $ids['N1a'] => 1,
            $ids['N1b'] => 1,
            $ids['N2']  => 2,
            $ids['N3a'] => 3,
            $ids['N3b'] => 3,
        ], $tree->getLevels());

        // The links of N2, to the groups it escalates to and from the groups escalating to it
        $this->assertSame(
            [sprintf('%d-%d', $ids['N2'], $ids['N3a']), sprintf('%d-%d', $ids['N2'], $ids['N3b'])],
            array_keys($tree->getLinksOf($ids['N2'], 'to')),
        );
        $this->assertSame(
            [sprintf('%d-%d', $ids['N1a'], $ids['N2']), sprintf('%d-%d', $ids['N1b'], $ids['N2'])],
            array_keys($tree->getLinksOf($ids['N2'], 'from')),
        );

        // Removing a group removes its links: N3b stays below N1a only.
        $tree->removeNode($ids['N2']);
        $this->assertNull($tree->getNode($ids['N2']));
        $this->assertSame([sprintf('%d-%d', $ids['N1a'], $ids['N3b'])], array_keys($tree->getLinks()));
        $this->assertSame([], $tree->getNode($ids['N1b'])?->getChildren());
        $this->assertSame(2, $tree->getLevels()[$ids['N3b']]);
        $this->assertSame(1, $tree->getLevels()[$ids['N3a']]);
    }

    public function testCannotLinkToAnAncestor(): void
    {
        $this->login();
        [$tree, $ids] = $this->createTree(['A', 'B', 'C', 'D']);
        $tree->link($ids['A'], $ids['B']);
        $tree->link($ids['B'], $ids['C']);

        $a = $tree->getNode($ids['A']);
        $c = $tree->getNode($ids['C']);
        $this->assertNotNull($a);
        $this->assertNotNull($c);
        $this->assertSame([$ids['A'], $ids['B']], $this->getIds($c->getAncestors()));
        $this->assertSame([$ids['B'], $ids['C']], $this->getIds($a->getDescendants()));
        $this->assertTrue($a->isAncestorOf($c));
        $this->assertFalse($c->isAncestorOf($a));

        // Direct parent, indirect ancestor, itself
        $this->assertFalse($tree->canLink($ids['B'], $ids['A']));
        $this->assertNull($tree->link($ids['B'], $ids['A']));
        $this->assertFalse($tree->canLink($ids['C'], $ids['A']));
        $this->assertNull($tree->link($ids['C'], $ids['A']));
        $this->assertFalse($tree->canLink($ids['A'], $ids['A']));

        // Skipping a level, or going to another branch, is allowed; so is a link already there.
        $this->assertTrue($tree->canLink($ids['A'], $ids['C']));
        $this->assertTrue($tree->canLink($ids['C'], $ids['D']));
        $this->assertTrue($tree->canLink($ids['A'], $ids['B']));
        $this->assertSame([sprintf('%d-%d', $ids['A'], $ids['B']), sprintf('%d-%d', $ids['B'], $ids['C'])], array_keys($tree->getLinks()));

        // A draft making a loop loses the link closing it.
        $state = $tree->toState();
        $state['links'][] = ['from' => $ids['C'], 'to' => $ids['A'], 'type' => LinkStrategyEnum::BASIC->value];
        $copy = EscalationTree::fromState($tree->entities_id, $state);
        $this->assertSame([sprintf('%d-%d', $ids['A'], $ids['B']), sprintf('%d-%d', $ids['B'], $ids['C'])], array_keys($copy->getLinks()));
    }

    public function testLoopAlreadySaved(): void
    {
        $this->login();
        $entities_id = $this->getRootEntityId();
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $entities_id);
        $this->createLinks($entities_id, [
            [$a, $b, LinkStrategyEnum::BASIC],
            [$b, $c, LinkStrategyEnum::BASIC],
            [$c, $a, LinkStrategyEnum::BASIC],
        ]);

        // No root: every group falls back to level 1 instead of looping.
        $tree = EscalationTree::load($entities_id);
        $this->assertSame([$a => 1, $b => 1, $c => 1], $tree->getLevels());
        $this->assertCount(3, $tree->getNode($a)?->getAncestors() ?? []);
    }

    public function testLoadOnlyPlacesTheGroupsOfTheLinksPlaced(): void
    {
        $this->login();
        $entities_id = $this->getRootEntityId();
        ['A' => $a] = $this->createGroups(['A'], $entities_id);
        ['B' => $b] = $this->createGroups(['B'], $entities_id, false, false);
        $this->createLinks($entities_id, [[$a, $b, LinkStrategyEnum::BASIC]]);

        // B cannot be assigned any more: neither its link nor A are placed.
        $tree = EscalationTree::load($entities_id);
        $this->assertNull($tree->getNode($a));
        $this->assertSame([], $tree->getLinks());
    }

    public function testAddingAGroupShowsItsReplicatedLinks(): void
    {
        $this->login();
        [$child_id, $a, $b] = $this->createInheritedLink();

        // Placed again (from a draft forged without it), B gets back A -> B: B cannot escalate to A.
        $tree = EscalationTree::fromState($child_id, ['nodes' => [$a]]);
        $this->assertSame([], $tree->getLinks());
        $tree->addNode($b);
        $this->assertSame([sprintf('%d-%d', $a, $b)], array_keys($tree->getLinks()));
        $this->assertFalse($tree->canLink($b, $a));
    }

    public function testReplicatedLinksHideAnEntityOutOfReach(): void
    {
        $this->login();
        [$child_id, $a, $b] = $this->createInheritedLink();
        $root_id = $this->getRootEntityId();
        $this->assertStringNotContainsString('#', (string) EscalationTree::load($child_id)->getLink(sprintf('%d-%d', $a, $b))?->origin);

        // Without access to the root entity, its name is not shown.
        $this->setEntity($child_id, false);
        $this->assertSame(sprintf('Hidden entity #%d', $root_id), EscalationTree::load($child_id)->getLink(sprintf('%d-%d', $a, $b))?->origin);
    }

    public function testStrategyAndState(): void
    {
        $this->login();
        [$tree, $ids] = $this->createTree(['A', 'B']);
        $key = $tree->link($ids['A'], $ids['B'])?->getKey();
        $this->assertIsString($key);

        $tree->setStrategy($key, LinkStrategyEnum::INHERITED);
        $this->assertSame(LinkStrategyEnum::INHERITED, $tree->getLink($key)?->strategy);
        // A link that is not drawn cannot be chosen.
        $tree->setStrategy($key, LinkStrategyEnum::NONE);
        $this->assertSame(LinkStrategyEnum::INHERITED, $tree->getLink($key)?->strategy);

        $copy = EscalationTree::fromState($tree->entities_id, $tree->toState());
        $this->assertSame($tree->toState(), $copy->toState());
        $this->assertSame([$ids['B']], $this->getIds($copy->getNode($ids['A'])?->getChildren() ?? []));
    }

    public function testRemovingAGroupRemovesTheReplicatedLinksItsLinksReplace(): void
    {
        $this->login();
        [$child_id, $a, $b] = $this->createInheritedLink();
        // In the child entity, A -> B is replaced by a link of its own.
        $this->createLinks($child_id, [[$a, $b, LinkStrategyEnum::BASIC]]);

        $tree = EscalationTree::load($child_id);
        $tree->removeNode($a);
        $this->assertNull($tree->getLink(sprintf('%d-%d', $a, $b)));
        $this->assertSame([sprintf('%d-%d', $a, $b)], $tree->toState()['removed']);

        // Saved, the replicated link does not come back with the group.
        $this->assertNull($tree->save());
        $tree = EscalationTree::load($child_id);
        $this->assertNull($tree->getNode($a));
        $this->assertSame([], $tree->getLinks());
    }

    public function testFromStateIgnoresForgedLinks(): void
    {
        $this->login();
        [$child_id, $a, $b] = $this->createInheritedLink();

        // The replicated link, removed, and given as a link of the entity, twice
        $tree = EscalationTree::fromState($child_id, [
            'nodes'   => [$a, $b],
            'links'   => array_fill(0, 3, ['from' => $a, 'to' => $b, 'type' => LinkStrategyEnum::BASIC->value]),
            'removed' => [sprintf('%d-%d', $a, $b)],
        ]);
        $this->assertSame([], $tree->toState()['links']);
        $this->assertSame([sprintf('%d-%d', $a, $b)], $tree->toState()['removed']);
        $this->assertSame([], $tree->getLinks());

        // A link given several times is read once.
        $tree = EscalationTree::fromState($child_id, [
            'nodes'   => [$a, $b],
            'links'   => array_fill(0, 3, ['from' => $b, 'to' => $a, 'type' => LinkStrategyEnum::BASIC->value]),
            'removed' => [sprintf('%d-%d', $a, $b)],
        ]);
        $this->assertSame([['from' => $b, 'to' => $a, 'type' => LinkStrategyEnum::BASIC->value]], $tree->toState()['links']);
    }

    public function testSaveRefusesALoopInASubEntity(): void
    {
        $this->login();
        [$child_id, $a, $b] = $this->createInheritedLink();
        // B -> A in a sub-entity of the child entity
        $grandchild_id = $this->createChildEntity('Grandchild', $child_id);
        $this->createLinks($grandchild_id, [[$b, $a, LinkStrategyEnum::BASIC]]);
        // The root entity cannot replicate A -> B again in the child entity, which removed it.
        $this->createLinks($child_id, [[$a, $b, LinkStrategyEnum::NONE]]);
        $this->assertNull(Group_Link::findLoop($this->getRootEntityId()));

        // Restoring it in the child entity would make a loop in the grandchild one: nothing is saved.
        $tree = EscalationTree::load($child_id, [$a, $b]);
        $this->assertTrue($tree->link($a, $b)?->isReplicated());
        $loop = $tree->save();
        $this->assertSame($grandchild_id, $loop['entities_id'] ?? null);
        $this->assertSame([sprintf('%d-%d', $a, $b) => LinkStrategyEnum::NONE->value], $this->getEntityLinks($child_id));
    }

    public function testEscalationLink(): void
    {
        $row  = ['groups_id_source' => '3', 'groups_id_destination' => '5', 'link_type' => LinkStrategyEnum::INHERITED->value];
        $link = EscalationLink::fromRow($row, 'Root');
        $this->assertSame([3, 5, LinkStrategyEnum::INHERITED], [$link->source, $link->destination, $link->strategy]);
        $this->assertSame('3-5', $link->getKey());
        $this->assertTrue($link->isReplicated());
        $this->assertFalse(EscalationLink::fromRow($row)->isReplicated());
        $this->assertSame(['groups_id_source' => 3, 'groups_id_destination' => 5, 'link_type' => LinkStrategyEnum::INHERITED->value], $link->toRow());

        // An unknown stored strategy reads as the default one.
        $this->assertSame(LinkStrategyEnum::NONE, EscalationLink::fromRow(['link_type' => 'unknown'] + $row)->strategy);

        // Ends of a link of group 3 with group 5, both ways
        $this->assertSame(['to', 'from'], EscalationLink::DIRECTIONS);
    }
}
