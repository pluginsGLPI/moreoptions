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

namespace GlpiPlugin\Moreoptions\Tests\Units;

use GlpiPlugin\Moreoptions\EscalationTree\EscalationGraph;
use PHPUnit\Framework\TestCase;

final class EscalationGraphTest extends TestCase
{
    public function testLevelsFollowTheLongestPath(): void
    {
        // 1 -> 2 -> 3, and 1 -> 3 skipping a level; 4 alone
        $graph = new EscalationGraph([4, 3, 2, 1], [[1, 2], [2, 3], [1, 3]]);

        $this->assertSame([4 => 1, 3 => 3, 2 => 2, 1 => 1], $graph->getLevels());
        $this->assertSame([2, 3], $graph->getChildren(1));
        $this->assertSame([2, 1], $graph->getParents(3));
        $this->assertEqualsCanonicalizing([1, 2], array_keys($graph->getAncestors(3)));
        $this->assertEqualsCanonicalizing([2, 3], array_keys($graph->getDescendants(1)));
        $this->assertNull($graph->findCycle());
    }

    public function testCycle(): void
    {
        // 1 -> 2 -> 3 -> 2, then 3 -> 4 after the loop
        $graph = new EscalationGraph([], [[1, 2], [2, 3], [3, 2], [3, 4]]);

        $this->assertSame([1 => 1, 2 => 1, 3 => 1, 4 => 1], $graph->getLevels());
        // In the order of the escalation, from any group of the loop
        $cycle = $graph->findCycle();
        $this->assertContains($cycle, [[2, 3], [3, 2]]);
        $this->assertArrayHasKey(2, $graph->getAncestors(2));
    }

    public function testAnEdgeIsAddedOnce(): void
    {
        $graph = new EscalationGraph();
        $graph->addEdge(1, 2);
        $graph->addEdge(1, 2);

        $this->assertSame([2], $graph->getChildren(1));
        $this->assertSame([1], $graph->getParents(2));
        $this->assertSame([], $graph->getChildren(3));
    }
}
