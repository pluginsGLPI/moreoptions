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

namespace GlpiPlugin\Moreoptions;

use CommonDBTM;
use CommonITILObject;
use DBmysql;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Event;
use Group;
use Migration;
use Rule;
use RuleAction;
use RuleChange;
use RuleProblem;
use RuleTicket;
use Ticket;

/**
 * The "Escalate to group" action of the ticket / change / problem business rules.
 *
 * Like the core "Technician group" action, it only takes a group, with the "Assign" action type:
 * the core then puts the group in the item input (see RuleCommonITILObject::executeActions()), and
 * the escalation itself is done once the item is saved (see self::escalateFromRules()), through an
 * {@link Escalation}.
 *
 * Also lists the "Assign: Technician group" actions of the rules, so that they can be switched to
 * "Assign: Escalate to group" (and back).
 */
final class EscalationRule
{
    /**
     * The rule action field, and the item input key it fills.
     */
    public const ACTION_FIELD = '_plugin_moreoptions_escalate_groups_id';

    /**
     * The core "Technician group" rule action field.
     */
    public const CORE_FIELD = '_groups_id_assign';

    /**
     * @return array<class-string<Rule>>
     */
    public static function getRuleClasses(): array
    {
        return [RuleTicket::class, RuleChange::class, RuleProblem::class];
    }

    /**
     * Called by the `getRuleActions` plugin hook (see hook.php).
     *
     * @param array<string, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    public static function getRuleActions(array $params): array
    {
        if (!in_array($params['rule_itemtype'] ?? '', self::getRuleClasses(), true)) {
            return [];
        }

        return [
            self::ACTION_FIELD => [
                'name'          => __('Escalate to group', 'moreoptions'),
                'type'          => 'dropdown',
                'table'         => Group::getTable(),
                'condition'     => ['is_assign' => 1],
                'force_actions' => ['assign'],
            ],
        ];
    }

    /**
     * Hooked on {@link \Glpi\Plugin\Hooks::PRE_ITEM_ADD} / {@link \Glpi\Plugin\Hooks::PRE_ITEM_UPDATE}
     * for tickets, changes and problems, which run before the rules. The escalation group may only
     * come from the rules: drop it from the user input, otherwise anyone able to update an item could
     * escalate it.
     */
    public static function dropFromUserInput(CommonDBTM $item): void
    {
        if (is_array($item->input)) {
            unset($item->input[self::ACTION_FIELD]);
        }
    }

    /**
     * Hooked on {@link \Glpi\Plugin\Hooks::ITEM_ADD} for tickets, changes and problems: called once
     * the actors of the new item are saved.
     */
    public static function escalateAfterAdd(CommonDBTM $item): void
    {
        if ($item instanceof CommonITILObject) {
            self::escalate($item);
        }
    }

    /**
     * Hooked on {@link \Glpi\Plugin\Hooks::POST_PREPAREUPDATE} for tickets, changes and problems:
     * called after the rules, on every update. ITEM_UPDATE would be too late (the actors sent by the
     * form are saved after it, adding back the previous group and technicians) and is skipped when no
     * column of the item changes (e.g. a rule matching on an actor change only).
     *
     * The item is escalated right away, then the input of the update is aligned on the escalation
     * result, otherwise the update would undo it.
     */
    public static function escalateBeforeUpdate(CommonDBTM $item): void
    {
        if (!$item instanceof CommonITILObject) {
            return;
        }

        $groups_id = self::escalate($item);
        if ($groups_id === null || !is_array($item->input)) {
            return;
        }

        // Assigned groups: only the target one remains.
        $item->input['_groups_id_assign'] = [$groups_id];
        unset($item->input['_additional_groups_assigns'], $item->input['_groups_id_assign_deleted']);

        // Assigned technicians: dropped by the escalation when the entity says so.
        $config = Config::getConfig((int) $item->fields['entities_id']);
        if ((int) ($config->fields['escalate_remove_technician'] ?? 0) === 1) {
            $item->input['_users_id_assign'] = [];
            unset(
                $item->input['_additional_assigns'],
                $item->input['_users_id_assign_deleted'],
                $item->input['_users_id_assign_notif'],
            );
        }

        // Status: may have been changed by the escalation. Also set on the fields, so that the update
        // does not see (and log) it as a change.
        $escalated = getItemForItemtype($item::class);
        if ($escalated instanceof CommonITILObject && $escalated->getFromDB($item->getID())) {
            $item->fields['status'] = $escalated->fields['status'];
            if (array_key_exists('status', $item->input)) {
                $item->input['status'] = $escalated->fields['status'];
            }
        }
    }

