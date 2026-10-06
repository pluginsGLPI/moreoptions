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

use DBmysql;
use GlpiPlugin\Moreoptions\Group_Link;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use Throwable;

/**
 * Escalation hierarchy between the groups of an entity: the groups placed in it (its nodes) and
 * the links between them. A group can escalate to several groups, and receive escalations from
 * several groups.
 *
 * The links of the tree are the links of the entity, plus the ones replicated from its parent
 * entities (see Group_Link::getInheritedLinks()). A replicated link cannot be changed, only
 * removed from the entity: it is then saved as a LinkStrategyEnum::NONE link.
 *
 * Nothing is saved until save() is called.
 */
final class EscalationTree
{
    /**
     * Links read from a draft at most (see fromState()): far more than an escalation tree has.
     */
    private const MAX_LINKS = 2000;

    /**
     * Placed groups, in placement order.
     *
     * @var array<int, GroupNode>
     */
    private array $nodes = [];

    /**
     * Links of the entity, by key.
     *
     * @var array<string, EscalationLink>
     */
    private array $links = [];

    /**
     * Keys of the replicated links removed from the entity.
     *
     * @var array<string, true>
     */
    private array $removed = [];

    /**
     * Whether the tree was reset (see reset()): its saved links are all replaced.
     */
    private bool $reset = false;

    /**
     * Version of the saved links the tree was made from (see Group_Link::getVersion()).
     */
    private string $version = '';

    /**
     * Cache of getLinks(): the links shown in the tree, computed again after each change (null).
     *
     * @var array<string, EscalationLink>|null
     */
    private ?array $links_cache = null;

    /**
     * Cache of getGraph(), computed again after each change (null).
     */
    private ?EscalationGraph $graph = null;

    /**
     * @param array<int, string>            $linkable_groups Groups that can be linked in the entity: names, by id. The replicated links are between these groups too.
     * @param array<string, EscalationLink> $replicated      Links replicated from the parent entities, by key, including the removed ones
     */
    private function __construct(
        public readonly int $entities_id,
        private readonly array $linkable_groups,
        private readonly array $replicated,
    ) {}

    /**
     * The tree as saved: the groups of the links applying in the entity.
     *
     * @param list<int> $first_groups Groups to place first, even without link
     */
    public static function load(int $entities_id, array $first_groups = []): self
    {
        $tree = self::forEntity($entities_id);
        foreach ($first_groups as $id) {
            $tree->addNode($id);
        }

        $rows          = Group_Link::getRowsOfEntity($entities_id);
        $tree->version = Group_Link::getVersion($rows);
        foreach ($rows as $row) {
            $link = EscalationLink::fromRow($row);
            $key  = $link->getKey();
            if ($link->strategy === LinkStrategyEnum::NONE) {
                if (isset($tree->replicated[$key])) {
                    $tree->removed[$key] = true;
                }
            } elseif ($tree->isLinkable($link->source) && $tree->isLinkable($link->destination)) {
                // Both groups or none: a group is not placed for a link that is not.
                $tree->addNode($link->source);
                $tree->addNode($link->destination);
                $tree->links[$key] = $link;
            }
        }

        foreach ($tree->getReplicatedLinksApplying() as $link) {
            $tree->addNode($link->source);
            $tree->addNode($link->destination);
        }
        $tree->changed();

        return $tree;
    }

