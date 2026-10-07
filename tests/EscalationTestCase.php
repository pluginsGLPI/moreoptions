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

namespace GlpiPlugin\Moreoptions\Tests;

use Entity;
use GlpiPlugin\Moreoptions\EscalationTree\EscalationLink;
use GlpiPlugin\Moreoptions\EscalationTree\TreeEditor;
use GlpiPlugin\Moreoptions\Group_Link;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use Group;

use function Safe\ob_get_clean;
use function Safe\ob_start;

/**
 * Fixtures shared by the tests of the escalation links: entities, groups and links between them.
 */
abstract class EscalationTestCase extends MoreOptionsTestCase
{
    protected function getRootEntityId(): int
    {
        $root_id = $this->getTestRootEntity(true);
        $this->assertIsInt($root_id);

        return $root_id;
    }

    protected function createChildEntity(string $name, int $parent): int
    {
        return $this->createItem(Entity::class, ['name' => $name, 'entities_id' => $parent], ['name'])->getID();
    }

    /**
     * @param list<string> $names
     * @return array<string, int> Ids of the created groups, by name
     */
    protected function createGroups(array $names, int $entities_id, bool $recursive = false, bool $assignable = true): array
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[$name] = $this->createItem(Group::class, [
                'name'         => $name,
                'entities_id'  => $entities_id,
                'is_recursive' => (int) $recursive,
                'is_assign'    => (int) $assignable,
            ])->getID();
        }

        return $ids;
    }

    /**
     * Stores links of an entity, as they would be saved (the rights on the links are not checked).
     *
     * @param list<array{int, int, LinkStrategyEnum}> $links Source, destination and strategy of each link
     */
    protected function createLinks(int $entities_id, array $links): void
    {
        $inputs = [];
        foreach ($links as $link) {
            $inputs[] = ['entities_id' => $entities_id] + (new EscalationLink(...$link))->toRow();
        }

        $this->createItems(Group_Link::class, $inputs);
    }

    /**
     * @return array<string, string> Stored link types of the entity, by link key (see EscalationLink::key())
     */
    protected function getEntityLinks(int $entities_id): array
    {
        $links = [];
        foreach ((new Group_Link())->find(['entities_id' => $entities_id]) as $row) {
            $links[EscalationLink::fromRow($row)->getKey()] = $row['link_type'];
        }

        ksort($links);

        return $links;
    }

    /**
     * A child entity of the root entity, where two recursive groups of the root entity are linked
     * by an inherited link of the root entity, A -> B.
     *
     * @return array{int, int, int} Child entity, A, B
     */
    protected function createInheritedLink(): array
    {
        $root_id  = $this->getRootEntityId();
        $child_id = $this->createChildEntity('Child', $root_id);
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], $root_id, true);
        $this->createLinks($root_id, [[$a, $b, LinkStrategyEnum::INHERITED]]);

        return [$child_id, $a, $b];
    }

    /**
     * The HTML of the editor.
     */
    protected function render(TreeEditor $editor): string
    {
        ob_start();
        $editor->display();

        return ob_get_clean();
    }
}
