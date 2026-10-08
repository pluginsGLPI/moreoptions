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
 * A group placed in an escalation tree, with the groups it escalates to (its children) and the
 * groups escalating to it (its parents). A group can have several parents.
 *
 * Its links are not kept by the node: they are read from its tree (see EscalationTree::getGraph()),
 * so that they always are the ones shown in it.
 */
final readonly class GroupNode
{
    /**
     * @internal Created by EscalationTree only
     */
    public function __construct(
        public int $id,
        public string $name,
        private EscalationTree $tree,
    ) {}

    /**
     * Groups escalating to this one directly.
     *
     * @return list<GroupNode>
     */
    public function getParents(): array
    {
        return $this->toNodes($this->tree->getGraph()->getParents($this->id));
    }

    /**
     * Groups this one escalates to directly.
     *
     * @return list<GroupNode>
     */
    public function getChildren(): array
    {
        return $this->toNodes($this->tree->getGraph()->getChildren($this->id));
    }

    /**
     * Groups escalating to this one, directly or not.
     *
     * @return array<int, GroupNode> By group id
     */
    public function getAncestors(): array
    {
        return $this->toNodesById($this->tree->getGraph()->getAncestors($this->id));
    }

    /**
     * Groups this one escalates to, directly or not.
     *
     * @return array<int, GroupNode> By group id
     */
    public function getDescendants(): array
    {
        return $this->toNodesById($this->tree->getGraph()->getDescendants($this->id));
    }

    /**
     * Whether this group escalates to the given one, directly or not.
     */
    public function isAncestorOf(GroupNode $node): bool
    {
        return isset($this->tree->getGraph()->getAncestors($node->id)[$this->id]);
    }

    /**
     * Whether no group escalates to this one: a first level of the tree.
     */
    public function isRoot(): bool
    {
        return $this->tree->getGraph()->getParents($this->id) === [];
    }

    /**
     * @param list<int> $ids
     * @return list<GroupNode>
     */
    private function toNodes(array $ids): array
    {
        return array_values($this->toNodesById(array_fill_keys($ids, true)));
    }

    /**
     * @param array<int, true> $ids
     * @return array<int, GroupNode>
     */
    private function toNodesById(array $ids): array
    {
        $nodes = [];
        foreach (array_keys($ids) as $id) {
            $node = $this->tree->getNode($id);
            if ($node instanceof self) {
                $nodes[$id] = $node;
            }
        }

        return $nodes;
    }
}
