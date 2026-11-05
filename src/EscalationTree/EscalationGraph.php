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

/**
 * Directed graph of escalation between groups: each edge goes from a group to a group it
 * escalates to. Holds the graph algorithms of the escalation: the levels of the groups, the
 * ancestors and descendants of a group, and the detection of a loop.
 */
final class EscalationGraph
{
    /**
     * Groups each group escalates to, in the order of the edges.
     *
     * @var array<int, list<int>>
     */
    private array $children = [];

    /**
     * Groups escalating to each group, in the order of the edges.
     *
     * @var array<int, list<int>>
     */
    private array $parents = [];

    /**
     * @param list<int> $nodes Groups of the graph, in order: the groups of the edges are added after them
     * @param iterable<array{int, int}> $edges Source and destination of each edge
     */
    public function __construct(array $nodes = [], iterable $edges = [])
    {
        foreach ($nodes as $node) {
            $this->addNode($node);
        }

        foreach ($edges as [$source, $destination]) {
            $this->addEdge($source, $destination);
        }
    }

    /**
     * @param list<int>                   $nodes
     * @param iterable<EscalationLink>    $links
     */
    public static function fromLinks(array $nodes, iterable $links): self
    {
        $edges = [];
        foreach ($links as $link) {
            $edges[] = [$link->source, $link->destination];
        }

        return new self($nodes, $edges);
    }

    public function addNode(int $node): void
    {
        $this->children[$node] ??= [];
        $this->parents[$node]  ??= [];
    }

    /**
     * Adds an edge, unless already there.
     */
    public function addEdge(int $source, int $destination): void
    {
        $this->addNode($source);
        $this->addNode($destination);
        if (!in_array($destination, $this->children[$source], true)) {
            $this->children[$source][]   = $destination;
            $this->parents[$destination][] = $source;
        }
    }

    /**
     * @return list<int>
     */
    public function getChildren(int $node): array
    {
        return $this->children[$node] ?? [];
    }

    /**
     * @return list<int>
     */
    public function getParents(int $node): array
    {
        return $this->parents[$node] ?? [];
    }

    /**
     * Groups escalating to the given one, directly or not.
     *
     * @return array<int, true> By group id
     */
    public function getAncestors(int $node): array
    {
        return $this->reach($node, $this->parents);
    }

    /**
     * Groups the given one escalates to, directly or not.
     *
     * @return array<int, true> By group id
     */
    public function getDescendants(int $node): array
    {
        return $this->reach($node, $this->children);
    }

    /**
     * Level of each group: 1 for the groups nobody escalates to, then the longest path from them.
     * The groups of a loop, and the ones after it, are at level 1.
     *
     * @return array<int, int> By group id, in the order of the groups
     */
    public function getLevels(): array
    {
        $levels = array_fill_keys(array_keys($this->children), 1);
        $sorted = array_flip($this->sortTopologically());
        foreach (array_keys($sorted) as $node) {
            foreach ($this->children[$node] as $child) {
                // The groups after a loop stay at level 1: some of their parents are not sorted.
                if (isset($sorted[$child])) {
                    $levels[$child] = max($levels[$child], $levels[$node] + 1);
                }
            }
        }

        return $levels;
    }

    /**
     * A loop of the graph: groups escalating, directly or not, to themselves.
     *
     * @return list<int>|null The groups of the first loop found, in the order of the escalation
     */
    public function findCycle(): ?array
    {
        $sorted = array_flip($this->sortTopologically());
        $left   = array_diff_key($this->children, $sorted);
        if ($left === []) {
            return null;
        }

        // Each group left has a parent left: going up from one of them, a group comes back.
        $path     = [];
        $position = [];
        $node     = array_key_first($left);
        while (!isset($position[$node])) {
            $position[$node] = count($path);
            $path[]          = $node;
            foreach ($this->parents[$node] as $parent) {
                if (isset($left[$parent])) {
                    $node = $parent;
                    break;
                }
            }
        }

        return array_reverse(array_slice($path, $position[$node]));
    }

    /**
     * Groups in an order where each group comes after the groups escalating to it (Kahn's
     * algorithm). The groups of a loop, and the ones after it, are left out.
     *
     * @return list<int>
     */
    private function sortTopologically(): array
    {
        // Parents of each group not sorted yet: a group is sorted once all its parents are.
        $remaining = array_map(count(...), $this->parents);
        $sorted    = array_keys($remaining, 0, true);
        // The list grows while it is read: each group sorted may let its children be sorted.
        for ($i = 0; isset($sorted[$i]); $i++) {
            foreach ($this->children[$sorted[$i]] as $child) {
                if (--$remaining[$child] === 0) {
                    $sorted[] = $child;
                }
            }
        }

        return $sorted;
    }

    /**
     * Groups reached from the given one, following the given edges.
     *
     * @param array<int, list<int>> $edges
     * @return array<int, true>
     */
    private function reach(int $node, array $edges): array
    {
        $reached = [];
        $stack   = $edges[$node] ?? [];
        while ($stack !== []) {
            $next = array_pop($stack);
            if (!isset($reached[$next])) {
                $reached[$next] = true;
                array_push($stack, ...$edges[$next]);
            }
        }

        return $reached;
    }
}
