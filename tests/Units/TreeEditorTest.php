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

use Config;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Moreoptions\EscalationTree\EscalationLink;
use GlpiPlugin\Moreoptions\EscalationTree\Action\AbstractTreeAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\AddLinkAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\AddNodeAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\ClearAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\DeleteLinkAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\DeleteSelectionAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\DrawingStrategyAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\LinkAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\OrientationAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\RemoveNodeAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\RenderAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\ResetAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\SelectLinkAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\SelectNodeAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\SetLinkTypeAction;
use GlpiPlugin\Moreoptions\EscalationTree\Action\TreeActionRegistry;
use GlpiPlugin\Moreoptions\EscalationTree\TreeEditor;
use GlpiPlugin\Moreoptions\Group_Link;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use GlpiPlugin\Moreoptions\Tests\EscalationTestCase;
use Group;
use PHPUnit\Framework\Attributes\DataProvider;

use function Safe\json_encode;

final class TreeEditorTest extends EscalationTestCase
{
    /**
     * A child entity holding a group of its own, Current, and four groups of its parent entity, linked
     * there by an inherited link, A -> B, and a basic one, C -> D. The child entity is the active one.
     *
     * @return array{Group, array<string, int>}
     */
    private function createGraphData(): array
    {
        [$child_id, $a, $b] = $this->createInheritedLink();
        $root_id = $this->getRootEntityId();
        $ids     = ['A' => $a, 'B' => $b] + $this->createGroups(['C', 'D'], $root_id, true);
        $this->createLinks($root_id, [[$ids['C'], $ids['D'], LinkStrategyEnum::BASIC]]);
        $group = $this->createItem(Group::class, [
            'name'        => 'Current',
            'entities_id' => $child_id,
            'is_assign'   => 1,
        ]);
        $ids['Current'] = $group->getID();

        $this->setEntity($child_id, true);

        return [$group, $ids];
    }

    /**
     * @return int Entity of the tree edited: the one of the group Current
     */
    private function getEntity(Group $group): int
    {
        return (int) $group->fields['entities_id'];
    }

    /**
     * A field of the data of the links given to Cytoscape.js.
     *
     * @param array<string, mixed> $vars Template variables
     * @return array<string, mixed> By link key
     */
    private function getEdgeData(array $vars, string $field): array
    {
        return array_column(array_column($vars['elements']['edges'], 'data'), $field, 'id');
    }

    /**
     * A field of the data of the groups given to Cytoscape.js.
     *
     * @param array<string, mixed> $vars Template variables
     * @return array<int|string, mixed> By group id (or point id)
     */
    private function getNodeData(array $vars, string $field): array
    {
        return array_column(array_column($vars['elements']['nodes'], 'data'), $field, 'id');
    }

    /**
     * Applies the action as the page does: from the state of the previous render.
     *
     * @param array<string, mixed> $params
     */
    private function handle(int $entities_id, TreeEditor $editor, AbstractTreeAction $action, array $params = []): TreeEditor
    {
        // The page sends the preferences of the user with each change.
        $vars   = $editor->getTemplateVariables();
        $editor = TreeEditor::fromState($entities_id, $editor->getState(), [
            '_orientation'      => $vars['orientation'],
            '_drawing_strategy' => $vars['drawing_strategy'],
        ]);
        $editor->apply($action, $params);

        return $editor;
    }

    /**
     * A request of the page, with the draft of the given editor.
     *
     * @param array<string, mixed> $params Action and its parameters
     * @return array<string, mixed>
     */
    private function request(int $entities_id, TreeEditor $editor, array $params): array
    {
        return $params + ['entities_id' => $entities_id, 'state' => json_encode($editor->getState())];
    }

