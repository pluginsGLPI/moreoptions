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

use Config;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Moreoptions\EscalationTree\Action\AbstractTreeAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\TreeActionRegistry;
use GlpiPlugin\Moreoptions\Group_Link;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use Group;
use JsonException;
use Session;

use function Safe\json_decode;

/**
 * Editor of the escalation tree of the active entity, in the "Escalation" tab of a group: the
 * tree being edited, the selection, and the changes made in the page.
 *
 * The editor is rendered server side (see TreeView); each change made in the page is sent to
 * ajax/group_link.php, along with the draft (see getState()), which applies it (see respond())
 * and renders the editor again. Nothing is saved until the draft is saved.
 *
 * Each change is an action (see Action\AbstractTreeAction), applied by apply().
 */
final class TreeEditor
{
    /**
     * Action saving the draft, applied by respond() itself.
     */
    public const SAVE_ACTION = 'save';

    /**
     * Whether the graph is drawn vertically. Like the drawing strategy, a preference of the user,
     * kept by the page in the browser and sent with each change (see setPreferences()).
     */
    private bool $vertical = false;

    /**
     * Strategy of the links drawn in the graph, chosen above it.
     */
    private LinkStrategyEnum $drawing_strategy = LinkStrategyEnum::BASIC;

    private ?int $selected_node = null;

    private ?string $selected_link = null;

    /**
     * Group the graph must be centered on, if out of view (the one just added).
     */
    private ?int $scroll_to = null;

    private string $query = '';

    /**
     * Why the last change was refused, if it was.
     */
    private ?string $error = null;

    /**
     * Whether the links of the entity can be changed: the escalation is part of the configuration
     * of GLPI, whatever the rights on the groups.
     */
    private readonly bool $canedit;

    private function __construct(
        private readonly Group $group,
        private readonly EscalationTree $tree,
    ) {
        $this->canedit = Config::canUpdate();
    }

    /**
     * The tree of the active entity as saved: the groups of its links. The group of the tab is not
     * placed for being the one of the tab: only when it has links (it is then highlighted).
     *
     * @param string|null  $error       Message to show, why the draft of the page was discarded for instance
     * @param array<mixed> $preferences See setPreferences()
     */
    public static function fromDatabase(Group $group, ?string $error = null, array $preferences = []): self
    {
        $editor = new self($group, EscalationTree::load(self::getActiveEntity()));
        $editor->error = $error;
        $editor->setPreferences($preferences);

        return $editor;
    }

    /**
     * The tree of the active entity being edited, as sent back by the page (see getState()).
     *
     * @param array<mixed> $state
     * @param array<mixed> $preferences See setPreferences()
     */
    public static function fromState(Group $group, array $state, array $preferences = []): self
    {
        $editor = new self($group, EscalationTree::fromState(self::getActiveEntity(), $state));
        $editor->setPreferences($preferences);

        $selected_node = (int) ($state['selected_node'] ?? 0);
        $editor->selected_node = $editor->tree->hasNode($selected_node) ? $selected_node : null;
        $selected_link = is_string($state['selected_link'] ?? null) ? $state['selected_link'] : '';
        $editor->selected_link = $editor->tree->getLink($selected_link) !== null ? $selected_link : null;

        return $editor;
    }

    /**
     * Answers a request of the page (see ajax/group_link.php): applies a change to the draft sent,
     * or saves it, and gives the editor to render. The draft is only applied to the entity it was
     * made for, and only saved over the links it was made from.
     *
     * @param array<mixed> $input `groups_id` (group of the tab), `action` (see Action\TreeActionRegistry, or SAVE_ACTION), `state` (draft, see getState()), the preferences of the user (see setPreferences()), and the parameters of the action
     *
     * @throws AccessDeniedHttpException For a group the user cannot see, or a save without the right to edit
     * @throws BadRequestHttpException   For a parameter that is not a single value, an unknown action or an invalid draft
     */
    public static function respond(array $input): self
    {
        // The page only sends single values (the draft is a JSON string).
        if (array_filter($input, 'is_array') !== []) {
            throw new BadRequestHttpException();
        }

        $group = new Group();
        if (!$group->getFromDB((int) ($input['groups_id'] ?? 0)) || !$group->can($group->getID(), READ)) {
            throw new AccessDeniedHttpException();
        }

        $name   = (string) ($input['action'] ?? '');
        $action = $name === self::SAVE_ACTION ? null : (TreeActionRegistry::get($name) ?? throw new BadRequestHttpException());
        try {
            $state = json_decode((string) ($input['state'] ?? ''), true);
        } catch (JsonException) {
            throw new BadRequestHttpException();
        }
        if (!is_array($state)) {
            throw new BadRequestHttpException();
        }

        if (!self::isStateOfActiveEntity($state)) {
            // The active entity changed since the page was loaded (in another browser tab, for instance).
            return self::fromDatabase(
                $group,
                __('The active entity changed: the graph was reloaded, the unsaved changes were discarded.', 'moreoptions'),
                $input,
            );
        }

        $editor = self::fromState($group, $state, $input);
        if ($action !== null) {
            $editor->apply($action, $input);
            return $editor;
        }

        if (!$editor->canedit) {
            throw new AccessDeniedHttpException();
        }
        if (!$editor->tree->isUpToDate()) {
            return self::fromDatabase(
                $group,
                __('The links were saved by someone else meanwhile: the graph was reloaded, the unsaved changes were discarded.', 'moreoptions'),
                $input,
            );
        }
        if (!$editor->save()) {
            return $editor;
        }
        Session::addMessageAfterRedirect(__('Escalation links saved.', 'moreoptions'));

        return self::fromDatabase($group, null, $input);
    }

