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

namespace GlpiPlugin\Moreoptions\Migration;

use Dropdown;
use Entity;
use Glpi\Message\MessageType;
use Glpi\Migration\AbstractPluginMigration;
use GlpiPlugin\Moreoptions\Config;
use GlpiPlugin\Moreoptions\EscalationTree\EscalationGraph;
use GlpiPlugin\Moreoptions\EscalationTree\EscalationLink;
use GlpiPlugin\Moreoptions\Group_Link;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use Group;
use Override;

/**
 * Migration of the group filter of the Escalade plugin, which reached its end of life, to the
 * escalation tree of MoreOptions (see Group_Link):
 * - the groups a group can escalate to (`glpi_plugin_escalade_groups_groups`) become links of the
 *   root entity, replicated in all its sub-entities (see LinkStrategyEnum::INHERITED): the links of
 *   Escalade apply in all the entities, except the ones closing a loop, which Escalade allows;
 * - the activation of the filter (`use_filter_assign_group`) becomes the one of the root entity,
 *   which its sub-entities inherit unless they define their own.
 *
 * The bypass of the filter by user of Escalade cannot be migrated: MoreOptions allows profiles to
 * bypass it (see Config `escalate_group_filter_bypass_profiles`).
 */
final class EscaladePluginMigration extends AbstractPluginMigration
{
    public const LINKS_TABLE = 'glpi_plugin_escalade_groups_groups';

    public const CONFIG_TABLE = 'glpi_plugin_escalade_configs';

    public const USERS_TABLE = 'glpi_plugin_escalade_users';

    #[Override]
    protected function getHasBeenExecutedConfigurationKey(): string
    {
        return 'moreoptions_escalade_migration';
    }

    /**
     * @return list<string>
     */
    #[Override]
    protected function getMainPluginTables(): array
    {
        return [self::LINKS_TABLE];
    }

    #[Override]
    protected function validatePrerequisites(): bool
    {
        return $this->checkDbFieldsExists([
            self::LINKS_TABLE     => ['id', 'groups_id_source', 'groups_id_destination'],
            Group_Link::getTable() => ['id', 'groups_id_source', 'groups_id_destination', 'entities_id', 'link_type'],
            Config::getTable()     => ['id', 'entities_id', 'escalate_group_filter_is_active'],
        ]);
    }

    #[Override]
    protected function processMigration(): bool
    {
        $this->migrateLinks();
        $this->warnAboutLoops();
        $this->migrateFilterActivation();
        $this->warnAboutUserBypasses();

        return true;
    }

    /**
     * The links of Escalade become inherited links of the root entity. A link MoreOptions already
     * has between the same groups in the root entity is kept as it is: it was set from MoreOptions.
     * A link closing a loop is skipped, in the order of Escalade: the first links are kept.
     */
    private function migrateLinks(): void
    {
        $rows = $this->db->request([
            'SELECT' => ['id', 'groups_id_source', 'groups_id_destination'],
            'FROM'   => self::LINKS_TABLE,
            'ORDER'  => 'id',
        ]);

        $this->progress_indicator?->setMaxSteps(count($rows));
        $this->progress_indicator?->setProgressBarMessage(__('Importing escalation links…', 'moreoptions'));

        $groups = $this->getExistingGroups();
        $done   = [];

        // Escalade allows loops (A -> B and B -> A), MoreOptions does not: a link closing one with
        // the links of the root entity, already there or imported before it, is skipped.
        $graph = new EscalationGraph();
        foreach (Group_Link::getRowsOfEntity(0) as $existing) {
            $existing = EscalationLink::fromRow($existing);
            if ($existing->strategy !== LinkStrategyEnum::NONE) {
                $graph->addEdge($existing->source, $existing->destination);
            }
        }

        foreach ($rows as $row) {
            $source      = (int) $row['groups_id_source'];
            $destination = (int) $row['groups_id_destination'];
            $this->progress_indicator?->advance();

            $key = EscalationLink::key($source, $destination);
            if (isset($done[$key])) {
                // Escalade does not prevent duplicates.
                continue;
            }

            $done[$key] = true;

            if ($source === $destination || !isset($groups[$source], $groups[$destination])) {
                $this->result->addMessage(
                    MessageType::Warning,
                    sprintf(
                        __('Escalation link #%d from group #%d to group #%d has been ignored: a group no longer exists or both groups are the same.', 'moreoptions'),
                        $row['id'],
                        $source,
                        $destination,
                    ),
                );
                continue;
            }

            $link = new Group_Link();
            if ($link->getFromDBByCrit(['entities_id' => 0, 'groups_id_source' => $source, 'groups_id_destination' => $destination])) {
                $this->result->markItemAsReused(Group_Link::class, $link->getID());
                $this->result->addMessage(
                    MessageType::Debug,
                    sprintf(
                        __('Escalation link from "%s" to "%s" already exists in the root entity, it has been kept.', 'moreoptions'),
                        $groups[$source],
                        $groups[$destination],
                    ),
                );
                continue;
            }

            if (isset($graph->getDescendants($destination)[$source])) {
                $this->result->addMessage(
                    MessageType::Warning,
                    sprintf(
                        __('Escalation link from "%1$s" to "%2$s" has been ignored: "%2$s" already escalates to "%1$s", directly or not, and escalation links cannot make a loop.', 'moreoptions'),
                        $groups[$source],
                        $groups[$destination],
                    ),
                );
                continue;
            }

            $this->importItem(
                Group_Link::class,
                ['entities_id' => 0] + (new EscalationLink($source, $destination, LinkStrategyEnum::INHERITED))->toRow(),
                null,
                ['_no_message' => true],
            );
            $graph->addEdge($source, $destination);
        }

        $this->progress_indicator?->setProgressBarMessage('');
        $this->progress_indicator?->finish();
    }