    /**
     * The tree being edited, as saved by toState(). Everything is checked again: unknown groups,
     * links or strategies, links making a loop, and links removed, are ignored.
     *
     * @param array<mixed> $state
     */
    public static function fromState(int $entities_id, array $state): self
    {
        $tree          = self::forEntity($entities_id);
        $tree->version = is_string($state['version'] ?? null) ? $state['version'] : '';
        $tree->reset   = ($state['reset'] ?? false) === true;

        foreach ((array) ($state['nodes'] ?? []) as $id) {
            if (is_numeric($id)) {
                $tree->addNode((int) $id);
            }
        }
        foreach ((array) ($state['removed'] ?? []) as $key) {
            if (is_string($key) && isset($tree->replicated[$key])) {
                $tree->removed[$key] = true;
            }
        }
        $tree->changed();

        // Each link added is added to the graph too, for the next ones to be checked against it.
        $graph = $tree->getGraph();
        foreach (array_slice((array) ($state['links'] ?? []), 0, self::MAX_LINKS) as $link) {
            if (!is_array($link)) {
                continue;
            }
            [$source, $destination] = [(int) ($link['from'] ?? 0), (int) ($link['to'] ?? 0)];
            $key      = EscalationLink::key($source, $destination);
            $strategy = is_string($link['type'] ?? null) ? LinkStrategyEnum::tryFromDrawn($link['type']) : null;
            // A removed replicated link is restored by link() only: it is not a link of the entity.
            if (
                $strategy !== null && !isset($tree->links[$key]) && !isset($tree->removed[$key])
                && $tree->canLink($source, $destination)
            ) {
                $tree->links[$key] = new EscalationLink($source, $destination, $strategy);
                $graph->addEdge($source, $destination);
            }
        }
        // The links shown so far stay valid for canLink(): the links added are checked by key.
        $tree->links_cache = null;

        return $tree;
    }

    /**
     * An empty tree for the entity, with the groups that can be linked in it and the links
     * replicated from its parent entities.
     */
    private static function forEntity(int $entities_id): self
    {
        $entity_names = [];
        $replicated   = [];
        foreach (Group_Link::getInheritedLinks($entities_id) as $link) {
            $origin = (int) $link->entities_id;
            $entity_names[$origin] ??= Group_Link::getEntityName($origin);
            $replicated[$link->getKey()] = $link->withOrigin($entity_names[$origin]);
        }

        return new self($entities_id, Group_Link::getGroupsForEntity($entities_id), $replicated);
    }

    /**
     * The tree being edited, as read by fromState().
     *
     * @return array{version: string, reset: bool, nodes: list<int>, links: list<array{from: int, to: int, type: string}>, removed: list<string>}
     */
    public function toState(): array
    {
        $links = [];
        foreach ($this->links as $link) {
            $links[] = ['from' => $link->source, 'to' => $link->destination, 'type' => $link->strategy->value];
        }

        return [
            'version' => $this->version,
            'reset'   => $this->reset,
            'nodes'   => array_keys($this->nodes),
            'links'   => $links,
            'removed' => array_keys($this->removed),
        ];
    }

    /**
     * Saves the links of the entity, plus a LinkStrategyEnum::NONE link for each replicated link
     * removed. The ones that apply again (restored) lose their LinkStrategyEnum::NONE link.
     *
     * Nothing is saved if the links would make a loop in a sub-entity: with the links of the
     * sub-entity, or the ones it gets from another parent entity.
     *
     * @return array{entities_id: int, groups: list<int>}|null The loop preventing the save, see Group_Link::findLoop()
     */
    public function save(): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        $links = array_values($this->links);
        foreach (array_keys($this->removed) as $key) {
            $links[] = $this->replicated[$key]->withStrategy(LinkStrategyEnum::NONE);
        }

        $DB->beginTransaction();
        try {
            Group_Link::saveLinksForEntity(
                $this->entities_id,
                $links,
                array_keys(array_diff_key($this->replicated, $this->removed)),
                $this->reset,
                $this->linkable_groups,
                array_keys($this->replicated),
            );
            $loop = Group_Link::findLoop($this->entities_id);
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        if ($loop !== null) {
            $DB->rollBack();
            return $loop;
        }
        $DB->commit();

        return null;
    }

    /**
     * Whether the saved links are still the ones the tree was made from: nobody saved others since.
     */
    public function isUpToDate(): bool
    {
        return hash_equals(Group_Link::getVersion(Group_Link::getRowsOfEntity($this->entities_id)), $this->version);
    }

