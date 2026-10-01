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

use Group_Ticket;
use Change_Group;
use Group_Problem;
use DBmysql;
use Change;
use CommonDBTM;
use CommonITILActor;
use CommonITILObject;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\RichText\RichText;
use Group;
use ITILFollowup;
use Migration;
use Problem;
use Session;
use Ticket;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * An escalation of a ticket / change / problem, shown as its own entry in the
 * item timeline when the "Escalate" option is enabled for the item entity.
 */
class Escalation extends CommonDBTM
{
    public $dohistory = true;

    public static $rightname = 'ticket';

    public static function getTypeName($nb = 0): string
    {
        return _n('Escalation', 'Escalations', $nb, 'moreoptions');
    }

    public static function getIcon(): string
    {
        return 'ti ti-escalator-up';
    }

    /**
     * @return array<class-string<CommonITILObject>>
     */
    private static function getSupportedItemtypes(): array
    {
        return [Ticket::class, Change::class, Problem::class];
    }

    public static function isEnabledFor(CommonITILObject $item): bool
    {
        if (!in_array($item::class, self::getSupportedItemtypes(), true)) {
            return false;
        }

        $config = Config::getConfig((int) $item->fields['entities_id']);

        return (int) ($config->fields['escalate_is_active'] ?? 0) === 1;
    }

    /**
     * Hooked on {@link \Glpi\Plugin\Hooks::TIMELINE_ITEMS}. Adds the escalations of the item
     * to its timeline.
     *
     * @param array<string, mixed> $params Parameters passed by the hook (keys: item, timeline)
     */
    public static function showInTimeline(array $params): void
    {
        if (!isset($params['item'], $params['timeline'])) {
            return;
        }

        $item = $params['item'];
        if (!$item instanceof CommonITILObject || !self::isEnabledFor($item)) {
            return;
        }

        /** @var array<string, mixed> $timeline */
        $timeline = &$params['timeline'];
        $can_see_private = Session::haveRight('followup', ITILFollowup::SEEPRIVATE);

        $criterias = [
            'itemtype' => $item::class,
            'items_id' => $item->getID(),
        ];

        if (!$can_see_private) {
            $criterias['is_private'] = 0;
        }

        $escalations = (new self())->find($criterias);

        foreach ($escalations as $row) {
            $timeline['MoreoptionsEscalation_' . $row['id']] = [
                'type'  => self::getType(),
                'class' => 'moreoptions-escalation',
                'item'  => [
                    'id'                => $row['id'],
                    'content'           => self::getTimelineContent($row),
                    'is_content_safe'   => true,
                    'users_id'          => $row['users_id'],
                    'can_edit'          => false,
                    'timeline_position' => CommonITILObject::TIMELINE_LEFT,
                    'date_creation'     => $row['date_creation'],
                    'date_mod'          => $row['date_mod'],
                    'is_private'        => $row['is_private'],
                ],
            ];
        }
    }