    public function testFromDatabase(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $this->createLinks($this->getEntity($group), [[$ids['Current'], $ids['C'], LinkStrategyEnum::BASIC]]);

        $editor = TreeEditor::fromDatabase($this->getEntity($group));
        $this->assertSame([
            'entities_id'   => $this->getEntity($group),
            'version'       => Group_Link::getVersion(Group_Link::getRowsOfEntity($this->getEntity($group))),
            'reset'         => false,
            'nodes'         => [$ids['Current'], $ids['C'], $ids['A'], $ids['B']],
            'links'         => [['from' => $ids['Current'], 'to' => $ids['C'], 'type' => LinkStrategyEnum::BASIC->value]],
            'removed'       => [],
            'selected_node' => null,
            'selected_link' => null,
        ], $editor->getState());

        $vars = $editor->getTemplateVariables();
        // Drawn with the color of their strategy
        $this->assertSame(
            [
                "{$ids['Current']}-{$ids['C']}" => LinkStrategyEnum::BASIC->getStrategy()->getColor(),
                "{$ids['A']}-{$ids['B']}"       => LinkStrategyEnum::INHERITED->getStrategy()->getColor(),
            ],
            $this->getEdgeData($vars, 'color'),
        );
        $this->assertSame(
            ["{$ids['Current']}-{$ids['C']}" => false, "{$ids['A']}-{$ids['B']}" => true],
            $this->getEdgeData($vars, 'replicated'),
        );
        // Only the strategies drawn can be chosen, the inherited one being replicated.
        $this->assertSame(
            [LinkStrategyEnum::BASIC->value, LinkStrategyEnum::INHERITED->value],
            array_column($vars['strategies'], 'value'),
        );
        $this->assertSame([false, true], array_column($vars['strategies'], 'replicable'));
        $this->assertSame(
            [LinkStrategyEnum::BASIC->getStrategy()->getColor(), LinkStrategyEnum::INHERITED->getStrategy()->getColor()],
            array_column($vars['strategies'], 'color'),
        );
        // Shown as a badge on the groups
        $this->assertSame(
            [$ids['Current'] => 1, $ids['C'] => 2, $ids['A'] => 1, $ids['B'] => 2],
            $this->getNodeData($vars, 'level'),
        );
        $this->assertNull($vars['selected_node']);

        // The selected group shows all its links, both ways.
        $editor->apply(new SelectNodeAction(), ['group' => $ids['C']]);
        $selected = $editor->getTemplateVariables()['selected_node'];
        $this->assertSame('C', $selected['name']);
        $this->assertSame(2, $selected['level']);
        $this->assertSame([], $selected['to']);
        $this->assertSame(["{$ids['Current']}-{$ids['C']}"], array_column($selected['from'], 'key'));
        $this->assertSame(['Current'], array_column($selected['from'], 'from_name'));

        $editor->apply(new SelectNodeAction(), ['group' => $ids['A']]);
        $selected = $editor->getTemplateVariables()['selected_node'];
        $this->assertSame(['B'], array_column($selected['to'], 'to_name'));
        $this->assertSame([true], array_column($selected['to'], 'replicated'));
        $this->assertSame([LinkStrategyEnum::INHERITED->getStrategy()->getColor()], array_column($selected['to'], 'color'));
        $this->assertSame([], $selected['from']);

        $pool = array_column($vars['pool'], 'id');
        $this->assertContains($ids['D'], $pool);
        $this->assertNotContains($ids['A'], $pool);
    }

    public function testGroupWithoutLinksIsNotPlaced(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();

        // Without links, a group of the entity is not in the graph: only the groups of the replicated link.
        $editor = TreeEditor::fromDatabase($this->getEntity($group));
        $this->assertSame([$ids['A'], $ids['B']], $editor->getState()['nodes']);
        $this->assertContains($ids['Current'], array_column($editor->getTemplateVariables()['pool'], 'id'));

        // Once linked and saved, it is.
        $editor = $this->handle($this->getEntity($group), $editor, new AddNodeAction(), ['group' => $ids['Current']]);
        $editor = $this->handle($this->getEntity($group), $editor, new LinkAction(), ['from' => $ids['Current'], 'to' => $ids['A']]);
        $this->assertTrue($editor->save());
        $this->assertContains($ids['Current'], TreeEditor::fromDatabase($this->getEntity($group))->getState()['nodes']);
    }

    public function testLinksOfTheGivenEntity(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();

        // In the child entity, only the inherited link of the root entity is replicated.
        $vars = TreeEditor::fromDatabase($this->getEntity($group))->getTemplateVariables();
        $this->assertSame(["{$ids['A']}-{$ids['B']}" => true], $this->getEdgeData($vars, 'replicated'));

        // In the root entity, both links are its own.
        $this->setEntity($this->getRootEntityId(), true);
        $vars = TreeEditor::fromDatabase($this->getRootEntityId())->getTemplateVariables();
        $this->assertSame(
            ["{$ids['A']}-{$ids['B']}" => false, "{$ids['C']}-{$ids['D']}" => false],
            $this->getEdgeData($vars, 'replicated'),
        );
    }

    public function testAddLinkFromTheGroupPanel(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $editor = $this->handle($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), new AddNodeAction(), ['group' => $ids['Current']]);
        $editor = $this->handle($this->getEntity($group), $editor, new SelectNodeAction(), ['group' => $ids['Current']]);

        // Only the group itself is left out of the group dropdowns: every other one can be linked.
        $selected = $editor->getTemplateVariables()['selected_node'];
        $others   = array_diff(array_keys(Group_Link::getGroupsForEntity($this->getEntity($group))), [$ids['Current']]);
        $this->assertEqualsCanonicalizing($others, array_keys($selected['candidates_to']));
        $this->assertEqualsCanonicalizing($others, array_keys($selected['candidates_from']));
        $this->assertSame('D', $selected['candidates_to'][$ids['D']]);

