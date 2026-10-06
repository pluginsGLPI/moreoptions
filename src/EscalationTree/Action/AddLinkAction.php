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

namespace GlpiPlugin\Moreoptions\EscalationTree\Action;

use GlpiPlugin\Moreoptions\EscalationTree\EscalationLink;
use GlpiPlugin\Moreoptions\EscalationTree\TreeEditor;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;

/**
 * Adds a link from the panel of the selected group, with the group and the strategy chosen in one
 * of its two forms: a child group (`direction` `to`), linked from the selected group, or a parent
 * group (`from`), the same link with both groups swapped. A group not placed yet is placed. The
 * group stays selected, for other links to be added.
 */
final class AddLinkAction extends AbstractTreeAction
{
    public static function getName(): string
    {
        return 'add_link';
    }

    public function apply(TreeEditor $editor, array $params): void
    {
        $selected  = $editor->getSelectedNode();
        $direction = (string) ($params['direction'] ?? '');
        if ($selected === null || !in_array($direction, EscalationLink::DIRECTIONS, true)) {
            return;
        }

        $other       = (int) ($params['_link_' . $direction . '_group'] ?? 0);
        $strategy    = LinkStrategyEnum::tryFromDrawn((string) ($params['_link_' . $direction . '_strategy'] ?? '')) ?? LinkStrategyEnum::BASIC;
        [$from, $to] = $direction === 'to' ? [$selected, $other] : [$other, $selected];

        // The group chosen is placed for the link: it stays placed if the link is refused, as only
        // a link it already has can make a loop.
        $tree = $editor->getTree();
        $tree->addNode($other);
        $link = $tree->link($from, $to);
        if ($link === null) {
            $editor->refuseLink($from, $to);
            return;
        }
        $tree->setStrategy($link->getKey(), $strategy);
    }
}