    /**
     * The timeline entry of an escalation: a header line "<icon> | <source groups> -> <target group>"
     * (see escalation_timeline.html.twig, which puts it next to the "Created: ... by ..." badge),
     * followed by the escalation comment, if any, which can be collapsed.
     *
     * @param array<string, mixed> $row
     */
    private static function getTimelineContent(array $row): string
    {
        $can_view_groups = Group::canView();
        $group_link = static function (int $groups_id) use ($can_view_groups): string {
            $name = htmlescape(Dropdown::getDropdownName(Group::getTable(), $groups_id));
            if ($can_view_groups) {
                $name = sprintf('<a href="%s">%s</a>', htmlescape(Group::getFormURLWithID($groups_id)), $name);
            }

            return '<span class="moreoptions-escalation-group"><i class="ti ti-users"></i>' . $name . '</span>';
        };

        $target = $group_link((int) $row['groups_id']);
        $sources = array_map($group_link, self::getSourceGroupIds($row));

        // The same as a sentence, as the icon tooltip. Already escaped: the group names are.
        $sentence = strip_tags($sources !== []
            ? sprintf(__s('Escalate from %1$s to %2$s', 'moreoptions'), implode(', ', $sources), $target)
            : sprintf(__s('Escalate to %s', 'moreoptions'), $target));

        $content = '<div class="moreoptions-escalation-summary">'
            . '<i class="' . htmlescape(self::getIcon()) . '" title="' . $sentence . '" aria-label="' . $sentence . '" data-bs-toggle="tooltip"></i>'
            . '<span class="moreoptions-escalation-separator" aria-hidden="true">|</span>'
            . '<span class="moreoptions-escalation-groups">'
            . implode('', $sources)
            . '<i class="ti ti-arrow-right" aria-hidden="true"></i>'
            . $target
            . '</span>'
            . '</div>';

        if (!empty($row['content'])) {
            $comment_id = 'moreoptions-escalation-comment-' . (int) $row['id'];
            $content .= sprintf(
                '<button type="button" class="btn btn-sm btn-ghost-secondary moreoptions-escalation-toggle" data-bs-toggle="collapse" data-bs-target="#%1$s" aria-expanded="true" aria-controls="%1$s" title="%2$s">'
                    . '<i class="ti ti-chevron-up"></i>'
                    . '</button>'
                    . '<div id="%1$s" class="collapse show moreoptions-escalation-comment">%3$s</div>',
                htmlescape($comment_id),
                htmlescape(__('Show / hide the comment', 'moreoptions')),
                RichText::getEnhancedHtml($row['content']),
            );
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int>
     */
    private static function getSourceGroupIds(array $row): array
    {
        $groups_ids = json_decode((string) ($row['groups_ids_source'] ?? ''), true);

        return is_array($groups_ids) ? array_map(intval(...), $groups_ids) : [];
    }

    /**
     * The author is always the current user, and the source groups are the groups assigned to
     * the item before the escalation. Escalating to a group already assigned is refused.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $item = getItemForItemtype($input['itemtype'] ?? '');
        if (!$item instanceof CommonITILObject || !$item->getFromDB((int) ($input['items_id'] ?? 0))) {
            return false;
        }

        $input['users_id'] = Session::getLoginUserID();

        $groups_ids_source = [];
        $group_link = getItemForItemtype($item->grouplinkclass);
        if ($group_link instanceof CommonDBTM) {
            foreach (
                $group_link->find([
                    $item->getForeignKeyField() => $item->getID(),
                    'type'                      => CommonITILActor::ASSIGN,
                ]) as $assigned
            ) {
                $groups_ids_source[] = (int) $assigned['groups_id'];
            }
        }

        if (in_array((int) ($input['groups_id'] ?? 0), $groups_ids_source, true)) {
            Session::addMessageAfterRedirect(
                __s('This group is already assigned.', 'moreoptions'),
                false,
                ERROR,
            );
            return false;
        }

        $input['groups_ids_source'] = json_encode($groups_ids_source);

        return $input;
    }

    /**
     * Applies the escalation to the escalated item, through the group link hook (see
     * self::escalate()): assigns the target group with `_plugin_moreoptions_escalade`, which drops
     * the other assigned groups.
     */
    public function post_addItem()
    {
        $item = getItemForItemtype($this->fields['itemtype']);
        if (!$item instanceof CommonITILObject) {
            return;
        }

        $group_link = getItemForItemtype($item->grouplinkclass);
        if (!$group_link instanceof CommonITILActor) {
            return;
        }

        $group_link->add([
            $item->getForeignKeyField()    => (int) $this->fields['items_id'],
            'groups_id'                    => (int) $this->fields['groups_id'],
            'type'                         => CommonITILActor::ASSIGN,
            '_plugin_moreoptions_escalade' => true,
        ]);

        if ((int) ($this->input['add_me_as_observer'] ?? 0) === 1) {
            $this->addAuthorAsObserver($item);
        }
    }

    /**
     * Adds the author of the escalation as an observer of the escalated item, unless they already are.
     */
    private function addAuthorAsObserver(CommonITILObject $item): void
    {
        $user_link = getItemForItemtype($item->userlinkclass);
        if (!$user_link instanceof CommonITILActor) {
            return;
        }

        $input = [
            $item->getForeignKeyField() => (int) $this->fields['items_id'],
            'users_id'                  => (int) $this->fields['users_id'],
            'type'                      => CommonITILActor::OBSERVER,
        ];
        if (countElementsInTable($user_link::getTable(), $input) > 0) {
            return;
        }

        $user_link->add($input);
    }

    /**
     * Called from the {@link \Glpi\Plugin\Hooks::TIMELINE_ACTIONS} hook (see
     * Controller::showTimelineActions()). Renders the script that adds a small "Escalate" button
     * next to the "Assigned to" label, opening the escalation form in a modal.
     *
     * @param array<string, mixed> $params
     */
    public static function showEscalateButton(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof CommonITILObject || $item->isNewItem() || !$item->canAssign() || !self::isEnabledFor($item)) {
            return;
        }

        TemplateRenderer::getInstance()->display('@moreoptions/escalation_button.html.twig', [
            'marker_id' => 'moreoptions-escalate-' . $item->getType() . '-' . $item->getID(),
            'itemtype'  => $item->getType(),
            'items_id'  => $item->getID(),
        ]);
    }

    /**
     * Called from the {@link \Glpi\Plugin\Hooks::TIMELINE_ACTIONS} hook (see
     * Controller::showTimelineActions()). Renders the script that lays out the header of escalation
     * entries (summary, "Created: ... by ..." badge, collapse button, "internal" icon).
     *
     * @param array<string, mixed> $params
     */
    public static function showTimelineScripts(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof CommonITILObject || $item->isNewItem() || !self::isEnabledFor($item)) {
            return;
        }

        TemplateRenderer::getInstance()->display('@moreoptions/escalation_timeline.html.twig', [
            'marker_id' => 'moreoptions-escalation-timeline-' . $item->getType() . '-' . $item->getID(),
        ]);
    }

