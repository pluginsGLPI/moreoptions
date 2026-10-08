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

/**
 * The actions of the escalation graph, by name (see AbstractTreeAction::getName()). Other plugins
 * can add their own with register(). The actions keep no state: one instance of each is enough.
 */
final class TreeActionRegistry
{
    /**
     * Actions added by other plugins, by name.
     *
     * @var array<string, AbstractTreeAction>
     */
    private static array $registered = [];

    /**
     * Adds an action, or replaces the one of the same name.
     */
    public static function register(AbstractTreeAction $action): void
    {
        self::$registered[$action::getName()] = $action;
    }

    /**
     * The action of the given name, null for an unknown one.
     */
    public static function get(string $name): ?AbstractTreeAction
    {
        return self::getActions()[$name] ?? null;
    }

    /**
     * @return array<string, AbstractTreeAction> By name
     */
    public static function getActions(): array
    {
        $actions = [];
        foreach (
            [
                new RenderAction(),
                new ClearAction(),
                new SelectNodeAction(),
                new SelectLinkAction(),
                new OrientationAction(),
                new DrawingStrategyAction(),
                new AddNodeAction(),
                new RemoveNodeAction(),
                new LinkAction(),
                new AddLinkAction(),
                new SetLinkTypeAction(),
                new DeleteLinkAction(),
                new DeleteSelectionAction(),
                new ResetAction(),
            ] as $action
        ) {
            $actions[$action::getName()] = $action;
        }

        return self::$registered + $actions;
    }
}