        // Current -> D, inherited: D is placed, Current stays selected.
        $editor = $this->handle($this->getEntity($group), $editor, new AddLinkAction(), [
            'direction'          => 'to',
            '_link_to_group'     => $ids['D'],
            '_link_to_strategy'  => LinkStrategyEnum::INHERITED->value,
        ]);
        $this->assertSame([['from' => $ids['Current'], 'to' => $ids['D'], 'type' => LinkStrategyEnum::INHERITED->value]], $editor->getState()['links']);
        $this->assertSame($ids['Current'], $editor->getState()['selected_node']);
        $this->assertSame(['D'], array_column($editor->getTemplateVariables()['selected_node']['to'], 'to_name'));

        // A -> Current, basic (the default strategy)
        $editor = $this->handle($this->getEntity($group), $editor, new AddLinkAction(), ['direction' => 'from', '_link_from_group' => $ids['A']]);
        $this->assertSame(['A'], array_column($editor->getTemplateVariables()['selected_node']['from'], 'from_name'));
        $this->assertSame(LinkStrategyEnum::BASIC->value, $editor->getState()['links'][1]['type']);

        // Deleting a link from the panel of the selected group keeps it selected.
        $editor = $this->handle($this->getEntity($group), $editor, new AddLinkAction(), ['direction' => 'to', '_link_to_group' => $ids['B']]);
        $editor = $this->handle($this->getEntity($group), $editor, new DeleteLinkAction(), ['link' => EscalationLink::key($ids['Current'], $ids['B'])]);
        $this->assertSame($ids['Current'], $editor->getState()['selected_node']);
        $this->assertCount(2, $editor->getState()['links']);