    /**
     * The imported links cannot make a loop in the root entity (see migrateLinks()), but they still
     * can with the links of a sub-entity: no change could be saved in its tree until one is removed.
     */
    private function warnAboutLoops(): void
    {
        $loop = Group_Link::findLoop(0);
        if ($loop === null) {
            return;
        }

        $names = array_map(
            static fn(int $groups_id): string => Dropdown::getDropdownName(Group::getTable(), $groups_id),
            [...$loop['groups'], $loop['groups'][0]],
        );
        $this->result->addMessage(
            MessageType::Warning,
            sprintf(
                __('The escalation links of the entity "%1$s" make a loop (%2$s): remove one of them in the escalation tree of the entity, no change can be saved there until then.', 'moreoptions'),
                Dropdown::getDropdownName(Entity::getTable(), $loop['entities_id']),
                implode(' -> ', $names),
            ),
        );
    }

    /**
     * The group filter of Escalade, active in all the entities, becomes active in the root entity.
     * An inactive filter in Escalade changes nothing: MoreOptions may have been set since.
     */
    private function migrateFilterActivation(): void
    {
        if (!$this->db->tableExists(self::CONFIG_TABLE) || !$this->db->fieldExists(self::CONFIG_TABLE, 'use_filter_assign_group')) {
            return;
        }

        $escalade_config = $this->db->request([
            'SELECT' => ['use_filter_assign_group'],
            'FROM'   => self::CONFIG_TABLE,
            'WHERE'  => ['id' => 1],
        ])->current();
        if ((int) ($escalade_config['use_filter_assign_group'] ?? 0) !== 1) {
            return;
        }

        $this->importItem(
            Config::class,
            ['entities_id' => 0, 'escalate_group_filter_is_active' => 1],
            ['entities_id' => 0],
        );
    }

    /**
     * The users allowed to bypass the group filter of Escalade cannot be migrated: tell how many.
     */
    private function warnAboutUserBypasses(): void
    {
        if (!$this->db->tableExists(self::USERS_TABLE) || !$this->db->fieldExists(self::USERS_TABLE, 'bypass_filter_assign_group')) {
            return;
        }

        $count = $this->countRecords(self::USERS_TABLE, ['bypass_filter_assign_group' => 1]);
        if ($count > 0) {
            $this->result->addMessage(
                MessageType::Warning,
                sprintf(
                    _n(
                        '%d user was allowed to bypass the group filter of Escalade: this cannot be migrated, choose the profiles allowed to bypass it in the "Escalate filter" tab of the configuration of the entities.',
                        '%d users were allowed to bypass the group filter of Escalade: this cannot be migrated, choose the profiles allowed to bypass it in the "Escalate filter" tab of the configuration of the entities.',
                        $count,
                        'moreoptions',
                    ),
                    $count,
                ),
            );
        }
    }

    /**
     * @return array<int, string> Names of the existing groups, by id
     */
    private function getExistingGroups(): array
    {
        $groups = [];
        foreach ($this->db->request(['SELECT' => ['id', 'completename'], 'FROM' => Group::getTable()]) as $group) {
            $groups[(int) $group['id']] = (string) $group['completename'];
        }

        return $groups;
    }
}