    /**
     * Escalates the item to the group set by an "Escalate to group" rule action, if any.
     *
     * When the item cannot be escalated (escalation not enabled for its entity, or see
     * Escalation::getEscalationBlocker()), the reason goes to the GLPI events log: there is nobody to
     * report it to when the rules run from the mail collector.
     *
     * @return int|null The group the item was escalated to, null when it was not
     */
    private static function escalate(CommonITILObject $item): ?int
    {
        if (!is_array($item->input) || !isset($item->input[self::ACTION_FIELD])) {
            return null;
        }

        $groups_id = (int) $item->input[self::ACTION_FIELD];
        unset($item->input[self::ACTION_FIELD]);

        $blocker = Escalation::isEnabledFor($item)
            ? Escalation::getEscalationBlocker($item, $groups_id)
            : __('Escalation is not enabled for the entity of the item.', 'moreoptions');
        if ($blocker !== null) {
            Event::log(
                $item->getID(),
                strtolower($item::class),
                3,
                $item instanceof Ticket ? 'tracking' : 'maintain',
                sprintf(
                    __('The "%1$s" rule action did not escalate the item to the group "%2$s": %3$s', 'moreoptions'),
                    __('Escalate to group', 'moreoptions'),
                    Dropdown::getDropdownName(Group::getTable(), $groups_id),
                    $blocker,
                ),
            );
            return null;
        }

        $config = Config::getConfig((int) $item->fields['entities_id']);

        $escalation = new Escalation();
        $escalated = $escalation->add([
            'itemtype'   => $item::class,
            'items_id'   => $item->getID(),
            'groups_id'  => $groups_id,
            'content'    => __('Escalated by a business rule.', 'moreoptions'),
            'is_private' => (int) ($config->fields['escalade_is_private_by_default'] ?? 0),
        ]);

        return $escalated !== false ? $groups_id : null;
    }

