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
 * Drawing of an escalation tree, with Cytoscape.js (see public/js/escalation_graph.js): the
 * positions of the groups and the routes of the links, never user-set.
 *
 * The groups are laid out on a grid, by level: horizontally, each level is a column, from left to
 * right; vertically, a row, from top to bottom. Within a level, the groups keep their placement
 * order. The links only run in the free space of the grid, so that they never cross a group: the
 * corridors between the levels, and the lanes between the groups of a level. Each link of a
 * corridor or a lane has its own track in it, for links not to overlap each other.
 *
 * Distances are computed on two axes: the primary one goes across the levels, the secondary one
 * along a level.
 */
final class TreeLayout
{
    public const NODE_WIDTH  = 150;

    public const NODE_HEIGHT = 76;

    /** Distance from the input point of a group where a link arrives, when it would overlap another one */
    public const ARRIVAL_SHIFT = 14;

    /** Space between the groups of two successive levels (the corridor where the links run), whatever the orientation */
    private const SPACE_BETWEEN_LEVELS = 150;

    /** Space between two groups of the same level (the lane where the links go around them), whatever the orientation */
    private const SPACE_BETWEEN_GROUPS = 100;

    /** Space between the tracks of a corridor or a lane */
    private const TRACK_SPACING = 10;

    /**
     * Level and index (in the level) of the groups.
     *
     * @var array<int, array{level: int, index: int}> By group id
     */
    private array $cells = [];

    /**
     * @param array<int, int> $levels Level of each group, see EscalationTree::getLevels()
     */
    public function __construct(EscalationTree $tree, array $levels, private readonly bool $vertical)
    {
        $seen = [];
        foreach ($tree->getNodes() as $node) {
            $level = $levels[$node->id];
            $this->cells[$node->id] = ['level' => $level, 'index' => $seen[$level] = ($seen[$level] ?? 0) + 1];
        }
    }

    /**
     * Center of the group.
     *
     * @return array{x: int, y: int}
     */
    public function getPosition(GroupNode $node): array
    {
        ['level' => $level, 'index' => $index] = $this->cells[$node->id];

        return $this->point($this->levelStart($level) + intdiv($this->primarySize(), 2), $this->rowCenter($index));
    }

    /**
     * Routes of the links: where each one starts and ends, and the points where it turns.
     *
     * A link to the next level only uses the corridor between both. A link going further, or back
     * (in a loop), also follows a lane between the groups: from the corridor after its source to
     * the corridor before its target.
     *
     * A link leaves its source from the middle of its output side (right, or bottom when
     * vertical), and arrives at the middle of the input side of its target (left, or top). A link
     * arriving at a group of the row a link to another group leaves from, across the same
     * corridor, would overlap it there: it arrives a bit aside the middle of its target.
     *
     * @param array<string, EscalationLink> $links By key
     * @return array<string, array{from: array{x: int, y: int}, to: array{x: int, y: int}, points: list<array{x: int, y: int}>}> By key
     */
    public function getRoutes(array $links): array
    {
        // The corridors and lanes each link uses, then the track of each link in them
        $paths = [];
        $users = ['corridor' => [], 'lane' => []];
        foreach ($links as $key => $link) {
            $from = $this->cells[$link->source];
            $to   = $this->cells[$link->destination];
            // Corridors after the source level, and before the target one when not the same
            $path = [
                'link'      => $link,
                'from'      => $from,
                'to'        => $to,
                'corridors' => [$from['level'], $from['level']],
                'lane'      => null,
                'start'     => $this->rowCenter($from['index']),
                'end'       => $this->rowCenter($to['index']),
            ];
            if ($to['level'] !== $from['level'] + 1) {
                $path['corridors'][1] = $to['level'] - 1;
                // Forwards: the lane closest to both groups; backwards: around, before the first group.
                $path['lane'] = $to['level'] > $from['level']
                    ? (int) round(($from['index'] + $to['index']) / 2 - 0.5)
                    : 0;
                $users['lane'][$path['lane']][] = $key;
            }

            foreach (array_unique($path['corridors']) as $corridor) {
                $users['corridor'][$corridor][] = $key;
            }

            $paths[$key] = $path;
        }

        // Position of each link among the users of each corridor and lane
        /** @var array{corridor: array<int, array<string, int>>, lane: array<int, array<string, int>>} $positions */
        $positions = ['corridor' => [], 'lane' => []];
        foreach ($users as $way => $by_way) {
            foreach ($by_way as $index => $keys) {
                $positions[$way][$index] = array_flip($keys);
            }
        }

        // Tracks in the corridors left (out) and reached (in); the links leaving each row of each corridor
        $leaving = [];
        foreach ($paths as $key => $path) {
            $paths[$key]['tracks'] = [];
            foreach ($path['corridors'] as $corridor) {
                $paths[$key]['tracks'][] = $this->corridorCenter($corridor) + $this->track($key, $positions['corridor'][$corridor], self::SPACE_BETWEEN_LEVELS);
            }

            $leaving[$path['corridors'][0]][$path['start']][] = $key;
        }

        foreach ($paths as $key => $path) {
            foreach ($leaving[$path['corridors'][1]][$path['end']] ?? [] as $other_key) {
                $other = $paths[$other_key];
                if (
                    $other['link']->source !== $path['link']->source
                    && $other['link']->destination !== $path['link']->destination
                    && $other['tracks'][0] > $path['tracks'][1]
                ) {
                    $paths[$key]['end'] += self::ARRIVAL_SHIFT;
                    break;
                }
            }
        }

        $routes = [];
        foreach ($paths as $key => $path) {
            ['start' => $start, 'end' => $end] = $path;
            [$out, $in] = $path['tracks'];

            if ($path['lane'] !== null) {
                $lane   = $this->laneCenter($path['lane']) + $this->track($key, $positions['lane'][$path['lane']], self::SPACE_BETWEEN_GROUPS);
                $points = [$this->point($out, $start), $this->point($out, $lane), $this->point($in, $lane), $this->point($in, $end)];
            } elseif ($start !== $end) {
                $points = [$this->point($out, $start), $this->point($out, $end)];
            } else {
                $points = [];
            }

            // From the output side of the source group to the input side of the target one
            $routes[$key] = [
                'from'   => $this->point($this->levelEnd($path['from']['level']), $start),
                'to'     => $this->point($this->levelStart($path['to']['level']), $end),
                'points' => $points,
            ];
        }

        return $routes;
    }

