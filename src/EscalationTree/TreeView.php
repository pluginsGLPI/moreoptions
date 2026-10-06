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

namespace GlpiPlugin\Moreoptions\EscalationTree;

use Dropdown;
use Entity;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use Group;

/**
 * What the editor of an escalation tree shows (see TreeEditor): the variables of
 * templates/components/group_link/editor.html.twig.
 */
final class TreeView
{
    /**
     * @var array<int, int> Level of each group
     */
    private readonly array $levels;

    public function __construct(
        private readonly Group $group,
        private readonly EscalationTree $tree,
        private readonly bool $canedit,
        private readonly bool $vertical,
        private readonly LinkStrategyEnum $drawing_strategy,
        private readonly ?int $selected_node,
        private readonly ?string $selected_link,
        private readonly string $query,
        private readonly ?string $error,
        private readonly ?int $scroll_to,
    ) {
        $this->levels = $tree->getLevels();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplateVariables(): array
    {
        return [
            'group'            => $this->group,
            'entity_name'      => Dropdown::getDropdownName(Entity::getTable(), $this->tree->entities_id),
            'canedit'          => $this->canedit,
            'can_reset'        => $this->canedit && $this->tree->canReset(),
            // A child entity gets back the links of its parent entities when reset
            'entity_is_root'   => $this->tree->entities_id === 0,
            'orientation'      => $this->vertical ? 'vertical' : 'horizontal',
            'drawing_strategy' => $this->drawing_strategy->value,
            'elements'         => $this->getGraphElements(),
            'selected_node'    => $this->describeSelectedNode(),
            'selected_link'    => $this->describeSelectedLink(),
            'placed'           => $this->getPlacedGroups(),
            'pool'             => $this->getPool(),
            'query'            => $this->query,
            'error'            => $this->error,
            'has_selection'    => $this->selected_node !== null || $this->selected_link !== null,
            'scroll_to'        => $this->scroll_to,
            'strategies'       => array_map(self::describeStrategy(...), LinkStrategyEnum::getDrawnCases()),
        ];
    }

    /**
     * A strategy a link can have, for the toolbar, the legend and the panels.
     *
     * @return array{value: string, label: string, description: string, color: string|null, replicable: bool}
     */
    private static function describeStrategy(LinkStrategyEnum $strategy): array
    {
        return [
            'value'       => $strategy->value,
            'label'       => $strategy->getStrategy()->getLabel(),
            'description' => $strategy->getStrategy()->getDescription(),
            'color'       => $strategy->getStrategy()->getColor(),
            'replicable'  => $strategy->getStrategy()->appliesToSubEntities(),
        ];
    }

    /**
     * Elements of the graph, for Cytoscape.js (see public/js/escalation_graph.js): positions are
     * the centers of the groups, links follow routes never crossing a group, colors are CSS colors
     * resolved in the page.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function getGraphElements(): array
    {
        $layout = new TreeLayout($this->tree, $this->levels, $this->vertical);

        $nodes   = [];
        $centers = [];
        foreach ($this->tree->getNodes() as $node) {
            $centers[$node->id] = $layout->getPosition($node);
            $nodes[] = [
                // The level is shown as a badge on the group
                'data'     => ['id' => (string) $node->id, 'label' => $node->name, 'level' => $this->levels[$node->id]],
                'position' => $centers[$node->id],
                'classes'  => self::classes([
                    'selected' => $this->selected_node === $node->id,
                    'current'  => $this->group->getID() === $node->id,
                ]),
            ];
        }

        $edges  = [];
        $routes = $layout->getRoutes($this->tree->getLinks());
        foreach ($this->tree->getLinks() as $key => $link) {
            $edges[] = [
                'data'    => [
                    'id'         => $key,
                    'source'     => (string) $link->source,
                    'target'     => (string) $link->destination,
                    // Points where it turns, around the groups, and where it starts and ends
                    // from the center of its groups (see TreeLayout::getRoutes())
                    'route'      => $routes[$key],
                    'endpoints'  => [
                        'source' => self::offset($routes[$key]['from'], $centers[$link->source]),
                        'target' => self::offset($routes[$key]['to'], $centers[$link->destination]),
                    ],
                    'replicated' => $link->isReplicated(),
                    // The context menu only offers the other strategies
                    'type'       => $link->strategy->value,
                    'color'      => $link->strategy->getStrategy()->getColor() ?? 'currentColor',
                ],
                'classes' => self::classes(['selected' => $this->selected_link === $key]),
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * The selected group, for its panel: all its links, to the groups it escalates to and from
     * the ones escalating to it, and the groups its link forms offer.
     *
     * @return array<string, mixed>|null
     */
    private function describeSelectedNode(): ?array
    {
        $node = $this->selected_node !== null ? $this->tree->getNode($this->selected_node) : null;
        if ($node === null) {
            return null;
        }

        $panel = [
            'id'      => $node->id,
            'name'    => $node->name,
            'level'   => $this->levels[$node->id],
        ];
        foreach (EscalationLink::DIRECTIONS as $direction) {
            $panel[$direction] = array_values(array_map($this->describeLink(...), $this->tree->getLinksOf($node->id, $direction)));
            $panel['candidates_' . $direction] = $this->tree->getLinkCandidates($node->id, $direction);
        }

        return $panel;
    }

    /**
     * The selected link, for its panel.
     *
     * @return array<string, mixed>|null
     */
    private function describeSelectedLink(): ?array
    {
        $link = $this->selected_link !== null ? $this->tree->getLink($this->selected_link) : null;

        return $link !== null ? $this->describeLink($link) : null;
    }

    /**
     * A link, as shown in the panels: its groups, its strategy, the entity it is replicated from.
     *
     * @return array<string, mixed>
     */
    private function describeLink(EscalationLink $link): array
    {
        return [
            'key'         => $link->getKey(),
            'from_id'     => $link->source,
            'from_name'   => $this->tree->getNode($link->source)?->name,
            'to_id'       => $link->destination,
            'to_name'     => $this->tree->getNode($link->destination)?->name,
            'type'        => $link->strategy->value,
            'label'       => $link->strategy->getStrategy()->getLabel(),
            'color'       => $link->strategy->getStrategy()->getColor(),
            'replicated'  => $link->isReplicated(),
            'entity_name' => $link->origin ?? '',
        ];
    }

    /**
     * The groups of the graph, for the list of the side panel: they can be selected from it with
     * the keyboard. By level, then by name.
     *
     * @return list<array{id: int, name: string, level: int, matches: bool}>
     */
    private function getPlacedGroups(): array
    {
        $placed = [];
        foreach ($this->tree->getNodes() as $node) {
            $placed[] = [
                'id'      => $node->id,
                'name'    => $node->name,
                'level'   => $this->levels[$node->id],
                'matches' => $this->matches($node->name),
            ];
        }
        usort($placed, self::compareByLevelAndName(...));

        return $placed;
    }

    /**
     * @param array{level: int, name: string} $a
     * @param array{level: int, name: string} $b
     */
    private static function compareByLevelAndName(array $a, array $b): int
    {
        return [$a['level'], $a['name']] <=> [$b['level'], $b['name']];
    }

    /**
     * The groups that can still be placed, for the list of the side panel.
     *
     * @return list<array{id: int, name: string, matches: bool}>
     */
    private function getPool(): array
    {
        $pool = [];
        foreach ($this->tree->getUnplacedGroups() as $id => $name) {
            $pool[] = ['id' => $id, 'name' => $name, 'matches' => $this->matches($name)];
        }

        return $pool;
    }

    /** Whether a group matches the search of the side panel */
    private function matches(string $name): bool
    {
        return $this->query === '' || mb_stripos($name, $this->query) !== false;
    }

    /**
     * Position of a point from the center of a group, as Cytoscape wants the ends of a link.
     *
     * @param array{x: int, y: int} $point
     * @param array{x: int, y: int} $center
     */
    private static function offset(array $point, array $center): string
    {
        return sprintf('%dpx %dpx', $point['x'] - $center['x'], $point['y'] - $center['y']);
    }

    /**
     * @param array<string, bool> $classes
     */
    private static function classes(array $classes): string
    {
        return implode(' ', array_keys(array_filter($classes)));
    }
}
