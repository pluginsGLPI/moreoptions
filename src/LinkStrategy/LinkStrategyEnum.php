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

namespace GlpiPlugin\Moreoptions\LinkStrategy;

enum LinkStrategyEnum: string
{
    case NONE      = 'none';
    case BASIC     = 'basic';
    case INHERITED = 'inherited';

    /**
     * Create a new instance of the strategy
     */
    public function getStrategy(): AbstractLinkStrategy
    {
        return match ($this) {
            self::NONE      => new NoneLink(),
            self::BASIC     => new BasicLink(),
            self::INHERITED => new InheritedLink(),
        };
    }

    /**
     * Get the strategy of a stored value, the default one for an unknown value
     */
    public static function fromValue(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::getDefault();
    }

    /**
     * Get the strategy of a value sent by the escalation graph: null unless a drawn one
     */
    public static function tryFromDrawn(string $value): ?self
    {
        $strategy = self::tryFrom($value);

        return $strategy?->getStrategy()->isDrawn() ? $strategy : null;
    }

    /**
     * Get the default strategy
     */
    public static function getDefault(): self
    {
        return self::NONE;
    }

    /**
     * Get the strategies of the links drawn in the escalation graph
     *
     * @return list<self>
     */
    public static function getDrawnCases(): array
    {
        $cases = [];
        foreach (self::cases() as $case) {
            if ($case->getStrategy()->isDrawn()) {
                $cases[] = $case;
            }
        }

        return $cases;
    }
}
