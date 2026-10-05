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
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Moreoptions\Tests\Units;

use Change;
use CommonITILActor;
use CommonITILObject;
use Entity;
use Glpi\Event;
use Glpi\Tests\RuleBuilder;
use GlpiPlugin\Moreoptions\Config;
use GlpiPlugin\Moreoptions\Escalation;
use GlpiPlugin\Moreoptions\EscalationRule;
use GlpiPlugin\Moreoptions\Tests\MoreOptionsTestCase;
use Group;
use Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use Problem;
use Rule;
use RuleAction;
use RuleChange;
use RuleCommonITILObject;
use RuleProblem;
use RuleRight;
use RuleTicket;
use Symfony\Component\DomCrawler\Crawler;
use Ticket;
use User;

use function Safe\ob_get_clean;
use function Safe\ob_start;

class EscalationRuleTest extends MoreOptionsTestCase
{
    /**
     * @return iterable<string, array{class-string<CommonITILObject>, class-string<RuleCommonITILObject>}>
     */
    public static function itemtypeProvider(): iterable
    {
        yield Ticket::class => [Ticket::class, RuleTicket::class];
        yield Change::class => [Change::class, RuleChange::class];
        yield Problem::class => [Problem::class, RuleProblem::class];
    }

    /**
     * @param class-string<CommonITILObject>     $itemtype
     * @param class-string<RuleCommonITILObject> $rule_class
     */
    #[DataProvider('itemtypeProvider')]
    public function testActionIsAvailableInRules(string $itemtype, string $rule_class): void
    {
        $rule = getItemForItemtype($rule_class);
        $this->assertInstanceOf(RuleCommonITILObject::class, $rule);

        $action = $rule->getAllActions()[EscalationRule::ACTION_FIELD] ?? null;
        $this->assertIsArray($action);
        $this->assertSame(['assign'], $action['force_actions'] ?? null);
        $this->assertSame(Group::getTable(), $action['table'] ?? null);
    }

    public function testActionIsNotAvailableInOtherRules(): void
    {
        $this->assertArrayNotHasKey(EscalationRule::ACTION_FIELD, (new RuleRight())->getAllActions());
    }