        // From D, neither Current nor A (its ancestors) are offered, and forcing them is refused.
        $editor = $this->handle($this->getEntity($group), $editor, new SelectNodeAction(), ['group' => $ids['D']]);
        $selected = $editor->getTemplateVariables()['selected_node'];
        $this->assertArrayNotHasKey($ids['Current'], $selected['candidates_to']);
        $this->assertArrayNotHasKey($ids['A'], $selected['candidates_to']);
        $this->assertArrayNotHasKey($ids['D'], $selected['candidates_to']);
        $this->assertArrayHasKey($ids['B'], $selected['candidates_to']);
        // Current, already linked to D, is not offered either the other way.
        $this->assertArrayNotHasKey($ids['Current'], $selected['candidates_from']);
        $editor = $this->handle($this->getEntity($group), $editor, new AddLinkAction(), ['direction' => 'to', '_link_to_group' => $ids['A']]);
        $this->assertIsString($editor->getTemplateVariables()['error']);
        $this->assertCount(2, $editor->getState()['links']);
    }

    public function testEditAndSave(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $editor = $this->handle($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), new AddNodeAction(), ['group' => $ids['Current']]);
        $current_c = EscalationLink::key($ids['Current'], $ids['C']);
        $a_b       = EscalationLink::key($ids['A'], $ids['B']);

        // Place D, then draw Current -> D, by dragging Current onto D
        $editor = $this->handle($this->getEntity($group), $editor, new AddNodeAction(), ['group' => $ids['D']]);
        $this->assertContains($ids['D'], $editor->getState()['nodes']);
        $this->assertNull($editor->getState()['selected_node']);
        $this->assertSame($ids['D'], $editor->getTemplateVariables()['scroll_to']);
        $editor = $this->handle($this->getEntity($group), $editor, new LinkAction(), ['from' => $ids['Current'], 'to' => $ids['D']]);
        $key = EscalationLink::key($ids['Current'], $ids['D']);
        $this->assertSame($key, $editor->getState()['selected_link']);
        $editor = $this->handle($this->getEntity($group), $editor, new SetLinkTypeAction(), ['value' => LinkStrategyEnum::INHERITED->value]);

        // Draw Current -> C, then delete it from the context menu, without selecting it
        $editor = $this->handle($this->getEntity($group), $editor, new AddNodeAction(), ['group' => $ids['C']]);
        $editor = $this->handle($this->getEntity($group), $editor, new LinkAction(), ['from' => $ids['Current'], 'to' => $ids['C']]);
        $this->assertSame($current_c, $editor->getState()['selected_link']);
        $editor = $this->handle($this->getEntity($group), $editor, new ClearAction());
        $editor = $this->handle($this->getEntity($group), $editor, new SetLinkTypeAction(), ['link' => $current_c, 'value' => LinkStrategyEnum::INHERITED->value]);
        $this->assertSame([LinkStrategyEnum::INHERITED->value, LinkStrategyEnum::INHERITED->value], array_column($editor->getState()['links'], 'type'));
        $editor = $this->handle($this->getEntity($group), $editor, new DeleteLinkAction(), ['link' => $current_c]);

        // D cannot escalate to Current, which escalates to it: the link is refused, with an explanation.
        $editor = $this->handle($this->getEntity($group), $editor, new LinkAction(), ['from' => $ids['D'], 'to' => $ids['Current']]);
        $this->assertIsString($editor->getTemplateVariables()['error']);
        $this->assertCount(1, $editor->getState()['links']);
        $editor = $this->handle($this->getEntity($group), $editor, new ClearAction());

        // Remove the inherited link A -> B
        $editor = $this->handle($this->getEntity($group), $editor, new SelectLinkAction(), ['link' => $a_b]);
        $selected = $editor->getTemplateVariables()['selected_link'];
        $this->assertTrue($selected['replicated']);
        $this->assertSame([$ids['A'], $ids['B']], [$selected['from_id'], $selected['to_id']]);
        $this->assertSame(LinkStrategyEnum::INHERITED->getStrategy()->getColor(), $selected['color']);
        $editor = $this->handle($this->getEntity($group), $editor, new DeleteLinkAction());

        $state = $editor->getState();
        $this->assertSame([['from' => $ids['Current'], 'to' => $ids['D'], 'type' => LinkStrategyEnum::INHERITED->value]], $state['links']);
        $this->assertSame([$a_b], $state['removed']);

        // Nothing is saved until the "save" action.
        $this->assertSame([], $this->getEntityLinks($this->getEntity($group)));
        $this->assertTrue(TreeEditor::fromState($this->getEntity($group), $state)->save());
        $this->assertSame([
            $a_b => LinkStrategyEnum::NONE->value,
            $key => LinkStrategyEnum::INHERITED->value,
        ], $this->getEntityLinks($this->getEntity($group)));

        // Once saved, the removed inherited link is loaded as removed, and drawing it again restores it.
        $editor = TreeEditor::fromDatabase($this->getEntity($group));
        $this->assertSame([$a_b], $editor->getState()['removed']);
        $editor = $this->handle($this->getEntity($group), $editor, new AddNodeAction(), ['group' => $ids['A']]);
        $editor = $this->handle($this->getEntity($group), $editor, new AddNodeAction(), ['group' => $ids['B']]);
        $editor = $this->handle($this->getEntity($group), $editor, new LinkAction(), ['from' => $ids['A'], 'to' => $ids['B']]);
        $this->assertSame([], $editor->getState()['removed']);

        // Once saved, the restored link applies again: its "none" link is gone.
        $this->assertTrue(TreeEditor::fromState($this->getEntity($group), $editor->getState())->save());
        $this->assertArrayNotHasKey($a_b, $this->getEntityLinks($this->getEntity($group)));
        $this->assertSame([], TreeEditor::fromDatabase($this->getEntity($group))->getState()['removed']);

        // Removing a group removes its links.
        $editor = $this->handle($this->getEntity($group), $editor, new RemoveNodeAction(), ['group' => $ids['D']]);
        $this->assertSame([], $editor->getState()['links']);
        $this->assertNotContains($ids['D'], $editor->getState()['nodes']);
    }

    public function testFromStateIgnoresInvalidData(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $root_id = $this->getRootEntityId();
        $not_assignable = $this->createGroups(['Not assignable'], $this->getEntity($group), assignable: false)['Not assignable'];
        // Groups of other entities: a sibling one, and the parent one without recursion
        $sibling = $this->createGroups(['Sibling group'], $this->createChildEntity('Sibling', $root_id))['Sibling group'];
        $parent  = $this->createGroups(['Parent group'], $root_id)['Parent group'];

        $editor = TreeEditor::fromState($this->getEntity($group), [
            'nodes'   => [$ids['A'], $ids['C'], $not_assignable, $sibling, $parent, 'foo'],
            'links'   => [
                ['from' => $ids['A'], 'to' => $ids['C'], 'type' => LinkStrategyEnum::BASIC->value],
                ['from' => $ids['A'], 'to' => $ids['A'], 'type' => LinkStrategyEnum::BASIC->value],
                ['from' => $ids['C'], 'to' => $ids['A'], 'type' => LinkStrategyEnum::NONE->value],
                ['from' => $ids['C'], 'to' => $not_assignable, 'type' => LinkStrategyEnum::BASIC->value],
                ['from' => $ids['C'], 'to' => $sibling, 'type' => LinkStrategyEnum::BASIC->value],
                ['from' => $parent, 'to' => $ids['A'], 'type' => LinkStrategyEnum::BASIC->value],
                'foo',
            ],
            'removed'       => [EscalationLink::key($ids['A'], $ids['C']), EscalationLink::key($ids['C'], $ids['D'])],
            'selected_link' => 'foo',
        ]);

        $this->assertSame([
            'entities_id'   => $this->getEntity($group),
            'version'       => '',
            'reset'         => false,
            'nodes'         => [$ids['A'], $ids['C']],
            'links'         => [['from' => $ids['A'], 'to' => $ids['C'], 'type' => LinkStrategyEnum::BASIC->value]],
            'removed'       => [],
            'selected_node' => null,
            'selected_link' => null,
        ], $editor->getState());

        // Saved, only the valid link is.
        $this->assertTrue($editor->save());
        $this->assertSame(
            [EscalationLink::key($ids['A'], $ids['C']) => LinkStrategyEnum::BASIC->value],
            $this->getEntityLinks($this->getEntity($group)),
        );
    }

    /**
     * The draft of the editor without its selection: the tree only.
     *
     * @return array<string, mixed>
     */
    private function getTreeState(TreeEditor $editor): array
    {
        return array_diff_key($editor->getState(), ['selected_node' => true, 'selected_link' => true]);
    }

    public function testReadOnly(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        // Without the right to update the configuration
        $_SESSION['glpiactiveprofile']['config'] = READ;

        $editor = TreeEditor::fromDatabase($this->getEntity($group));
        $this->assertFalse($editor->getTemplateVariables()['canedit']);
        $this->assertFalse($editor->getTemplateVariables()['can_reset']);
        // Whatever the action changing the links, with all the parameters it could use
        $editor->apply(new SelectNodeAction(), ['group' => $ids['A']]);
        $state = $this->getTreeState($editor);
        $params = [
            'group'          => $ids['A'],
            'link'           => EscalationLink::key($ids['A'], $ids['B']),
            'from'           => $ids['B'],
            'to'             => $ids['C'],
            'value'          => LinkStrategyEnum::INHERITED->value,
            'direction'      => 'to',
            '_link_to_group' => $ids['C'],
        ];
        foreach (TreeActionRegistry::getActions() as $action) {
            if ($action->requiresEdit()) {
                $editor->apply($action, $params);
                $this->assertSame($state, $this->getTreeState($editor), $action::getName());
            }
        }

        // Selecting stays possible.
        $editor->apply(new SelectLinkAction(), ['link' => EscalationLink::key($ids['A'], $ids['B'])]);
        $this->assertSame(EscalationLink::key($ids['A'], $ids['B']), $editor->getState()['selected_link']);
    }

    public function testDraftOfAnotherEntityIsRefused(): void
    {
        $this->login();
        [$group] = $this->createGraphData();
        $this->setEntity($this->getRootEntityId(), true);
        $draft = TreeEditor::fromDatabase($this->getEntity($group));

        // The draft is only applied to the entity it was made for.
        $this->expectException(BadRequestHttpException::class);
        TreeEditor::respond($this->request($this->getRootEntityId(), $draft, ['action' => RenderAction::getName()]));
    }

    public function testOrientation(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $editor = TreeEditor::fromDatabase($this->getEntity($group));
        $editor->apply(new OrientationAction(), ['value' => 'vertical']);
        $vars = $editor->getTemplateVariables();
        $this->assertSame('vertical', $vars['orientation']);

        // Vertically, A (level 1) is above B (level 2).
        $positions = [];
        foreach ($vars['elements']['nodes'] as $node) {
            $positions[(int) $node['data']['id']] = $node['position'];
        }
        $this->assertLessThan($positions[$ids['B']]['y'], $positions[$ids['A']]['y']);

        $editor->apply(new OrientationAction(), ['value' => 'diagonal']);
        $this->assertSame('vertical', $editor->getTemplateVariables()['orientation']);
        $editor->apply(new OrientationAction(), ['value' => 'horizontal']);
        $this->assertSame('horizontal', $editor->getTemplateVariables()['orientation']);
    }

    public function testPreferencesAreSentByThePage(): void
    {
        $this->login();
        [$group] = $this->createGraphData();
        $editor = TreeEditor::fromDatabase($this->getEntity($group));
        $this->assertSame('horizontal', $editor->getTemplateVariables()['orientation']);
        $this->assertSame(LinkStrategyEnum::BASIC->value, $editor->getTemplateVariables()['drawing_strategy']);

        $vars = TreeEditor::respond($this->request($this->getEntity($group), $editor, [
            'action'            => 'render',
            '_orientation'      => 'vertical',
            '_drawing_strategy' => LinkStrategyEnum::INHERITED->value,
        ]))->getTemplateVariables();
        $this->assertSame('vertical', $vars['orientation']);
        $this->assertSame(LinkStrategyEnum::INHERITED->value, $vars['drawing_strategy']);

        // Kept by the page only, and unknown values are ignored.
        $vars = TreeEditor::respond($this->request($this->getEntity($group), $editor, [
            'action'            => 'render',
            '_orientation'      => 'diagonal',
            '_drawing_strategy' => LinkStrategyEnum::NONE->value,
        ]))->getTemplateVariables();
        $this->assertSame('horizontal', $vars['orientation']);
        $this->assertSame(LinkStrategyEnum::BASIC->value, $vars['drawing_strategy']);
    }

    public function testDrawingStrategy(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $editor = $this->handle($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), new AddNodeAction(), ['group' => $ids['Current']]);
        $this->assertSame(LinkStrategyEnum::BASIC->value, $editor->getTemplateVariables()['drawing_strategy']);

        // Links drawn take the strategy chosen above the graph; a strategy that is not drawn is ignored.
        $editor = $this->handle($this->getEntity($group), $editor, new DrawingStrategyAction(), ['value' => LinkStrategyEnum::INHERITED->value]);
        $editor = $this->handle($this->getEntity($group), $editor, new DrawingStrategyAction(), ['value' => LinkStrategyEnum::NONE->value]);
        $this->assertSame(LinkStrategyEnum::INHERITED->value, $editor->getTemplateVariables()['drawing_strategy']);
        $editor = $this->handle($this->getEntity($group), $editor, new AddNodeAction(), ['group' => $ids['C']]);
        $editor = $this->handle($this->getEntity($group), $editor, new LinkAction(), ['from' => $ids['Current'], 'to' => $ids['C']]);
        $this->assertSame([['from' => $ids['Current'], 'to' => $ids['C'], 'type' => LinkStrategyEnum::INHERITED->value]], $editor->getState()['links']);

        // A link already there keeps its strategy.
        $editor = $this->handle($this->getEntity($group), $editor, new DrawingStrategyAction(), ['value' => LinkStrategyEnum::BASIC->value]);
        $editor = $this->handle($this->getEntity($group), $editor, new LinkAction(), ['from' => $ids['Current'], 'to' => $ids['C']]);
        $this->assertSame(LinkStrategyEnum::INHERITED->value, $editor->getState()['links'][0]['type']);
    }

    public function testReset(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $root_id = $this->getRootEntityId();
        // The root entity also passes down B -> C.
        $this->createLinks($root_id, [[$ids['B'], $ids['C'], LinkStrategyEnum::INHERITED]]);
        // In the child entity: a link of its own, the inherited A -> B removed, the inherited B -> C replaced.
        $this->createLinks($this->getEntity($group), [
            [$ids['Current'], $ids['A'], LinkStrategyEnum::BASIC],
            [$ids['A'], $ids['B'], LinkStrategyEnum::NONE],
            [$ids['B'], $ids['C'], LinkStrategyEnum::BASIC],
        ]);
        $this->assertTrue(TreeEditor::fromDatabase($this->getEntity($group))->getTemplateVariables()['can_reset']);

        // Reset: the links of the entity are gone, the inherited ones are back, as they are in the root entity.
        $editor = $this->handle($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), new ResetAction());
        $this->assertSame([], $editor->getState()['links']);
        $this->assertSame([], $editor->getState()['removed']);
        $this->assertNotContains($ids['Current'], $editor->getState()['nodes']);
        $this->assertFalse($editor->getTemplateVariables()['can_reset']);
        // Nothing is saved until the "save" action.
        $this->assertCount(3, $this->getEntityLinks($this->getEntity($group)));
        $this->assertTrue(TreeEditor::fromState($this->getEntity($group), $editor->getState())->save());
        $this->assertSame([], $this->getEntityLinks($this->getEntity($group)));
        $this->assertSame(
            [EscalationLink::key($ids['A'], $ids['B']) => true, EscalationLink::key($ids['B'], $ids['C']) => true],
            $this->getEdgeData(TreeEditor::fromDatabase($this->getEntity($group))->getTemplateVariables(), 'replicated'),
        );

        // In the root entity, no link is left.
        $this->setEntity($root_id, true);
        $editor = $this->handle($root_id, TreeEditor::fromDatabase($root_id), new ResetAction());
        $this->assertTrue(TreeEditor::fromState($root_id, $editor->getState())->save());
        $this->assertSame([], $this->getEntityLinks($root_id));
    }

    public function testDisplay(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $editor = TreeEditor::fromDatabase($this->getEntity($group));
        $editor->apply(new SelectLinkAction(), ['link' => EscalationLink::key($ids['A'], $ids['B'])]);
        $html = $this->render($editor);

        // The graph, drawn by Cytoscape.js from its elements
        $this->assertStringContainsString('data-mo-cy', $html);
        $this->assertStringContainsString('"id":"' . EscalationLink::key($ids['A'], $ids['B']) . '"', $html);
        // The panel of the selected link, with its groups
        $this->assertStringContainsString('data-mo-action="select_node" data-mo-group="' . $ids['A'] . '"', $html);
        $this->assertStringContainsString('class="alert alert-info', $html);
        $this->assertStringContainsString('mo-gl-edge-replicated', $html);
        $this->assertStringContainsString('data-mo-action="delete_link"', $html);

        // The panel of a selected group can remove it from the graph.
        $editor->apply(new SelectNodeAction(), ['group' => $ids['A']]);
        $this->assertMatchesRegularExpression('/data-mo-action="remove_node"\s+data-mo-group="' . $ids['A'] . '"/', $this->render($editor));

        // The group just placed is the one to bring into view.
        $editor->apply(new AddNodeAction(), ['group' => $ids['D']]);
        $this->assertStringContainsString('data-mo-scroll-to="' . $ids['D'] . '"', $this->render($editor));
    }

    public function testDeleteSelection(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();

        // The selected link
        $editor = $this->handle($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), new SelectLinkAction(), ['link' => EscalationLink::key($ids['A'], $ids['B'])]);
        $editor = $this->handle($this->getEntity($group), $editor, new DeleteSelectionAction());
        $this->assertSame([EscalationLink::key($ids['A'], $ids['B'])], $editor->getState()['removed']);
        $this->assertNull($editor->getState()['selected_link']);

        // The selected group
        $editor = $this->handle($this->getEntity($group), $editor, new AddNodeAction(), ['group' => $ids['Current']]);
        $editor = $this->handle($this->getEntity($group), $editor, new SelectNodeAction(), ['group' => $ids['Current']]);
        $editor = $this->handle($this->getEntity($group), $editor, new DeleteSelectionAction());
        $this->assertNotContains($ids['Current'], $editor->getState()['nodes']);

        // Nothing selected: nothing to delete
        $state = $editor->getState();
        $editor = $this->handle($this->getEntity($group), $editor, new DeleteSelectionAction());
        $this->assertSame($state, $editor->getState());
    }

    public function testRespond(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();

        // A change, applied to the draft sent
        $editor = TreeEditor::respond($this->request($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), ['action' => 'add_node', 'group' => $ids['Current']]));
        $this->assertContains($ids['Current'], $editor->getState()['nodes']);

        // Saved, then reloaded
        $editor = TreeEditor::respond($this->request($this->getEntity($group), $editor, ['action' => 'link', 'from' => $ids['Current'], 'to' => $ids['A']]));
        $editor = TreeEditor::respond($this->request($this->getEntity($group), $editor, ['action' => 'save']));
        $this->assertSame(
            [EscalationLink::key($ids['Current'], $ids['A']) => LinkStrategyEnum::BASIC->value],
            $this->getEntityLinks($this->getEntity($group)),
        );
        $this->assertSame(Group_Link::getVersion(Group_Link::getRowsOfEntity($this->getEntity($group))), $editor->getState()['version']);

        // A draft made before links were saved by someone else is not saved over them.
        $outdated = $this->request($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), ['action' => 'save']);
        $this->createLinks($this->getEntity($group), [[$ids['Current'], $ids['C'], LinkStrategyEnum::BASIC]]);
        $editor = TreeEditor::respond($outdated);
        $this->assertStringContainsString('saved by someone else', (string) $editor->getTemplateVariables()['error']);
        $this->assertCount(2, $this->getEntityLinks($this->getEntity($group)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, class-string<\Throwable>}>
     */
    public static function invalidRequestProvider(): iterable
    {
        yield 'unknown entity' => [['entities_id' => -1], AccessDeniedHttpException::class];
        yield 'unknown action' => [['action' => 'foo'], BadRequestHttpException::class];
        yield 'invalid draft' => [['state' => '{'], BadRequestHttpException::class];
        yield 'draft not an object' => [['state' => '"foo"'], BadRequestHttpException::class];
        // The page only sends single values.
        foreach (['entities_id', 'action', 'state', 'query', 'group', 'link', 'from', 'value'] as $name) {
            yield $name . ' not a single value' => [[$name => ['x']], BadRequestHttpException::class];
        }
    }

    /**
     * @param array<string, mixed>      $input
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidRequestProvider')]
    public function testRespondRefusesInvalidRequests(array $input, string $exception): void
    {
        $this->login();
        [$group] = $this->createGraphData();

        $this->expectException($exception);
        TreeEditor::respond($input + $this->request($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), ['action' => RenderAction::getName()]));
    }

    public function testEditingNeedsTheRightToUpdateTheConfiguration(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        // The groups can be updated, not the configuration.
        $_SESSION['glpiactiveprofile']['group']  = ALLSTANDARDRIGHT;
        $_SESSION['glpiactiveprofile']['config'] = READ;
        $this->assertTrue(Group::canUpdate());
        $this->assertFalse(Config::canUpdate());

        // The links cannot be changed...
        $editor = TreeEditor::respond($this->request($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), ['action' => 'add_node', 'group' => $ids['Current']]));
        $this->assertFalse($editor->getTemplateVariables()['canedit']);
        $this->assertNotContains($ids['Current'], $editor->getState()['nodes']);

        // ...nor saved.
        $this->expectException(AccessDeniedHttpException::class);
        TreeEditor::respond($this->request($this->getEntity($group), $editor, ['action' => TreeEditor::SAVE_ACTION]));
    }

    public function testEditingWithTheRightToUpdateTheConfiguration(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        // The configuration can be updated, the groups only read.
        $_SESSION['glpiactiveprofile']['group']  = READ;
        $_SESSION['glpiactiveprofile']['config'] = READ | UPDATE;
        $this->assertFalse(Group::canUpdate());

        $editor = TreeEditor::respond($this->request($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), ['action' => 'add_node', 'group' => $ids['Current']]));
        $this->assertTrue($editor->getTemplateVariables()['canedit']);
        $editor = TreeEditor::respond($this->request($this->getEntity($group), $editor, ['action' => 'link', 'from' => $ids['Current'], 'to' => $ids['A']]));
        TreeEditor::respond($this->request($this->getEntity($group), $editor, ['action' => 'save']));
        $this->assertSame(
            [EscalationLink::key($ids['Current'], $ids['A']) => LinkStrategyEnum::BASIC->value],
            $this->getEntityLinks($this->getEntity($group)),
        );
    }

    public function testNamesAreEscaped(): void
    {
        $this->login();
        [$group] = $this->createGraphData();
        $name = '</script><img src=x onerror=alert(1)>';
        $evil = $this->createGroups([$name], $this->getEntity($group))[$name];

        // In the graph, then in the panel of the group
        $editor = $this->handle($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), new AddNodeAction(), ['group' => $evil]);
        foreach ([$editor, $this->handle($this->getEntity($group), $editor, new SelectNodeAction(), ['group' => $evil])] as $shown) {
            $html = $this->render($shown);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('</script><img', $html);
        }
    }

    public function testListOfThePlacedGroups(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        $editor = $this->handle($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), new AddNodeAction(), ['group' => $ids['Current']]);

        // By level, then by name, with the search applied
        $placed = $editor->getTemplateVariables()['placed'];
        $this->assertSame([$ids['A'], $ids['Current'], $ids['B']], array_column($placed, 'id'));
        $this->assertSame([1, 1, 2], array_column($placed, 'level'));
        $editor = $this->handle($this->getEntity($group), $editor, new RenderAction(), ['query' => 'cur']);
        $this->assertSame([false, true, false], array_column($editor->getTemplateVariables()['placed'], 'matches'));

        // Each one selects its group, without the right to edit too.
        $_SESSION['glpiactiveprofile']['config'] = READ;
        $html = $this->render(TreeEditor::fromDatabase($this->getEntity($group)));
        $this->assertStringContainsString('data-mo-action="select_node" data-mo-group="' . $ids['A'] . '"', $html);
        $this->assertStringNotContainsString('data-mo-action="add_node"', $html);
    }

    public function testActionsOfOtherPlugins(): void
    {
        $this->login();
        [$group, $ids] = $this->createGraphData();
        // The names sent by the page (see public/js/escalation_graph.js and the templates)
        $this->assertSame(
            ['render', 'clear', 'select_node', 'select_link', 'orientation', 'drawing_strategy', 'add_node', 'remove_node',
                'link', 'add_link', 'set_link_type', 'delete_link', 'delete_selection', 'reset'],
            array_keys(TreeActionRegistry::getActions()),
        );
        $this->assertNull(TreeActionRegistry::get('foo'));

        // Another plugin adds an action, sent by the page as the others.
        TreeActionRegistry::register(new class extends AbstractTreeAction {
            public static function getName(): string
            {
                return 'test_place_all';
            }

            public function apply(TreeEditor $editor, array $params): void
            {
                foreach (array_keys($editor->getTree()->getUnplacedGroups()) as $id) {
                    $editor->getTree()->addNode($id);
                }
            }
        });
        $editor = TreeEditor::respond($this->request($this->getEntity($group), TreeEditor::fromDatabase($this->getEntity($group)), ['action' => 'test_place_all']));
        $this->assertContains($ids['Current'], $editor->getState()['nodes']);
        $this->assertContains($ids['D'], $editor->getState()['nodes']);
    }
}