    /**
     * Renders the escalation form, loaded in the modal opened by the "Escalate" button (see
     * ajax/escalation_form.php).
     */
    public static function showEscalationForm(CommonITILObject $item): void
    {
        switch ($item::class) {
            case Ticket::class:
                $groups = new Group_Ticket();
                break;
            case Change::class:
                $groups = new Change_Group();
                break;
            case Problem::class:
                $groups = new Group_Problem();
                break;
            default:
                return;
        }

        $groups = $groups->find([strtolower($item::class) . 's_id' => $item->getID(), 'type' => CommonITILActor::ASSIGN]);
        foreach ($groups as $key => $row) {
            $groups_used[$key] = (int) $row['groups_id'];
        }

        $config = Config::getConfig((int) $item->fields['entities_id']);

        TemplateRenderer::getInstance()->display('@moreoptions/escalation_form.html.twig', [
            'item' => $item,
            'groups_used' => $groups_used ?? [],
            // Default values of the form options
            'config' => [
                'assign_to_observer' => (int) ($config->fields['escalade_assign_me_as_obsever_by_default'] ?? 0) === 1,
                'is_private'         => (int) ($config->fields['escalade_is_private_by_default'] ?? 0) === 1,
            ],
        ]);
    }

    /**
     * Hooked on {@link \Glpi\Plugin\Hooks::ITEM_ADD} for Group_Ticket, Change_Group and
     * Group_Problem. Only group links added with `_plugin_moreoptions_escalade => true` in their
     * input are escalations: the new group then replaces the previously assigned ones.
     */
    public static function escalate(CommonITILActor $group_link): void
    {
        if (
            !is_array($group_link->input)
            || !($group_link->input['_plugin_moreoptions_escalade'] ?? false)
            || (int) $group_link->fields['type'] !== CommonITILActor::ASSIGN
        ) {
            return;
        }

        self::keepOnlyAssignedGroup($group_link);

        $item = getItemForItemtype($group_link::$itemtype_1 ?? '');
        if (!$item instanceof CommonITILObject || !$item->getFromDB((int) $group_link->fields[$group_link::$items_id_1])) {
            return;
        }

        self::removeTechnician($item);
        self::changeStatusAfterEscalation($item);
    }