    /**
     * @param class-string<CommonITILObject>     $itemtype
     * @param class-string<RuleCommonITILObject> $rule_class
     */
    #[DataProvider('itemtypeProvider')]
    public function testEscalationOnAdd(string $itemtype, string $rule_class): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);
        $this->configureEscalation($entities_id, ['escalate_is_active' => 1]);

        $source_group = $this->createGroup($entities_id, 'Source group');
        $target_group = $this->createGroup($entities_id, 'Target group');
        $this->createEscalationRule($rule_class, RuleCommonITILObject::ONADD, $target_group);

        $item = $this->createItem($itemtype, [
            'name'              => 'Please escalate-me',
            'content'           => 'Test content',
            'entities_id'       => $entities_id,
            '_groups_id_assign' => $source_group->getID(),
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        // The target group replaced the group assigned on creation
        $this->assertSame([$target_group->getID()], $this->getAssignedGroupIds($item));

        $escalations = (new Escalation())->find(['itemtype' => $itemtype, 'items_id' => $item->getID()]);
        $this->assertCount(1, $escalations);
        $escalation = reset($escalations);
        $this->assertSame($target_group->getID(), (int) $escalation['groups_id']);
        $this->assertSame('[' . $source_group->getID() . ']', $escalation['groups_ids_source']);
    }

    /**
     * @param class-string<CommonITILObject>     $itemtype
     * @param class-string<RuleCommonITILObject> $rule_class
     */
    #[DataProvider('itemtypeProvider')]
    public function testEscalationOnUpdate(string $itemtype, string $rule_class): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);
        $this->configureEscalation($entities_id, ['escalate_is_active' => 1]);

        $source_group = $this->createGroup($entities_id, 'Source group');
        $target_group = $this->createGroup($entities_id, 'Target group');
        $this->createEscalationRule($rule_class, RuleCommonITILObject::ONUPDATE, $target_group);

        $item = $this->createItem($itemtype, [
            'name'              => 'Test escalation',
            'content'           => 'Test content',
            'entities_id'       => $entities_id,
            '_groups_id_assign' => $source_group->getID(),
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);
        $this->assertSame([$source_group->getID()], $this->getAssignedGroupIds($item));

        $this->updateItem($itemtype, $item->getID(), ['name' => 'Please escalate-me']);

        $this->assertSame([$target_group->getID()], $this->getAssignedGroupIds($item));
        $this->assertSame(1, countElementsInTable(Escalation::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $item->getID(),
        ]));
    }

    /**
     * @param class-string<CommonITILObject>     $itemtype
     * @param class-string<RuleCommonITILObject> $rule_class
     */
    #[DataProvider('itemtypeProvider')]
    public function testNoEscalationWhenDisabledForEntity(string $itemtype, string $rule_class): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);
        $this->configureEscalation($entities_id, ['escalate_is_active' => 0]);

        $source_group = $this->createGroup($entities_id, 'Source group');
        $target_group = $this->createGroup($entities_id, 'Target group');
        $this->createEscalationRule($rule_class, RuleCommonITILObject::ONADD, $target_group);

        $item = $this->createItem($itemtype, [
            'name'              => 'Please escalate-me',
            'content'           => 'Test content',
            'entities_id'       => $entities_id,
            '_groups_id_assign' => $source_group->getID(),
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        // Nothing happened...
        $this->assertSame([$source_group->getID()], $this->getAssignedGroupIds($item));
        $this->assertSame(0, countElementsInTable(Escalation::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $item->getID(),
        ]));

        // ... and the events log says why
        $events = (new Event())->find([
            'type'     => strtolower($itemtype),
            'items_id' => $item->getID(),
            'message'  => ['LIKE', '%Escalation is not enabled for the entity of the item.%'],
        ]);
        $this->assertCount(1, $events);
        $this->assertStringContainsString('Target group', reset($events)['message']);
    }

    /**
     * Saving the item form sends back its current actors and status: they must not undo the escalation.
     *
     * @param class-string<CommonITILObject>     $itemtype
     * @param class-string<RuleCommonITILObject> $rule_class
     */
    #[DataProvider('itemtypeProvider')]
    public function testEscalationOnUpdateFromForm(string $itemtype, string $rule_class): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);

        $status_field = 'escalade_status_after_escalation_' . strtolower($itemtype);
        $this->configureEscalation($entities_id, [
            'escalate_is_active'         => 1,
            'escalate_remove_technician' => 1,
            $status_field                => CommonITILObject::WAITING,
        ]);

        $source_group = $this->createGroup($entities_id, 'Source group');
        $target_group = $this->createGroup($entities_id, 'Target group');
        $technician_id = getItemByTypeName(User::class, 'tech', true);
        $this->createEscalationRule($rule_class, RuleCommonITILObject::ONUPDATE, $target_group);

        $item = $this->createItem($itemtype, [
            'name'              => 'Test escalation',
            'content'           => 'Test content',
            'entities_id'       => $entities_id,
            '_groups_id_assign' => $source_group->getID(),
            '_users_id_assign'  => $technician_id,
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);
        $this->assertTrue($item->getFromDB($item->getID()));
        $status_before = (int) $item->fields['status'];
        $this->assertNotSame(CommonITILObject::WAITING, $status_before);

        $this->assertTrue($item->update([
            'id'      => $item->getID(),
            'name'    => 'Please escalate-me',
            'status'  => $status_before,
            '_actors' => [
                'requester' => [],
                'observer'  => [],
                'assign'    => [
                    ['itemtype' => Group::class, 'items_id' => $source_group->getID()],
                    ['itemtype' => User::class, 'items_id' => $technician_id, 'use_notification' => 1, 'alternative_email' => ''],
                ],
            ],
        ]));

        $this->assertSame([$target_group->getID()], $this->getAssignedGroupIds($item));
        $this->assertSame([], $this->getAssignedUserIds($item));
        $this->assertTrue($item->getFromDB($item->getID()));
        $this->assertSame(CommonITILObject::WAITING, (int) $item->fields['status']);
    }

    /**
     * A rule matching on an actor change only escalates the item, although none of its columns changes.
     *
     * @param class-string<CommonITILObject>     $itemtype
     * @param class-string<RuleCommonITILObject> $rule_class
     */
    #[DataProvider('itemtypeProvider')]
    public function testEscalationOnActorChangeOnly(string $itemtype, string $rule_class): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);
        $this->configureEscalation($entities_id, ['escalate_is_active' => 1]);

        $target_group = $this->createGroup($entities_id, 'Target group');
        $technician_id = getItemByTypeName(User::class, 'tech', true);
        $this->createRule(
            (new RuleBuilder('Escalation on technician', $rule_class))
                ->setEntity(0)
                ->setCondtion(RuleCommonITILObject::ONUPDATE)
                ->addCriteria('_users_id_assign', Rule::PATTERN_IS, $technician_id)
                ->addAction('assign', EscalationRule::ACTION_FIELD, $target_group->getID()),
        );

        $item = $this->createItem($itemtype, [
            'name'        => 'Test escalation',
            'content'     => 'Test content',
            'entities_id' => $entities_id,
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        $this->assertTrue($item->update([
            'id'      => $item->getID(),
            '_actors' => [
                'requester' => [],
                'observer'  => [],
                'assign'    => [
                    ['itemtype' => User::class, 'items_id' => $technician_id, 'use_notification' => 1, 'alternative_email' => ''],
                ],
            ],
        ]));

        $this->assertSame([$target_group->getID()], $this->getAssignedGroupIds($item));
        $this->assertSame(1, countElementsInTable(Escalation::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $item->getID(),
        ]));
    }

    /**
     * @param class-string<CommonITILObject>     $itemtype
     * @param class-string<RuleCommonITILObject> $rule_class
     */
    #[DataProvider('itemtypeProvider')]
    public function testEscalationCannotComeFromUserInput(string $itemtype, string $rule_class): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);

        $target_group = $this->createGroup($entities_id, 'Target group');

        $item = $this->createItem($itemtype, [
            'name'                            => 'Test escalation',
            'content'                         => 'Test content',
            'entities_id'                     => $entities_id,
            EscalationRule::ACTION_FIELD      => $target_group->getID(),
        ], [EscalationRule::ACTION_FIELD]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        $this->updateItem($itemtype, $item->getID(), [
            'name'                       => 'Test escalation updated',
            EscalationRule::ACTION_FIELD => $target_group->getID(),
        ], [EscalationRule::ACTION_FIELD]);

        $this->assertSame([], $this->getAssignedGroupIds($item));
        $this->assertSame(0, countElementsInTable(Escalation::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $item->getID(),
        ]));
    }

    public function testSwitchAction(): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);

        $group = $this->createGroup($entities_id, 'Rule group');
        $rule = $this->createRule(
            (new RuleBuilder('Technician group rule', RuleTicket::class))
                ->setEntity(0)
                ->addCriteria('name', Rule::PATTERN_CONTAIN, 'anything')
                ->addAction('assign', EscalationRule::CORE_FIELD, $group->getID())
                ->addAction('append', EscalationRule::CORE_FIELD, $group->getID()),
        );

        $assign = $this->getRuleAction($rule, 'assign');
        $append = $this->getRuleAction($rule, 'append');

        // Only the "Assign" action is listed
        $listed = array_column(EscalationRule::getGroupAssignActions(), 'field', 'id');
        $this->assertSame(EscalationRule::CORE_FIELD, $listed[$assign->getID()] ?? null);
        $this->assertArrayNotHasKey($append->getID(), $listed);

        // Switch to "Escalate to group", then back
        $this->assertSame(['updated' => 1, 'failed' => 0], EscalationRule::switchActions([$assign->getID() => EscalationRule::ACTION_FIELD]));
        $this->assertTrue($assign->getFromDB($assign->getID()));
        $this->assertSame(EscalationRule::ACTION_FIELD, $assign->fields['field']);
        $this->assertSame((string) $group->getID(), (string) $assign->fields['value']);

        $this->assertSame(['updated' => 1, 'failed' => 0], EscalationRule::switchActions([$assign->getID() => EscalationRule::CORE_FIELD]));
        $this->assertTrue($assign->getFromDB($assign->getID()));
        $this->assertSame(EscalationRule::CORE_FIELD, $assign->fields['field']);

        // An "Add" action cannot be switched, nor can an action be switched to any other field
        $this->assertSame(['updated' => 0, 'failed' => 1], EscalationRule::switchActions([$append->getID() => EscalationRule::ACTION_FIELD]));
        $this->assertSame(['updated' => 0, 'failed' => 1], EscalationRule::switchActions([$assign->getID() => 'name']));
        $this->assertTrue($append->getFromDB($append->getID()));
        $this->assertSame(EscalationRule::CORE_FIELD, $append->fields['field']);
        $this->assertTrue($assign->getFromDB($assign->getID()));
        $this->assertSame(EscalationRule::CORE_FIELD, $assign->fields['field']);
    }

    public function testSwitchActions(): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);

        $group = $this->createGroup($entities_id, 'Rule group');
        $actions = [];
        foreach (['First', 'Second'] as $name) {
            $rule = $this->createRule(
                (new RuleBuilder($name . ' rule', RuleTicket::class))
                    ->setEntity(0)
                    ->addCriteria('name', Rule::PATTERN_CONTAIN, 'anything')
                    ->addAction('assign', EscalationRule::CORE_FIELD, $group->getID()),
            );
            $actions[] = $this->getRuleAction($rule, 'assign');
        }

        // Only the changed actions are counted, unknown ones fail
        $result = EscalationRule::switchActions([
            $actions[0]->getID() => EscalationRule::ACTION_FIELD,
            $actions[1]->getID() => EscalationRule::CORE_FIELD,
            0                    => EscalationRule::ACTION_FIELD,
        ]);
        $this->assertSame(['updated' => 1, 'failed' => 1], $result);

        $this->assertTrue($actions[0]->getFromDB($actions[0]->getID()));
        $this->assertSame(EscalationRule::ACTION_FIELD, $actions[0]->fields['field']);
        $this->assertTrue($actions[1]->getFromDB($actions[1]->getID()));
        $this->assertSame(EscalationRule::CORE_FIELD, $actions[1]->fields['field']);
    }

    public function testUninstallSwitchesActionsBackToTechnicianGroup(): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);

        $group = $this->createGroup($entities_id, 'Rule group');
        $rule = $this->createEscalationRule(RuleTicket::class, RuleCommonITILObject::ONADD, $group);
        $action = $this->getRuleAction($rule, 'assign');
        $this->assertSame(EscalationRule::ACTION_FIELD, $action->fields['field']);

        EscalationRule::uninstall(new Migration(PLUGIN_MOREOPTIONS_VERSION));

        $this->assertTrue($action->getFromDB($action->getID()));
        $this->assertSame(EscalationRule::CORE_FIELD, $action->fields['field']);
        $this->assertSame((string) $group->getID(), (string) $action->fields['value']);
        $this->assertSame('assign', $action->fields['action_type']);
    }

    public function testRulesPageAndBanner(): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);

        $entity = getItemByTypeName(Entity::class, '_test_root_entity');
        $group = $this->createGroup($entities_id, 'Rule group');

        // No rule assigns a technician group: no link to the rules page
        $this->assertFalse(EscalationRule::hasRulesToReview());
        $this->assertCount(0, $this->getRulesPageLinks($entity));

        $rule = $this->createRule(
            (new RuleBuilder('Technician group rule', RuleTicket::class))
                ->setEntity(0)
                ->addCriteria('name', Rule::PATTERN_CONTAIN, 'anything')
                ->addAction('assign', EscalationRule::CORE_FIELD, $group->getID()),
        );
        $action = $this->getRuleAction($rule, 'assign');
        $this->assertTrue(EscalationRule::hasRulesToReview());

        // The rules page lists the action, with its field dropdown, in a single form with one save button
        ob_start();
        EscalationRule::showRulesList();
        $crawler = new Crawler(ob_get_clean());

        $this->assertCount(1, $crawler->filter('form'));
        $this->assertCount(1, $crawler->filter('form button[name="update"]'));
        $select = $crawler->filter('select[name="fields[' . $action->getID() . ']"]');
        $this->assertCount(1, $select);
        $this->assertSame(EscalationRule::CORE_FIELD, $select->filter('option[selected]')->attr('value'));
        $this->assertCount(1, $select->filter('option[value="' . EscalationRule::ACTION_FIELD . '"]'));

        // The entity configuration links to it
        $this->assertCount(1, $this->getRulesPageLinks($entity));
    }

    private function getRulesPageLinks(Entity $entity): Crawler
    {
        ob_start();
        Config::showForEntity($entity);

        return (new Crawler(ob_get_clean()))->filter('a[href$="/plugins/moreoptions/front/escalation_rules.php"]');
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function configureEscalation(int $entities_id, array $fields): void
    {
        $config = Config::getConfig($entities_id, false);
        if ($config->isNewItem()) {
            $this->createTestConfig(['entities_id' => $entities_id] + $fields);
        } else {
            $this->updateTestConfig($config, $fields);
        }
    }

    /**
     * @return array<int>
     */
    private function getAssignedUserIds(CommonITILObject $item): array
    {
        $user_link = getItemForItemtype($item->userlinkclass);
        $this->assertInstanceOf(CommonITILActor::class, $user_link);

        return array_map(
            static fn(array $row): int => (int) $row['users_id'],
            array_values($user_link->find([
                $item->getForeignKeyField() => $item->getID(),
                'type'                      => CommonITILActor::ASSIGN,
            ], ['id ASC'])),
        );
    }

    /**
     * @param class-string<RuleCommonITILObject> $rule_class
     */
    private function createEscalationRule(string $rule_class, int $condition, Group $group): Rule
    {
        $builder = (new RuleBuilder('Escalation rule', $rule_class))
            ->setEntity(0)
            ->setCondtion($condition)
            ->addCriteria('name', Rule::PATTERN_CONTAIN, 'escalate-me')
            ->addAction('assign', EscalationRule::ACTION_FIELD, $group->getID());

        return $this->createRule($builder);
    }

    private function getRuleAction(Rule $rule, string $action_type): RuleAction
    {
        $action = new RuleAction();
        $this->assertTrue($action->getFromDBByCrit(['rules_id' => $rule->getID(), 'action_type' => $action_type]));

        return $action;
    }

    private function createGroup(int $entities_id, string $name): Group
    {
        $group = $this->createItem(Group::class, [
            'name'         => $name,
            'entities_id'  => $entities_id,
            'is_recursive' => 1,
            'is_assign'    => 1,
        ]);
        $this->assertInstanceOf(Group::class, $group);

        return $group;
    }

    /**
     * @return array<int>
     */
    private function getAssignedGroupIds(CommonITILObject $item): array
    {
        $group_link = getItemForItemtype($item->grouplinkclass);
        $this->assertInstanceOf(CommonITILActor::class, $group_link);

        return array_map(
            static fn(array $row): int => (int) $row['groups_id'],
            array_values($group_link->find([
                $item->getForeignKeyField() => $item->getID(),
                'type'                      => CommonITILActor::ASSIGN,
            ], ['id ASC'])),
        );
    }
}