    /**
     * Whether the current user can see the rules the "Review rules" page lists.
     */
    public static function canManageRules(): bool
    {
        foreach (self::getRuleClasses() as $rule_class) {
            if ($rule_class::canView()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the "Review rules" page has a rule to switch to "Escalate to group": shows its link in
     * the entity configuration (see escalation_rules_banner.html.twig).
     */
    public static function hasRulesToReview(): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $criteria = self::getGroupAssignActionsCriteria([self::CORE_FIELD]);
        if ($criteria === null) {
            return false;
        }

        $row = $DB->request(['COUNT' => 'cpt'] + $criteria)->current();

        return (int) ($row['cpt'] ?? 0) > 0;
    }

    /**
     * The "Assign" actions on a technician group (core "Technician group" or "Escalate to group")
     * of the rules the current user can see, sorted by rule type and ranking.
     *
     * @return array<int, array{id: int, field: string, rule_class: class-string<Rule>, rule_type: string, rule_link: string, is_active: bool, group: string, can_update: bool}>
     */
    public static function getGroupAssignActions(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $criteria = self::getGroupAssignActionsCriteria([self::CORE_FIELD, self::ACTION_FIELD]);
        if ($criteria === null) {
            return [];
        }

        $rules_table   = Rule::getTable();
        $actions_table = RuleAction::getTable();

        $iterator = $DB->request([
            'SELECT' => [
                $actions_table . '.id',
                $actions_table . '.field',
                $actions_table . '.value',
                $rules_table . '.id AS rules_id',
                $rules_table . '.sub_type',
                $rules_table . '.is_active',
            ],
            'ORDER'  => [$rules_table . '.sub_type', $rules_table . '.ranking', $actions_table . '.id'],
        ] + $criteria);

        $actions = [];
        foreach ($iterator as $row) {
            $rule = getItemForItemtype($row['sub_type']);
            if (!$rule instanceof Rule) {
                continue;
            }

            // Also loads the rule, for getLink().
            $can_update = $rule->can((int) $row['rules_id'], UPDATE);

            $actions[] = [
                'id'         => (int) $row['id'],
                'field'      => (string) $row['field'],
                'rule_class' => $rule::class,
                'rule_type'  => $rule->getTitle(),
                'rule_link'  => $rule->getLink(),
                'is_active'  => (int) $row['is_active'] === 1,
                'group'      => Dropdown::getDropdownName(Group::getTable(), (int) $row['value']),
                'can_update' => $can_update,
            ];
        }

        return $actions;
    }

    /**
     * The "Assign" actions on the given fields of the rules the current user can see, null when they
     * cannot see any rule.
     *
     * @param array<string> $fields
     * @return array<string, mixed>|null
     */
    private static function getGroupAssignActionsCriteria(array $fields): ?array
    {
        $rule_classes = array_values(array_filter(
            self::getRuleClasses(),
            static fn(string $rule_class): bool => $rule_class::canView(),
        ));
        if ($rule_classes === []) {
            return null;
        }

        $rules_table   = Rule::getTable();
        $actions_table = RuleAction::getTable();

        return [
            'FROM'       => $actions_table,
            'INNER JOIN' => [
                $rules_table => [
                    'ON' => [
                        $actions_table => 'rules_id',
                        $rules_table   => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                $actions_table . '.field'       => $fields,
                $actions_table . '.action_type' => 'assign',
                $rules_table . '.sub_type'      => $rule_classes,
            ] + getEntitiesRestrictCriteria($rules_table, '', '', true),
        ];
    }

    /**
     * Applies the fields chosen on the "Review rules" page: switches each "Assign" technician group
     * action whose field changed to "Escalate to group" or back to the core "Technician group".
     *
     * @param array<mixed, mixed> $fields Rule action id => self::CORE_FIELD or self::ACTION_FIELD
     * @return array{updated: int, failed: int}
     */
    public static function switchActions(array $fields): array
    {
        $result = ['updated' => 0, 'failed' => 0];
        foreach ($fields as $ruleactions_id => $field) {
            $action = new RuleAction();
            if (!$action->getFromDB((int) $ruleactions_id) || !in_array($field, [self::CORE_FIELD, self::ACTION_FIELD], true)) {
                $result['failed']++;
                continue;
            }

            if ($action->fields['field'] === $field) {
                continue;
            }

            if (self::switchAction($action, $field)) {
                $result['updated']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    private static function switchAction(RuleAction $action, string $field): bool
    {
        if (
            $action->fields['action_type'] !== 'assign'
            || !in_array($action->fields['field'], [self::CORE_FIELD, self::ACTION_FIELD], true)
        ) {
            return false;
        }

        $rule = Rule::getRuleObjectByID((int) $action->fields['rules_id']);
        if (
            $rule === null
            || !in_array($rule::class, self::getRuleClasses(), true)
            || !$rule->can((int) $action->fields['rules_id'], UPDATE)
            || !$action->update(['id' => $action->getID(), 'field' => $field])
        ) {
            return false;
        }

        // As the core does when an action is added or removed (see RuleAction::post_addItem()).
        $rule->update(['id' => $rule->getID(), 'date_mod' => $_SESSION['glpi_currenttime']]);

        return true;
    }

    /**
     * Renders the "Review rules" page (see front/escalation_rules.php).
     */
    public static function showRulesList(): void
    {
        // One table per rule type, as the rules lists.
        $actions = self::getGroupAssignActions();

        $actions_by_type = [];
        foreach ($actions as $action) {
            $actions_by_type[$action['rule_class']] ??= ['title' => $action['rule_type'], 'actions' => []];
            $actions_by_type[$action['rule_class']]['actions'][] = $action;
        }

        TemplateRenderer::getInstance()->display('@moreoptions/escalation_rules.html.twig', [
            'actions_by_type' => $actions_by_type,
            'can_update_any'  => in_array(true, array_column($actions, 'can_update'), true),
            'field_labels'    => [
                self::CORE_FIELD   => __('Technician group'),
                self::ACTION_FIELD => __('Escalate to group', 'moreoptions'),
            ],
        ]);
    }

    /**
     * Once the plugin is uninstalled, the "Escalate to group" rule actions would be unknown, and
     * their rules would silently stop assigning a group: switch them back to the core
     * "Technician group".
     */
    public static function uninstall(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $migration->displayMessage('Switching "Escalate to group" rule actions back to "Technician group"');
        $DB->update(
            RuleAction::getTable(),
            ['field' => self::CORE_FIELD],
            ['field' => self::ACTION_FIELD],
        );
    }
}
