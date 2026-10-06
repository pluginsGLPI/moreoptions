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

use GlpiPlugin\Moreoptions\EscalationTree\EscalationLink;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use PHPUnit\Framework\TestCase;

final class EscalationLinkTest extends TestCase
{
    // Root > Child > Grandchild
    private const ROOT = 0;

    private const CHILD = 1;

    private const GRANDCHILD = 2;

    /**
     * Links of the pair 1 -> 2, from the deepest entity.
     *
     * @param list<array{LinkStrategyEnum, int}> $links Strategy and entity of each link
     * @return list<EscalationLink>
     */
    private function createPair(array $links): array
    {
        $pair = [];
        foreach ($links as [$strategy, $entity]) {
            $pair[] = new EscalationLink(1, 2, $strategy, null, $entity);
        }

        return $pair;
    }

    public function testResolveAnInheritedLink(): void
    {
        $pair = $this->createPair([[LinkStrategyEnum::INHERITED, self::ROOT]]);

        $this->assertSame(self::ROOT, EscalationLink::resolve($pair, self::ROOT, [self::ROOT => 0])?->entities_id);
        $this->assertSame(self::ROOT, EscalationLink::resolve($pair, self::GRANDCHILD, [self::ROOT => 0, self::CHILD => 1, self::GRANDCHILD => 2])?->entities_id);
        // The links of the entities not searched are ignored.
        $this->assertNull(EscalationLink::resolve($pair, self::CHILD, [self::CHILD => 0]));
    }

    public function testABasicLinkOnlyAppliesInItsEntity(): void
    {
        // A basic link of the child hides the inherited link of the root, in the child only.
        $pair     = $this->createPair([[LinkStrategyEnum::BASIC, self::CHILD], [LinkStrategyEnum::INHERITED, self::ROOT]]);
        $entities = [self::ROOT => 0, self::CHILD => 1, self::GRANDCHILD => 2];

        $this->assertSame(self::CHILD, EscalationLink::resolve($pair, self::CHILD, $entities)?->entities_id);
        $this->assertSame(self::ROOT, EscalationLink::resolve($pair, self::GRANDCHILD, $entities)?->entities_id);
        // Without the links of the child itself: what the root passes down to it
        $this->assertSame(self::ROOT, EscalationLink::resolve($pair, self::CHILD, [self::ROOT => 0])?->entities_id);
    }

    public function testANoneLinkBlocksTheInheritance(): void
    {
        // Removed from the child: from its sub-entities too, but not from the root.
        $pair     = $this->createPair([[LinkStrategyEnum::NONE, self::CHILD], [LinkStrategyEnum::INHERITED, self::ROOT]]);
        $entities = [self::ROOT => 0, self::CHILD => 1, self::GRANDCHILD => 2];

        $this->assertNull(EscalationLink::resolve($pair, self::CHILD, $entities));
        $this->assertNull(EscalationLink::resolve($pair, self::GRANDCHILD, $entities));
        $this->assertSame(self::ROOT, EscalationLink::resolve($pair, self::ROOT, [self::ROOT => 0])?->entities_id);
    }
}