    /**
     * Keep only the given group assigned to its item: drop the other assigned groups.
     */
    private static function keepOnlyAssignedGroup(CommonITILActor $group_link): void
    {
        $items_id_field = $group_link::$items_id_1;

        $previous_links = $group_link->find([
            $items_id_field => (int) $group_link->fields[$items_id_field],
            'type'          => CommonITILActor::ASSIGN,
            'NOT'           => ['id' => $group_link->getID()],
        ]);
        foreach ($previous_links as $previous_link) {
            (new ($group_link::class)())->delete(['id' => $previous_link['id']]);
        }
    }

    /**
     * Drop the technicians assigned to the escalated item, when the "remove technician" option
     * is enabled for its entity.
     */
    private static function removeTechnician(CommonITILObject $item): void
    {
        $config = Config::getConfig((int) $item->fields['entities_id']);
        if ((int) ($config->fields['escalate_remove_technician'] ?? 0) !== 1) {
            return;
        }

        $user_link = getItemForItemtype($item->userlinkclass);
        if (!$user_link instanceof CommonITILActor) {
            return;
        }

        $technician_links = $user_link->find([
            $item->getForeignKeyField() => $item->getID(),
            'type'                      => CommonITILActor::ASSIGN,
        ]);
        foreach ($technician_links as $technician_link) {
            (new ($user_link::class)())->delete(['id' => $technician_link['id']]);
        }
    }

    /**
     * Set the status configured for its entity on the escalated item, unless it already has it.
     */
    private static function changeStatusAfterEscalation(CommonITILObject $item): void
    {
        // Reload: removing the actors (see self::removeTechnician()) may have changed the status.
        if (!$item->getFromDB($item->getID())) {
            return;
        }

        $config = Config::getConfig((int) $item->fields['entities_id']);
        $new_status = (int) ($config->fields['escalade_status_after_escalation_' . strtolower($item::class)] ?? 0);
        if ($new_status === 0 || (int) $item->fields['status'] === $new_status) {
            return;
        }

        $item->update(['id' => $item->getID(), 'status' => $new_status]);
    }

    public static function install(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage('Installing ' . $table);
            $query = "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int unsigned NOT NULL DEFAULT '0',
                `users_id` int unsigned NOT NULL DEFAULT '0',
                `groups_ids_source` text,
                `groups_id` int unsigned NOT NULL DEFAULT '0',
                `content` longtext,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `is_private` tinyint NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `users_id` (`users_id`),
                KEY `groups_id` (`groups_id`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
              ";
            $DB->doQuery($query);
        }

        // Source groups, formerly a single `groups_id_source`, are now a JSON list.
        if (!$DB->fieldExists($table, 'groups_ids_source')) {
            $migration->addField($table, 'groups_ids_source', 'text', ['after' => 'users_id']);
            $migration->migrationOneTable($table);

            if ($DB->fieldExists($table, 'groups_id_source')) {
                foreach ($DB->request(['FROM' => $table, 'WHERE' => ['groups_id_source' => ['>', 0]]]) as $row) {
                    $DB->update(
                        $table,
                        ['groups_ids_source' => json_encode([(int) $row['groups_id_source']])],
                        ['id' => $row['id']],
                    );
                }
            }
        }

        if ($DB->fieldExists($table, 'groups_id_source')) {
            $migration->dropKey($table, 'groups_id_source');
            $migration->dropField($table, 'groups_id_source');
        }

        if (!$DB->fieldExists($table, 'is_private')) {
            $migration->addField($table, 'is_private', 'bool', ['value' => '0']);
        }

        $migration->executeMigration();
    }

    public static function uninstall(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }
}
