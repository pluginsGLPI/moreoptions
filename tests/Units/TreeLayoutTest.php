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
use GlpiPlugin\Moreoptions\EscalationTree\TreeLayout;
use GlpiPlugin\Moreoptions\Tests\EscalationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TreeLayoutTest extends EscalationTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function orientationProvider(): iterable
    {
        yield 'horizontal' => [false];
        yield 'vertical' => [true];
    }

    /**
     * A tree of the root entity, with the given groups linked.
     *
     * @param list<string>               $names
     * @param list<array{string, string}> $links Source and destination names of each link
     * @return array{EscalationTree, array<string, int>} The tree, and the ids of the groups, by name
     */
    private function createTree(array $names, array $links): array
    {
        $entities_id = $this->getRootEntityId();
        $ids         = $this->createGroups($names, $entities_id);
        $tree        = EscalationTree::load($entities_id, array_values($ids));
        foreach ($links as [$from, $to]) {
            $this->assertNotNull($tree->link($ids[$from], $ids[$to]));
        }

        return [$tree, $ids];
    }

    /**
     * Points of a route, from its start to its end.
     *
     * @param array{from: array{x: int, y: int}, to: array{x: int, y: int}, points: list<array{x: int, y: int}>} $route
     * @return list<array{x: int, y: int}>
     */
    private static function getPath(array $route): array
    {
        return [$route['from'], ...$route['points'], $route['to']];
    }

    /**
     * A1 and A2 (level 1) escalate to B (level 2), which escalates to C (level 3); A1 also escalates
     * to C directly, skipping a level.
     */
    #[DataProvider('orientationProvider')]
    public function testRoutesAvoidGroupsAndEachOther(bool $vertical): void
    {
        $this->login();
        [$tree, $ids] = $this->createTree(['A1', 'A2', 'B', 'C'], [['A1', 'B'], ['A2', 'B'], ['B', 'C'], ['A1', 'C']]);

        $layout = new TreeLayout($tree, $tree->getLevels(), $vertical);
        $routes = $layout->getRoutes($tree->getLinks());

        // Each route goes from the middle of the output side of its source to the input side of its
        // target: at its middle, or next to it not to overlap another link.
        [$primary, $secondary] = $vertical ? ['y', 'x'] : ['x', 'y'];
        $half = intdiv($vertical ? TreeLayout::NODE_HEIGHT : TreeLayout::NODE_WIDTH, 2);
        foreach ($tree->getLinks() as $key => $link) {
            $source = $tree->getNode($link->source);
            $target = $tree->getNode($link->destination);
            $this->assertNotNull($source);
            $this->assertNotNull($target);
            $out = $layout->getPosition($source);
            $out[$primary] += $half;
            $this->assertSame($out, $routes[$key]['from']);
            $in = $layout->getPosition($target);
            $this->assertSame($in[$primary] - $half, $routes[$key]['to'][$primary]);
            $this->assertContains($routes[$key]['to'][$secondary] - $in[$secondary], [0, TreeLayout::ARRIVAL_SHIFT]);
        }
        $this->assertNoOverlap($tree, $routes);
        // The link skipping a level goes around the group in between.
        $this->assertCount(4, $routes[EscalationLink::key($ids['A1'], $ids['C'])]['points']);

        // No part of any route crosses a group.
        $boxes = [];
        foreach ($tree->getNodes() as $node) {
            ['x' => $x, 'y' => $y] = $layout->getPosition($node);
            $boxes[$node->id] = [$x - TreeLayout::NODE_WIDTH / 2, $y - TreeLayout::NODE_HEIGHT / 2, $x + TreeLayout::NODE_WIDTH / 2, $y + TreeLayout::NODE_HEIGHT / 2];
        }
        foreach ($routes as $key => $route) {
            $path = self::getPath($route);
            for ($i = 0; $i < count($path) - 1; $i++) {
                for ($t = 0.05; $t < 1; $t += 0.05) {
                    $x = $path[$i]['x'] + ($path[$i + 1]['x'] - $path[$i]['x']) * $t;
                    $y = $path[$i]['y'] + ($path[$i + 1]['y'] - $path[$i]['y']) * $t;
                    foreach ($boxes as $id => [$x1, $y1, $x2, $y2]) {
                        $this->assertFalse(
                            $x > $x1 && $x < $x2 && $y > $y1 && $y < $y2,
                            sprintf('Link %s crosses group %d', $key, $id),
                        );
                    }
                }
            }
        }

        // Links turning in the corridor after level 1 each have their own track in it.
        $this->assertNotSame(
            $routes[EscalationLink::key($ids['A2'], $ids['B'])]['points'][0][$primary],
            $routes[EscalationLink::key($ids['A1'], $ids['C'])]['points'][0][$primary],
        );
    }

    /**
     * A (level 1, first row) escalates to D (level 2, second row), B (level 1, second row) to C
     * (level 2, first row): the links cross in the corridor between both levels.
     */
    #[DataProvider('orientationProvider')]
    public function testCrossingLinksDoNotOverlap(bool $vertical): void
    {
        $this->login();
        [$tree] = $this->createTree(['A', 'B', 'C', 'D'], [['A', 'D'], ['B', 'C']]);

        $layout = new TreeLayout($tree, $tree->getLevels(), $vertical);
        $this->assertNoOverlap($tree, $layout->getRoutes($tree->getLinks()));
    }

    /**
     * No two links share a part of their route, unless they leave the same group or arrive at the
     * same group: the route of a link would read as going elsewhere.
     *
     * @param array<string, array{from: array{x: int, y: int}, to: array{x: int, y: int}, points: list<array{x: int, y: int}>}> $routes
     */
    private function assertNoOverlap(EscalationTree $tree, array $routes): void
    {
        $segments = [];
        foreach ($routes as $key => $route) {
            $path = self::getPath($route);
            for ($i = 0; $i < count($path) - 1; $i++) {
                $segments[$key][] = [$path[$i], $path[$i + 1]];
            }
        }

        $links = $tree->getLinks();
        foreach ($segments as $key => $own) {
            foreach ($segments as $other_key => $others) {
                if (
                    $key >= $other_key
                    || $links[$key]->source === $links[$other_key]->source
                    || $links[$key]->destination === $links[$other_key]->destination
                ) {
                    continue;
                }
                foreach ($own as [$a1, $a2]) {
                    foreach ($others as [$b1, $b2]) {
                        foreach (['x' => 'y', 'y' => 'x'] as $fixed => $along) {
                            if ($a1[$fixed] !== $a2[$fixed] || $b1[$fixed] !== $b2[$fixed] || $a1[$fixed] !== $b1[$fixed]) {
                                continue;
                            }
                            $shared = min(max($a1[$along], $a2[$along]), max($b1[$along], $b2[$along]))
                                - max(min($a1[$along], $a2[$along]), min($b1[$along], $b2[$along]));
                            $this->assertLessThanOrEqual(0, $shared, sprintf('Links %s and %s overlap', $key, $other_key));
                        }
                    }
                }
            }
        }
    }
}
