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

abstract class AbstractLinkStrategy
{
    /**
     * Get the CSS color of the arrow representing the link in the escalation graph, null for a
     * link that is not drawn. A link replicated from a parent entity is drawn dashed.
     */
    public function getColor(): ?string
    {
        return null;
    }

    /**
     * Whether the link is drawn in the escalation graph, and can be chosen for a link there
     */
    public function isDrawn(): bool
    {
        return $this->getColor() !== null;
    }

    /**
     * Whether the link is replicated in the sub-entities of its entity
     */
    public function appliesToSubEntities(): bool
    {
        return false;
    }

    /**
     * Whether the link stops, from its entity, the replication of an inherited link
     */
    public function blocksInheritance(): bool
    {
        return false;
    }

    /**
     * Get the label of the link strategy
     */
    abstract public function getLabel(): string;

    /**
     * Get the description of the link strategy, shown in the escalation graph: where the link
     * applies. Empty for a link that is not drawn.
     */
    public function getDescription(): string
    {
        return '';
    }
}