    /**
     * Resets the tree: removes all the links of the entity, which gets back the links replicated
     * from its parent entities, removed or replaced in it until now. Only the groups of these
     * links stay placed. In the root entity, no link is left.
     */
    public function reset(): void
    {
        $this->links   = [];
        $this->removed = [];
        $this->reset   = true;
        $placed        = [];
        foreach ($this->replicated as $link) {
            $placed[$link->source]      = true;
            $placed[$link->destination] = true;
        }
        $this->nodes = array_intersect_key($this->nodes, $placed);
        foreach (array_keys($placed) as $id) {
            $this->addNode($id);
        }
        $this->changed();
    }

    /**
     * Whether resetting the tree would change it: it has links of the entity, or replicated links
     * removed from it.
     */
    public function canReset(): bool
    {
        return $this->links !== [] || $this->removed !== [];
    }

    /**
     * @return list<GroupNode> In placement order
     */
    public function getNodes(): array
    {
        return array_values($this->nodes);
    }

    public function getNode(int $id): ?GroupNode
    {
        return $this->nodes[$id] ?? null;
    }

    public function hasNode(int $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /**
     * Whether the group can be linked in the entity: assignable there (see Group_Link::getGroupsForEntity()).
     */
    public function isLinkable(int $id): bool
    {
        return isset($this->linkable_groups[$id]);
    }

    /**
     * Groups that can be linked in the entity and are not placed yet: names, by id.
     *
     * @return array<int, string>
     */
    public function getUnplacedGroups(): array
    {
        return array_diff_key($this->linkable_groups, $this->nodes);
    }

    /**
     * Places a group that can be linked in the entity.
     *
     * @return GroupNode|null The node of the group, null for a group that cannot be placed
     */
    public function addNode(int $id): ?GroupNode
    {
        if (!$this->hasNode($id) && $this->isLinkable($id)) {
            $this->nodes[$id] = new GroupNode($id, $this->linkable_groups[$id], $this);
            $this->changed();
        }

        return $this->nodes[$id] ?? null;
    }

    /**
     * Removes a group, and its links: its links of the entity are deleted, and its replicated
     * links removed from the entity, including the ones its links of the entity replaced.
     */
    public function removeNode(int $id): void
    {
        foreach (array_keys($this->links + $this->replicated) as $key) {
            $link = $this->links[$key] ?? $this->replicated[$key];
            if ($link->source === $id || $link->destination === $id) {
                unset($this->links[$key]);
                if (isset($this->replicated[$key])) {
                    $this->removed[$key] = true;
                }
            }
        }
        unset($this->nodes[$id]);
        $this->changed();
    }

    /**
     * Links shown in the tree: the links of the entity, and the replicated ones still applying.
     *
     * @return array<string, EscalationLink> By key
     */
    public function getLinks(): array
    {
        if ($this->links_cache === null) {
            $this->links_cache = $this->links;
            foreach ($this->getReplicatedLinksApplying() as $key => $link) {
                if ($this->hasNode($link->source) && $this->hasNode($link->destination)) {
                    $this->links_cache[$key] = $link;
                }
            }
        }

        return $this->links_cache;
    }

    /**
     * Links from the group (`to`: to the groups it escalates to), or to the group (`from`: from
     * the groups escalating to it).
     *
     * @return array<string, EscalationLink> By key
     */
    public function getLinksOf(int $id, string $direction): array
    {
        $links = [];
        foreach ($this->getLinks() as $key => $link) {
            if (($direction === 'to' ? $link->source : $link->destination) === $id) {
                $links[$key] = $link;
            }
        }

        return $links;
    }

    public function getLink(string $key): ?EscalationLink
    {
        return $this->getLinks()[$key] ?? null;
    }

    /**
     * The groups placed and the links shown, as a graph: their parents, children, levels...
     */
    public function getGraph(): EscalationGraph
    {
        return $this->graph ??= EscalationGraph::fromLinks(array_keys($this->nodes), $this->getLinks());
    }

    /**
     * Whether the source group can be linked to the destination group: both are placed, and the
     * link would not make a loop (the destination does not escalate to the source).
     */
    public function canLink(int $source, int $destination): bool
    {
        if (!$this->hasNode($source) || !$this->hasNode($destination) || $source === $destination) {
            return false;
        }
        if ($this->getLink(EscalationLink::key($source, $destination)) !== null) {
            return true;
        }

        return !$this->nodes[$destination]->isAncestorOf($this->nodes[$source]);
    }

    /**
     * Groups the given group can be linked to (`to`) or from (`from`): the placed groups it is not
     * linked with yet, and that would not make a loop, then the groups not placed yet.
     *
     * @return array<int, string> Names, by group id
     */
    public function getLinkCandidates(int $id, string $direction): array
    {
        // Linked the other way round, they would make a loop: escalating to a group the given
        // one escalates from, directly or not, or the reverse.
        $node      = $this->nodes[$id] ?? null;
        $forbidden = $node === null ? [] : ($direction === 'to' ? $node->getAncestors() : $node->getDescendants());

        $candidates = [];
        foreach ($this->nodes as $other => $node) {
            [$from, $to] = $direction === 'to' ? [$id, $other] : [$other, $id];
            if ($other !== $id && !isset($forbidden[$other]) && $this->getLink(EscalationLink::key($from, $to)) === null) {
                $candidates[$other] = $node->name;
            }
        }

        return $candidates + $this->getUnplacedGroups();
    }

    /**
     * Links the source group to the destination group: restores the replicated link removed
     * between them, if any, or adds a LinkStrategyEnum::BASIC one.
     *
     * @return EscalationLink|null The link between them, null if they cannot be linked (see canLink())
     */
    public function link(int $source, int $destination): ?EscalationLink
    {
        if (!$this->canLink($source, $destination)) {
            return null;
        }

        $key = EscalationLink::key($source, $destination);
        if ($this->getLink($key) === null) {
            if (isset($this->removed[$key])) {
                unset($this->removed[$key]);
            } else {
                $this->links[$key] = new EscalationLink($source, $destination, LinkStrategyEnum::BASIC);
            }
            $this->changed();
        }

        return $this->getLink($key);
    }

    /**
     * Removes a link: deletes a link of the entity, or removes a replicated one from the entity.
     * A link of the entity replacing a replicated one gives way to it.
     */
    public function unlink(string $key): void
    {
        if (isset($this->links[$key])) {
            unset($this->links[$key]);
        } elseif (isset($this->replicated[$key])) {
            $this->removed[$key] = true;
        }
        $this->changed();
    }

    /**
     * Changes the strategy of a link of the entity. A replicated link cannot be changed.
     */
    public function setStrategy(string $key, LinkStrategyEnum $strategy): void
    {
        if (isset($this->links[$key]) && $strategy->getStrategy()->isDrawn()) {
            $this->links[$key] = $this->links[$key]->withStrategy($strategy);
            $this->changed();
        }
    }

    /**
     * Level of each group: 1 for the groups nobody escalates to, then the longest path from them.
     *
     * @return array<int, int> By group id, in placement order
     */
    public function getLevels(): array
    {
        return $this->getGraph()->getLevels();
    }

    /**
     * Replicated links neither removed nor replaced by a link of the entity.
     *
     * @return array<string, EscalationLink>
     */
    private function getReplicatedLinksApplying(): array
    {
        return array_diff_key($this->replicated, $this->removed, $this->links);
    }

    /**
     * Forgets what is computed from the groups and links, after a change.
     */
    private function changed(): void
    {
        $this->links_cache = null;
        $this->graph       = null;
    }
}
