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
 * @copyright Copyright (C) 2022-2024 by Cancel Send plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/pluginsGLPI/moreoptions
 * @link      https://gitlab.teclib.com/glpi-network/cancelsend/
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Moreoptions;

use DBmysql;
use Change;
use CommonDBTM;
use CommonGLPI;
use CommonITILObject;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use JsonException;
use Migration;
use Plugin;
use Problem;
use Profile;
use Session;
use Ticket;

use function Safe\json_decode;
use function Safe\json_encode;

class Config extends CommonDBTM
{
    public $dohistory = true;

    public static $rightname = 'config';

    public const CONFIG_PARENT = Entity::CONFIG_PARENT;

    public static function getMenuName(): string
    {
        return __('More options', 'moreoptions');
    }

    public static function getTypeName($nb = 0): string
    {
        return __('More options', 'moreoptions');
    }

    /**
     * @return array<string, mixed>
     */
    public function defineTabs($options = []): array
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        return $ong;
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::$rightname, READ);
    }

    public function canEdit($ID): bool
    {
        return (bool) Session::haveRight(self::$rightname, UPDATE);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === Entity::class) {
            return self::createTabEntry(__('More options', 'moreoptions'), 0);
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() === Entity::class) {
            if ($item instanceof Entity) {
                self::showForEntity($item);
            }

            return true;
        }

        return true;
    }

    public static function preItemUpdate(CommonDBTM $item): CommonDBTM
    {
        if (!is_array($item->input)) {
            return $item;
        }

        foreach (self::getAllConfigFields() as $field) {
            if (isset($item->input[$field])) {
                $item->input[$field] = (int) $item->input[$field];
            }
        }

        foreach (self::getProfilesConfigFields() as $field) {
            if (isset($item->input[$field])) {
                $item->input[$field] = self::encodeProfiles($item->input[$field]);
            }
        }

        return $item;
    }

    /**
     * Value stored for a `profiles` field, from the multiple select of the form: the ids of the
     * profiles as a JSON list, or Config::CONFIG_PARENT when "Inherit" is among them.
     */
    private static function encodeProfiles(mixed $value): string
    {
        $ids = array_map(intval(...), array_filter((array) $value, is_numeric(...)));
        if (in_array(self::CONFIG_PARENT, $ids, true)) {
            return (string) self::CONFIG_PARENT;
        }

        return json_encode(array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0))));
    }

    /**
     * Ids of the profiles of a `profiles` field, as stored by encodeProfiles(): none for an
     * inherited value not resolved (see getConfig()) or an invalid one.
     *
     * @return list<int>
     */
    public static function decodeProfiles(mixed $value): array
    {
        if (!is_string($value) || $value === '' || (int) $value === self::CONFIG_PARENT) {
            return [];
        }

        try {
            $ids = json_decode($value, true);
        } catch (JsonException) {
            return [];
        }

        return is_array($ids) ? array_values(array_map(intval(...), array_filter($ids, is_numeric(...)))) : [];
    }

    /**
     * @return array<string>
     */
    public static function getItilConfigFields(): array
    {
        return self::getConfigFieldsByKind('yes_no');
    }

    /**
     * @return array<string>
     */
    private static function getActorGroupConfigFields(): array
    {
        return self::getConfigFieldsByKind('actor');
    }

    /**
     * Fields of kind `profiles`: lists of profiles, stored as JSON (see encodeProfiles()).
     *
     * @return array<string>
     */
    private static function getProfilesConfigFields(): array
    {
        return self::getConfigFieldsByKind('profiles');
    }

    /**
     * Every field a child entity can inherit from its parent entity: the integer ones (see
     * getAllConfigFields()) and the `profiles` ones.
     *
     * @return array<string>
     */
    private static function getInheritableConfigFields(): array
    {
        return array_merge(self::getAllConfigFields(), self::getProfilesConfigFields());
    }

    /**
     * Integer fields: the `yes_no`, `actor` and `status` ones.
     *
     * @return array<string>
     */
    private static function getAllConfigFields(): array
    {
        return array_merge(
            self::getItilConfigFields(),
            self::getActorGroupConfigFields(),
            array_keys(self::getStatusConfigFields()),
        );
    }

    /**
     * Fields of kind `status`, with the ITIL itemtype whose statuses they hold.
     *
     * @return array<string, class-string<CommonITILObject>>
     */
    private static function getStatusConfigFields(): array
    {
        return [
            'escalade_status_after_escalation_ticket'  => Ticket::class,
            'escalade_status_after_escalation_change'  => Change::class,
            'escalade_status_after_escalation_problem' => Problem::class,
        ];
    }

    /**
     * Choices of a `status` field: "No change" (0), then every status of the itemtype.
     *
     * @param class-string<CommonITILObject> $itemtype
     * @return array<int, string>
     */
    public static function getSelectableStatus(string $itemtype): array
    {
        return [0 => __('No change', 'moreoptions')] + $itemtype::getAllStatusArray();
    }

    /**
     * Every field name of the given kind, resolved across all four tabs --
     * i.e. the same list `getItilConfigFields()`/`getActorGroupConfigFields()`
     * used to hardcode, but read off `getScreenSections()` so a new setting
     * only needs to be added there.
     *
     * @return array<string>
     */
    private static function getConfigFieldsByKind(string $kind): array
    {
        $fields = [];
        foreach (self::getScreenTabs() as $tab) {
            foreach (self::getSectionsForTab($tab['id']) as $section) {
                foreach ($section['rows'] as $row) {
                    if ($row['kind'] === $kind) {
                        $fields[] = $row['field'];
                    }
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @return array<int, string>
     */
    public static function getSelectableActorGroup(): array
    {
        return [
            0  => __('No'),
            1  => __('Default', 'moreoptions'),
            2  => __('All'),
        ];
    }

    /**
     * Escalade provides the same feature ("Use the technician's group") with a
     * global configuration, while this plugin configures it per entity. When the
     * Escalade option is effectively enabled, it takes precedence over ours.
     */
    public static function isTechnicianGroupHandledByEscalade(): bool
    {
        if (!Plugin::isPluginActive('escalade')) {
            return false;
        }

        return self::escaladeConfigHandlesTechnicianGroup(
            self::getEscaladeConfig(),
            self::isTechnicianGroupHandledByBehaviors(),
        );
    }

    /**
     * Tell whether an Escalade configuration effectively handles the technician
     * group assignment.
     *
     * Holds the decision alone, without reading the plugins state, so that it can
     * be tested without having Escalade nor Behaviors installed.
     *
     * @param array<string, mixed>|null $escalade_config      Escalade configuration, `null` when it cannot be read
     * @param bool                      $handled_by_behaviors Whether the Behaviors plugin owns the feature
     */
    public static function escaladeConfigHandlesTechnicianGroup(
        ?array $escalade_config,
        bool $handled_by_behaviors = false,
    ): bool {
        if ($escalade_config === null) {
            return false;
        }

        // The main option only selects which groups are used: the feature stays
        // inert unless it is also enabled on creation and/or on modification.
        if ((int) ($escalade_config['use_assign_user_group'] ?? 0) === 0) {
            return false;
        }

        // Older Escalade versions have no sub-options: the main one was enough.
        $on_creation     = (int) ($escalade_config['use_assign_user_group_creation'] ?? 1) !== 0;
        $on_modification = (int) ($escalade_config['use_assign_user_group_modification'] ?? 1) !== 0;

        // On creation, Escalade steps aside when the Behaviors plugin owns the
        // feature (see PluginEscaladeTicket::assignUserGroup()).
        if ($on_creation && $handled_by_behaviors) {
            $on_creation = false;
        }

        return $on_creation || $on_modification;
    }

    /**
     * Get the Escalade configuration, from the session when available, from the
     * database otherwise (CLI, tests, ...).
     *
     * @return array<string, mixed>|null
     */
    private static function getEscaladeConfig(): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (isset($_SESSION['glpi_plugins']['escalade']['config']) && is_array($_SESSION['glpi_plugins']['escalade']['config'])) {
            return $_SESSION['glpi_plugins']['escalade']['config'];
        }

        $table = 'glpi_plugin_escalade_configs';
        if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'use_assign_user_group')) {
            return null;
        }

        $fields = ['use_assign_user_group'];
        foreach (['use_assign_user_group_creation', 'use_assign_user_group_modification'] as $field) {
            if ($DB->fieldExists($table, $field)) {
                $fields[] = $field;
            }
        }

        $escalade_config = $DB->request([
            'SELECT' => $fields,
            'FROM'   => $table,
            'LIMIT'  => 1,
        ])->current();

        return is_array($escalade_config) ? $escalade_config : null;
    }

    /**
     * The Behaviors plugin provides the same feature too, and Escalade gives it
     * precedence on ticket creation.
     */
    private static function isTechnicianGroupHandledByBehaviors(): bool
    {
        if (!Plugin::isPluginActive('behaviors') || !class_exists(\GlpiPlugin\Behaviors\Config::class)) {
            return false;
        }

        return (int) \GlpiPlugin\Behaviors\Config::getInstance()->getField('use_assign_user_group') !== 0;
    }

    public static function showForEntity(Entity $item): void
    {
        $moconfig = new self();
        $moconfig->getFromDBByCrit([
            'entities_id' => $item->getID(),
        ]);

        $status_options = [];
        foreach (self::getStatusConfigFields() as $field => $itemtype) {
            $status_options[$field] = self::getSelectableStatus($itemtype);
            if ($item->getID() > 0) {
                $status_options[$field] = [self::CONFIG_PARENT => __('Inherit', 'moreoptions')] + $status_options[$field];
            }
        }

        $tabs = self::getScreenTabs();
        $sections_by_tab = [];
        foreach ($tabs as $tab) {
            $sections_by_tab[$tab['id']] = self::getSectionsForTab($tab['id']);
        }

        TemplateRenderer::getInstance()->display(
            '@moreoptions/config.html.twig',
            [
                'item'               => $moconfig,
                'tabs'               => $tabs,
                'sections_by_tab'    => $sections_by_tab,
                'parent_entity_id'   => $item->getID() > 0 ? (int) $item->fields['entities_id'] : null,
                'parent_badges'      => self::getParentValueBadges($item),
                'dropdown_options'   => self::getSelectableActorGroup(),
                'status_options'     => $status_options,
                'config_parent'      => self::CONFIG_PARENT,
                'escalade_takes_technician_group' => self::isTechnicianGroupHandledByEscalade(),
                'profile_options'    => ($item->getID() > 0 ? [self::CONFIG_PARENT => __('Inherit', 'moreoptions')] : []) + self::getSelectableProfiles(),
                'profiles_values'    => self::getProfilesValues($moconfig),
                'escalation_tree'    => Group_Link::getEditorVariables($item),
                'params'             => [
                    'canedit' => self::canUpdate(),
                ],
            ],
        );
    }

    public static function getIcon(): string
    {
        return "ti ti-send";
    }

    /**
     * Choices of a `profiles` field: every profile, by name.
     *
     * @return array<int, string>
     */
    private static function getSelectableProfiles(): array
    {
        return array_map(
            static fn(array $profile): string => (string) $profile['name'],
            (new Profile())->find([], 'name'),
        );
    }

    /**
     * Values selected in the `profiles` fields of the given configuration: Config::CONFIG_PARENT
     * alone when inherited.
     *
     * @return array<string, list<int>>
     */
    private static function getProfilesValues(self $moconfig): array
    {
        $values = [];
        foreach (self::getProfilesConfigFields() as $field) {
            $value = $moconfig->fields[$field] ?? null;
            $values[$field] = $value !== null && (int) $value === self::CONFIG_PARENT
                ? [self::CONFIG_PARENT]
                : self::decodeProfiles($value);
        }

        return $values;
    }

    /**
     * The four tabs the config screen is split into.
     *
     * @return array<int, array{id: string, label: string, icon: string}>
     */
    public static function getScreenTabs(): array
    {
        return [
            ['id' => 'ticket', 'label' => __('Ticket'), 'icon' => Ticket::getIcon()],
            ['id' => 'change', 'label' => __('Change'), 'icon' => Change::getIcon()],
            ['id' => 'problem', 'label' => __('Problem'), 'icon' => Problem::getIcon()],
            ['id' => 'task', 'label' => _n('Task', 'Tasks', 2), 'icon' => 'ti-checklist'],
            ['id' => 'escalate', 'label' => __('Escalate', 'moreoptions'), 'icon' => Escalation::getIcon(),],
            ['id' => 'escalate_filter', 'label' => __('Escalate filter', 'moreoptions'), 'icon' => 'ti-filter'],
        ];
    }

    /**
     * Settings shared by the Ticket / Change / Problem tabs ('itil'), plus the
     * task-only ones ('task'), which have no per-type variant: their field
     * name carries no suffix.
     *
     * This is the single place to add a new setting or section. A row
     * applies to all three ITIL types unless it lists the ones it is
     * restricted to in `only` (e.g. `['ticket']`, for a ticket-specific
     * setting), and warns instead of being hidden on a single type with
     * `warn_on`. Adding a field here is not enough on its own: it also needs
     * a column (see install()) and, for a brand new one, a default value
     * wired into addConfig().
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function getScreenSections(): array
    {
        return [
            'itil' => [
                [
                    'title' => __('Actors and groups', 'moreoptions'),
                    'icon'  => 'ti-users-group',
                    'rows'  => [
                        ['key' => 'take_item_group', 'kind' => 'yes_no', 'label' => __('Take the group of associated item', 'moreoptions')],
                        ['key' => 'take_requester_group', 'kind' => 'actor', 'label' => __('Take the requester group', 'moreoptions')],
                        ['key' => 'take_technician_group', 'kind' => 'actor', 'label' => __('Take the technician group', 'moreoptions'), 'warn_on' => 'ticket'],
                        ['key' => 'assign_technical_manager_when_changing_category', 'kind' => 'yes_no', 'label' => __('Assign technical manager when changing category', 'moreoptions')],
                        ['key' => 'assign_technical_group_when_changing_category', 'kind' => 'yes_no', 'label' => __('Assign technical group when changing category', 'moreoptions')],
                        ['key' => 'assign_technician_from_task', 'kind' => 'yes_no', 'label' => __('Assign technician from task to parent item', 'moreoptions')],
                    ],
                ],
                [
                    'title' => __('Closure', 'moreoptions'),
                    'icon'  => 'ti-lock',
                    'rows'  => [
                        ['key' => 'prevent_closure', 'kind' => 'yes_no', 'label' => __('Prevent closure with tasks in To Do status', 'moreoptions')],
                    ],
                ],
                [
                    'title' => __('Mandatory fields to Solve and Close ITILs', 'moreoptions'),
                    'icon'  => 'ti-help-circle',
                    'rows'  => [
                        ['key' => 'require_technician_to_close', 'kind' => 'yes_no', 'label' => __('Technician')],
                        ['key' => 'require_technicians_group_to_close', 'kind' => 'yes_no', 'label' => __('Technicians group')],
                        ['key' => 'require_category_to_close', 'kind' => 'yes_no', 'label' => __('Category')],
                        ['key' => 'require_location_to_close', 'kind' => 'yes_no', 'label' => __('Location')],
                        ['key' => 'require_solution_to_close', 'kind' => 'yes_no', 'label' => __('Solution')],
                    ],
                ],
            ],
            'task' => [
                [
                    'title' => __('Mandatory fields for Tasks creation', 'moreoptions'),
                    'icon'  => 'ti-checklist',
                    'note'  => __('These settings are shared by tickets, changes and problems.', 'moreoptions'),
                    'rows'  => [
                        ['key' => 'mandatory_task_category', 'kind' => 'yes_no', 'label' => __('Category')],
                        ['key' => 'mandatory_task_duration', 'kind' => 'yes_no', 'label' => __('Duration')],
                        ['key' => 'mandatory_task_user', 'kind' => 'yes_no', 'label' => __('User')],
                        ['key' => 'mandatory_task_group', 'kind' => 'yes_no', 'label' => __('Group')],
                    ],
                ],
            ],
            'escalate' => [
                [
                    'title' => __('Escalate', 'moreoptions'),
                    'icon'  => Escalation::getIcon(),
                    'rows'  => [
                        ['key' => 'escalate_is_active', 'kind' => 'yes_no', 'label' => __('Activate escalation', 'moreoptions')],
                        ['key' => 'escalate_remove_technician', 'kind' => 'yes_no', 'label' => __('Remove technician after escalation', 'moreoptions')],
                        ['key' => 'escalade_status_after_escalation_ticket', 'kind' => 'status', 'label' => __('Ticket status after escalation', 'moreoptions')],
                        ['key' => 'escalade_status_after_escalation_change', 'kind' => 'status', 'label' => __('Change status after escalation', 'moreoptions')],
                        ['key' => 'escalade_status_after_escalation_problem', 'kind' => 'status', 'label' => __('Problem status after escalation', 'moreoptions')],
                    ],
                ],
                [
                    'title' => __('Default options values', 'moreoptions'),
                    'icon'  => 'ti-settings',
                    'rows'  => [
                        ['key' => 'escalade_assign_me_as_obsever_by_default', 'kind' => 'yes_no', 'label' => __('Assign me as observer after escalation', 'moreoptions')],
                        ['key' => 'escalade_is_private_by_default', 'kind' => 'yes_no', 'label' => __('Escalate event is private', 'moreoptions')],
                    ],
                ],
            ],
            'escalate_filter' => [
                [
                    'title' => __('Group filter', 'moreoptions'),
                    'icon'  => 'ti-filter',
                    'rows'  => [
                        ['key' => 'escalate_group_filter_is_active', 'kind' => 'yes_no', 'label' => __('Activate group filter', 'moreoptions')],
                        ['key' => 'escalate_group_filter_bypass_profiles', 'kind' => 'profiles', 'label' => __('Profiles allowed to bypass the filter', 'moreoptions')],
                    ],
                ],
            ],
        ];
    }

    /**
     * Sections to render for one tab: each row's field name resolved (`key`
     * plus the tab's suffix, task settings getting none), and rows
     * restricted with `only` dropped where they do not apply.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getSectionsForTab(string $tab_id): array
    {
        if (in_array($tab_id, ['task', 'escalate', 'escalate_filter'], true)) {
            $group = $tab_id;
            $suffix = '';
        } else {
            $group = 'itil';
            $suffix = '_' . $tab_id;
        }

        $sections = [];
        foreach (self::getScreenSections()[$group] as $section) {
            $rows = array_values(array_filter(
                $section['rows'],
                static fn(array $row): bool => empty($row['only']) || in_array($tab_id, $row['only'], true),
            ));

            if ($rows === []) {
                continue;
            }

            $section['rows'] = array_map(
                static fn(array $row): array => $row + ['field' => $row['key'] . $suffix],
                $rows,
            );
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * GLPI's own "inherited value" badges, one per field, shown right on the
     * "Inherit" option so it doubles as what that option currently resolves
     * to.
     *
     * Returns an empty array for the root entity, which inherits from nothing.
     *
     * @return array<string, string>
     */
    private static function getParentValueBadges(Entity $item): array
    {
        if ($item->getID() <= 0) {
            return [];
        }

        $parent_config = self::getConfig((int) $item->fields['entities_id'], true);
        $actor_options = self::getSelectableActorGroup();

        $badges = [];
        foreach (self::getItilConfigFields() as $field) {
            $text = ((int) ($parent_config->fields[$field] ?? 0)) === 1 ? __('Yes') : __('No');
            $badges[$field] = Entity::inheritedValue(htmlescape($text), false, false);
        }

        foreach (self::getActorGroupConfigFields() as $field) {
            $text = $actor_options[(int) ($parent_config->fields[$field] ?? 0)] ?? __('No');
            $badges[$field] = Entity::inheritedValue(htmlescape($text), false, false);
        }

        foreach (self::getStatusConfigFields() as $field => $itemtype) {
            $text = self::getSelectableStatus($itemtype)[(int) ($parent_config->fields[$field] ?? 0)] ?? __('No change', 'moreoptions');
            $badges[$field] = Entity::inheritedValue(htmlescape($text), false, false);
        }

        $profile_names = self::getSelectableProfiles();
        foreach (self::getProfilesConfigFields() as $field) {
            $names = array_intersect_key($profile_names, array_flip(self::decodeProfiles($parent_config->fields[$field] ?? null)));
            $text  = $names !== [] ? implode(', ', $names) : __('None');
            $badges[$field] = Entity::inheritedValue(htmlescape($text), false, false);
        }

        return $badges;
    }

    public static function addConfig(CommonDBTM $item): void
    {
        $moconfig = new self();
        $entity_id = $item->getID();
        $data = ['entities_id' => $entity_id];
        if ($entity_id > 0) {
            foreach (self::getInheritableConfigFields() as $field) {
                $data[$field] = self::CONFIG_PARENT;
            }
        }

        $moconfig->add($data);
    }

    /**
     * Get configuration for an entity
     *
     * @param int|null $entityId Entity ID (null = current active entity)
     * @param bool $useInheritance Whether to follow parent entity inheritance (default: true)
     */
    public static function getConfig(?int $entityId = null, bool $useInheritance = true): self
    {
        if ($entityId === null) {
            $entityId = Session::getActiveEntity();
        }

        $moconfig = new self();
        $moconfig->getFromDBByCrit([
            'entities_id' => $entityId,
        ]);

        if ($useInheritance && $entityId > 0) {
            $entity = new Entity();
            if ($entity->getFromDB($entityId)) {
                $parentConfig = self::getConfig((int) $entity->fields['entities_id'], true);
                foreach (self::getInheritableConfigFields() as $field) {
                    if (($moconfig->fields[$field] ?? 0) == self::CONFIG_PARENT) {
                        $moconfig->fields[$field] = $parentConfig->fields[$field] ?? 0;
                    }
                }
            }
        }

        return $moconfig;
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
                `entities_id` int unsigned NOT NULL DEFAULT '0',
                `take_item_group_ticket` tinyint NOT NULL DEFAULT '0',
                `take_item_group_change` tinyint NOT NULL DEFAULT '0',
                `take_item_group_problem` tinyint NOT NULL DEFAULT '0',
                `take_requester_group_ticket` tinyint NOT NULL DEFAULT '0',
                `take_requester_group_change` tinyint NOT NULL DEFAULT '0',
                `take_requester_group_problem` tinyint NOT NULL DEFAULT '0',
                `take_technician_group_ticket` tinyint NOT NULL DEFAULT '0',
                `take_technician_group_change` tinyint NOT NULL DEFAULT '0',
                `take_technician_group_problem` tinyint NOT NULL DEFAULT '0',
                `prevent_closure_ticket` tinyint NOT NULL DEFAULT '0',
                `prevent_closure_change` tinyint NOT NULL DEFAULT '0',
                `prevent_closure_problem` tinyint NOT NULL DEFAULT '0',
                `require_technician_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_technicians_group_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_category_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_location_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_solution_to_close_ticket` tinyint NOT NULL DEFAULT '0',
                `require_technician_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_technicians_group_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_category_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_location_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_solution_to_close_change` tinyint NOT NULL DEFAULT '0',
                `require_technician_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `require_technicians_group_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `require_category_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `require_location_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `require_solution_to_close_problem` tinyint NOT NULL DEFAULT '0',
                `assign_technical_manager_when_changing_category_ticket` tinyint NOT NULL DEFAULT '0',
                `assign_technical_group_when_changing_category_ticket` tinyint NOT NULL DEFAULT '0',
                `assign_technical_manager_when_changing_category_change` tinyint NOT NULL DEFAULT '0',
                `assign_technical_group_when_changing_category_change` tinyint NOT NULL DEFAULT '0',
                `assign_technical_manager_when_changing_category_problem` tinyint NOT NULL DEFAULT '0',
                `assign_technical_group_when_changing_category_problem` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_category` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_duration` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_user` tinyint NOT NULL DEFAULT '0',
                `mandatory_task_group` tinyint NOT NULL DEFAULT '0',
                `assign_technician_from_task_ticket` tinyint NOT NULL DEFAULT '0',
                `assign_technician_from_task_change` tinyint NOT NULL DEFAULT '0',
                `assign_technician_from_task_problem` tinyint NOT NULL DEFAULT '0',
                `escalate_is_active` tinyint NOT NULL DEFAULT '0',
                `escalate_remove_technician` tinyint NOT NULL DEFAULT '0',
                `escalade_status_after_escalation_ticket` tinyint NOT NULL DEFAULT '0',
                `escalade_status_after_escalation_change` tinyint NOT NULL DEFAULT '0',
                `escalade_status_after_escalation_problem` tinyint NOT NULL DEFAULT '0',
                `escalade_assign_me_as_obsever_by_default` tinyint NOT NULL DEFAULT '0',
                `escalade_is_private_by_default` tinyint NOT NULL DEFAULT '0',
                `escalate_group_filter_is_active` tinyint NOT NULL DEFAULT '0',
                `escalate_group_filter_bypass_profiles` text,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
              ";
            $DB->doQuery($query);
        }

        foreach (self::getActorGroupConfigFields() as $field) {
            if (!$DB->fieldExists($table, $field)) {
                $migration->changeField($table, $field, $field, 'bool', ['value' => '0']);
            }
        }

        foreach (
            [
                'assign_technician_from_task_ticket',
                'assign_technician_from_task_change',
                'assign_technician_from_task_problem',
                'escalate_is_active',
                'escalate_remove_technician',
                'escalade_status_after_escalation_ticket',
                'escalade_status_after_escalation_change',
                'escalade_status_after_escalation_problem',
                'escalade_assign_me_as_obsever_by_default',
                'escalade_is_private_by_default',
            ] as $field
        ) {
            if (!$DB->fieldExists($table, $field)) {
                $migration->addField($table, $field, 'bool', ['value' => '0']);
            }
        }

        // Status fields added to an existing table: child entities inherit by default.
        $new_inherited_fields = [];
        foreach (array_keys(self::getStatusConfigFields()) as $field) {
            if (!$DB->fieldExists($table, $field)) {
                $migration->addField($table, $field, 'bool', ['value' => '0']);
                $new_inherited_fields[] = $field;
            }
        }

        // Same for the group filter fields.
        if (!$DB->fieldExists($table, 'escalate_group_filter_is_active')) {
            $migration->addField($table, 'escalate_group_filter_is_active', 'bool', ['value' => '0']);
            $new_inherited_fields[] = 'escalate_group_filter_is_active';
        }
        if (!$DB->fieldExists($table, 'escalate_group_filter_bypass_profiles')) {
            $migration->addField($table, 'escalate_group_filter_bypass_profiles', 'text');
            $new_inherited_fields[] = 'escalate_group_filter_bypass_profiles';
        }

        $migration->executeMigration();

        if ($new_inherited_fields !== []) {
            $DB->update(
                $table,
                array_fill_keys($new_inherited_fields, self::CONFIG_PARENT),
                ['entities_id' => ['>', 0]],
            );
        }

        $entities = new Entity();
        foreach ($entities->find() as $entity) {
            if (is_array($entity) && isset($entity['id'])) {
                $entity_id = (int) $entity['id'];
                if (countElementsInTable(self::getTable(), ['entities_id' => $entity_id]) > 0) {
                    continue;
                }

                $data = ['entities_id' => $entity_id];
                if ($entity_id > 0) {
                    foreach (self::getInheritableConfigFields() as $field) {
                        $data[$field] = self::CONFIG_PARENT;
                    }
                }

                $DB->insert(
                    self::getTable(),
                    $data,
                );
            }
        }
    }


    public static function uninstall(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE IF EXISTS `" . self::getTable() . "`");
        }
    }
}
