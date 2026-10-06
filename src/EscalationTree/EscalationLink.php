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

use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;

/**
 * A link of an escalation tree: the source group escalates to the destination group. A link is
 * either one of the entity of the tree, or replicated from a parent entity (see
 * LinkStrategyEnum::INHERITED).
 */
final class EscalationLink
{
    /**
     * Directions of a link from a group: to the group it escalates to, from the group escalating to it.
     */
    public const DIRECTIONS = ['to', 'from'];

    /**
     * @param string|null $origin      Name of the parent entity the link is replicated from, null for a link of the entity
     * @param int|null    $entities_id Entity the link is stored in, null for a link not stored yet
     */
    public function __construct(
        public readonly int $source,
        public readonly int $destination,
        public readonly LinkStrategyEnum $strategy,
        public readonly ?string $origin = null,
        public readonly ?int $entities_id = null,
    ) {}

    /**
     * The link stored in a row of the links table (see Group_Link).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, ?string $origin = null): self
    {
        return new self(
            (int) $row['groups_id_source'],
            (int) $row['groups_id_destination'],
            LinkStrategyEnum::fromValue($row['link_type']),
            $origin,
            isset($row['entities_id']) ? (int) $row['entities_id'] : null,
        );
    }

    /**
     * Identifies the link in its tree: a tree holds one link at most between two groups, in each direction.
     */
    public static function key(int $source, int $destination): string
    {
        return $source . '-' . $destination;
    }

    public function getKey(): string
    {
        return self::key($this->source, $this->destination);
    }

    /**
     * The fields of the link in the links table (see Group_Link).
     *
     * @return array{groups_id_source: int, groups_id_destination: int, link_type: string}
     */
    public function toRow(): array
    {
        return [
            'groups_id_source'      => $this->source,
            'groups_id_destination' => $this->destination,
            'link_type'             => $this->strategy->value,
        ];
    }

    /**
     * The same link, replicated from the parent entity of the given name.
     */
    public function withOrigin(string $origin): self
    {
        return new self($this->source, $this->destination, $this->strategy, $origin, $this->entities_id);
    }

    /**
     * The link applying in an entity, among the links of a pair of groups stored in the entity and
     * in its parent entities. From the deepest entity, the first link applying wins:
     * - a link of the entity itself;
     * - a link of a parent entity applying to its sub-entities (see LinkStrategyEnum::INHERITED),
     *   a LinkStrategyEnum::BASIC one only applying in its own entity.
     * A link blocking the inheritance (see LinkStrategyEnum::NONE) stops the search: no link
     * applies, in its entity and its sub-entities.
     *
     * @param list<self>      $links    Links of the pair, from the deepest entity
     * @param array<int, int> $entities Entities whose links are searched, as keys: the others are ignored
     */
    public static function resolve(array $links, int $entities_id, array $entities): ?self
    {
        foreach ($links as $link) {
            if (!isset($entities[$link->entities_id])) {
                continue;
            }

            $strategy = $link->strategy->getStrategy();
            if ($strategy->blocksInheritance()) {
                return null;
            }

            if ($link->entities_id === $entities_id || $strategy->appliesToSubEntities()) {
                return $link;
            }
        }

        return null;
    }

    public function isReplicated(): bool
    {
        return $this->origin !== null;
    }

    public function withStrategy(LinkStrategyEnum $strategy): self
    {
        return new self($this->source, $this->destination, $strategy, $this->origin, $this->entities_id);
    }
}