    /**
     * Whether the draft was made for the active entity: the active entity may have changed since
     * the page was loaded (in another browser tab, for instance).
     *
     * @param array<mixed> $state See getState()
     */
    public static function isStateOfActiveEntity(array $state): bool
    {
        return (int) ($state['entities_id'] ?? -1) === self::getActiveEntity();
    }

    /**
     * The draft, as sent back to fromState() by the page: the entity, the tree and the selection.
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return ['entities_id' => $this->tree->entities_id] + $this->tree->toState() + [
            'selected_node' => $this->selected_node,
            'selected_link' => $this->selected_link,
        ];
    }

    /**
     * Saves the draft, unless its links would make a loop in a sub-entity: the error is then shown,
     * without naming what the user cannot see.
     *
     * @return bool Whether it was saved
     */
    public function save(): bool
    {
        $loop = $this->tree->save();
        if ($loop === null) {
            return true;
        }

        // The groups of the loop, back to the first one
        $names = [];
        foreach (Group_Link::getGroupNames($loop['groups']) as $group) {
            $names[] = $group['name'];
        }
        $names[] = $names[0];

        $this->error = sprintf(
            __('The links were not saved: in the entity %1$s, they would make a loop between the groups %2$s.', 'moreoptions'),
            Group_Link::getEntityName($loop['entities_id']),
            implode(' → ', $names),
        );

        return false;
    }

    /**
     * Applies an action of the page to the draft. The actions changing the links are ignored
     * without the right to edit them (see AbstractTreeAction::requiresEdit()).
     *
     * @param array<mixed> $params Parameters sent by the page, single values (see respond()), with the `query` of the group search
     */
    public function apply(AbstractTreeAction $action, array $params): void
    {
        if ($action->requiresEdit() && !$this->canedit) {
            return;
        }
        $this->query = trim((string) ($params['query'] ?? ''));
        $action->apply($this, $params);
    }

    public function getTree(): EscalationTree
    {
        return $this->tree;
    }

    public function getSelectedNode(): ?int
    {
        return $this->selected_node;
    }

    public function getSelectedLink(): ?string
    {
        return $this->selected_link;
    }

    /**
     * Selects a group, or a link, or nothing: the list of the groups is then shown.
     */
    public function select(?int $node = null, ?string $link = null): void
    {
        $this->selected_node = $node;
        $this->selected_link = $link;
    }

    /**
     * Centers the graph on the given group, if out of view.
     */
    public function scrollTo(int $group): void
    {
        $this->scroll_to = $group;
    }

    /**
     * Applies the preferences of the user sent by the page, kept in the browser: `_orientation`
     * (see setOrientation()) and `_drawing_strategy` (a drawn strategy). Unknown values are ignored.
     *
     * @param array<mixed> $preferences
     */
    public function setPreferences(array $preferences): void
    {
        $this->setOrientation((string) ($preferences['_orientation'] ?? ''));
        $strategy = LinkStrategyEnum::tryFromDrawn((string) ($preferences['_drawing_strategy'] ?? ''));
        if ($strategy !== null) {
            $this->setDrawingStrategy($strategy);
        }
    }

    /**
     * Draws the graph horizontally or vertically: an unknown orientation is ignored.
     */
    public function setOrientation(string $orientation): void
    {
        if (in_array($orientation, ['horizontal', 'vertical'], true)) {
            $this->vertical = $orientation === 'vertical';
        }
    }

    /**
     * Strategy of the links drawn in the graph: the one chosen above it, basic by default.
     */
    public function getDrawingStrategy(): LinkStrategyEnum
    {
        return $this->drawing_strategy;
    }

    public function setDrawingStrategy(LinkStrategyEnum $strategy): void
    {
        $this->drawing_strategy = $strategy;
    }

    /**
     * Explains why two groups cannot be linked: the link would make a loop.
     */
    public function refuseLink(int $from, int $to): void
    {
        $source      = $this->tree->getNode($from);
        $destination = $this->tree->getNode($to);
        if ($source !== null && $destination !== null) {
            $this->error = sprintf(
                __('%1$s already escalates to %2$s, directly or not: linking them the other way would create a loop.', 'moreoptions'),
                $destination->name,
                $source->name,
            );
        }
    }

    /**
     * Renders the editor (the part of the tab replaced after each change).
     */
    public function display(): void
    {
        TemplateRenderer::getInstance()->display(
            '@moreoptions/components/group_link/editor.html.twig',
            $this->getTemplateVariables(),
        );
    }

    /**
     * Variables of the editor template (see templates/components/group_link/editor.html.twig).
     *
     * @return array<string, mixed>
     */
    public function getTemplateVariables(): array
    {
        return (new TreeView(
            group: $this->group,
            tree: $this->tree,
            canedit: $this->canedit,
            vertical: $this->vertical,
            drawing_strategy: $this->drawing_strategy,
            selected_node: $this->selected_node,
            selected_link: $this->selected_link,
            query: $this->query,
            error: $this->error,
            scroll_to: $this->scroll_to,
        ))->getTemplateVariables() + ['state' => $this->getState()];
    }

    private static function getActiveEntity(): int
    {
        return (int) Session::getActiveEntity();
    }

}