    /**
     * Offset of the track of a link in a corridor or a lane shared with other links: the tracks
     * are spread around its center, within its width.
     *
     * @param array<string, int> $users Positions of the links using it, by key
     */
    private function track(string $key, array $users, int $width): int
    {
        $count   = count($users);
        $spacing = $count > 1 ? min(self::TRACK_SPACING, intdiv($width - 16, $count - 1)) : 0;

        return (int) round(($users[$key] - ($count - 1) / 2) * $spacing);
    }

    /** Start of a level on the primary axis */
    private function levelStart(int $level): int
    {
        return 50 + ($level - 1) * $this->levelGap();
    }

    /** End of a level on the primary axis */
    private function levelEnd(int $level): int
    {
        return $this->levelStart($level) + $this->primarySize();
    }

    /** Start of a group of a level on the secondary axis, by its index in the level */
    private function indexStart(int $index): int
    {
        return 60 + ($index - 1) * $this->indexGap();
    }

    /** Middle of the groups of an index (in their level), on the secondary axis */
    private function rowCenter(int $index): int
    {
        return $this->indexStart($index) + intdiv($this->secondarySize(), 2);
    }

    /** Middle of the corridor after a level (0: before the first one), on the primary axis */
    private function corridorCenter(int $level): int
    {
        return $this->levelEnd($level) + intdiv(self::SPACE_BETWEEN_LEVELS, 2);
    }

    /** Middle of the lane after a group of a level (0: before the first one), on the secondary axis */
    private function laneCenter(int $index): int
    {
        return $this->indexStart($index) + $this->secondarySize() + intdiv(self::SPACE_BETWEEN_GROUPS, 2);
    }

    /** Distance from a level to the next one, on the primary axis */
    private function levelGap(): int
    {
        return $this->primarySize() + self::SPACE_BETWEEN_LEVELS;
    }

    /** Distance from a group of a level to the next one, on the secondary axis */
    private function indexGap(): int
    {
        return $this->secondarySize() + self::SPACE_BETWEEN_GROUPS;
    }

    /** Size of a group on the primary axis */
    private function primarySize(): int
    {
        return $this->vertical ? self::NODE_HEIGHT : self::NODE_WIDTH;
    }

    /** Size of a group on the secondary axis */
    private function secondarySize(): int
    {
        return $this->vertical ? self::NODE_WIDTH : self::NODE_HEIGHT;
    }

    /**
     * @return array{x: int, y: int}
     */
    private function point(int $primary, int $secondary): array
    {
        return $this->vertical ? ['x' => $secondary, 'y' => $primary] : ['x' => $primary, 'y' => $secondary];
    }
}
