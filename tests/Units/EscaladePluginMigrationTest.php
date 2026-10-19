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

use Glpi\Migration\PluginMigrationResult;
use DBmysql;
use DBConnection;
use Glpi\Message\MessageType;
use GlpiPlugin\Moreoptions\Config;
use GlpiPlugin\Moreoptions\Group_Link;
use GlpiPlugin\Moreoptions\LinkStrategy\LinkStrategyEnum;
use GlpiPlugin\Moreoptions\Migration\EscaladePluginMigration;
use GlpiPlugin\Moreoptions\Tests\EscalationTestCase;
use Group;

final class EscaladePluginMigrationTest extends EscalationTestCase
{
    /**
     * Escalade tables created by the tests, dropped once they are done: the ones of an installed
     * Escalade plugin are kept.
     *
     * @var list<string>
     */
    private static array $created_tables = [];

    public static function setUpBeforeClass(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        parent::setUpBeforeClass();

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $tables    = [
            EscaladePluginMigration::LINKS_TABLE => "
                `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                `groups_id_source` int {$key_sign} NOT NULL DEFAULT '0',
                `groups_id_destination` int {$key_sign} NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`)",
            EscaladePluginMigration::CONFIG_TABLE => "
                `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                `use_filter_assign_group` int NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`)",
            EscaladePluginMigration::USERS_TABLE => "
                `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                `users_id` int {$key_sign} NOT NULL DEFAULT '0',
                `bypass_filter_assign_group` tinyint NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`)",
        ];

        foreach ($tables as $table => $fields) {
            if (!$DB->tableExists($table)) {
                $DB->doQuery("CREATE TABLE `{$table}` ({$fields}) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
                self::$created_tables[] = $table;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (self::$created_tables as $table) {
            $DB->dropTable($table);
        }

        self::$created_tables = [];

        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        parent::setUp();

        // Rolled back after each test, like the data inserted by the tests. The configuration of an
        // installed Escalade plugin is kept: its fields have no default value.
        foreach ([EscaladePluginMigration::LINKS_TABLE, EscaladePluginMigration::USERS_TABLE] as $table) {
            $DB->delete($table, ['id' => ['>', 0]]);
        }

        $DB->update(EscaladePluginMigration::CONFIG_TABLE, ['use_filter_assign_group' => 0], ['id' => ['>', 0]]);
    }

    /**
     * @param list<array{int, int}> $links Source and destination of each Escalade link
     */
    private function insertEscaladeLinks(array $links): void
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach ($links as [$source, $destination]) {
            $DB->insert(EscaladePluginMigration::LINKS_TABLE, ['groups_id_source' => $source, 'groups_id_destination' => $destination]);
        }
    }

    private function setEscaladeFilter(bool $active): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->updateOrInsert(EscaladePluginMigration::CONFIG_TABLE, ['use_filter_assign_group' => (int) $active], ['id' => 1]);
    }

    /**
     * @return list<string> Messages of the given type
     */
    private function getMessages(PluginMigrationResult $result, MessageType $type): array
    {
        $messages = [];
        foreach ($result->getMessages() as $message) {
            if ($message['type'] === $type) {
                $messages[] = $message['message'];
            }
        }

        return $messages;
    }

    public function testLinksBecomeInheritedLinksOfTheRootEntity(): void
    {
        global $DB;

        $this->login();
        $entities_id = $this->getRootEntityId();
        $child_id    = $this->createChildEntity('Child', $entities_id);
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $entities_id, true);
        $this->insertEscaladeLinks([[$a, $b], [$a, $c], [$b, $c], [$a, $b]]);

        $migration = new EscaladePluginMigration($DB);
        $this->assertTrue($migration->hasPluginData());

        $result = $migration->execute();
        $this->assertTrue($result->isFullyProcessed());
        $this->assertFalse($result->hasErrors());
        $this->assertCount(3, $result->getCreatedItemsIds()[Group_Link::class] ?? []);

        $this->assertSame([
            sprintf('%d-%d', $a, $b) => LinkStrategyEnum::INHERITED->value,
            sprintf('%d-%d', $a, $c) => LinkStrategyEnum::INHERITED->value,
            sprintf('%d-%d', $b, $c) => LinkStrategyEnum::INHERITED->value,
        ], $this->getEntityLinks(0));

        // The links apply in all the entities, as in Escalade.
        $this->assertSame([$b, $c], Group_Link::getNextLevelGroups($a, $entities_id));
        $this->assertSame([$b, $c], Group_Link::getNextLevelGroups($a, $child_id));
        $this->assertSame([$c], Group_Link::getNextLevelGroups($b, $child_id));
    }

    public function testInvalidLinksAreIgnored(): void
    {
        global $DB;

        $this->login();
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], $this->getRootEntityId(), true);
        $deleted = $this->createGroups(['Deleted'], $this->getRootEntityId())['Deleted'];
        $this->deleteItem(Group::class, $deleted, true);
        $this->insertEscaladeLinks([[$a, $b], [$a, $a], [$a, $deleted], [$deleted, $b]]);

        $result = (new EscaladePluginMigration($DB))->execute();
        $this->assertTrue($result->isFullyProcessed());
        $this->assertFalse($result->hasErrors());
        $this->assertCount(3, $this->getMessages($result, MessageType::Warning));

        $this->assertSame([sprintf('%d-%d', $a, $b) => LinkStrategyEnum::INHERITED->value], $this->getEntityLinks(0));
    }

    public function testExistingLinksAreKept(): void
    {
        global $DB;

        $this->login();
        ['A' => $a, 'B' => $b, 'C' => $c] = $this->createGroups(['A', 'B', 'C'], $this->getRootEntityId(), true);
        $this->createLinks(0, [[$a, $b, LinkStrategyEnum::NONE]]);
        $this->insertEscaladeLinks([[$a, $b], [$b, $c]]);

        $result = (new EscaladePluginMigration($DB))->execute();
        $this->assertTrue($result->isFullyProcessed());
        $this->assertCount(1, $result->getReusedItemsIds()[Group_Link::class] ?? []);
        $this->assertCount(1, $result->getCreatedItemsIds()[Group_Link::class] ?? []);

        $this->assertSame([
            sprintf('%d-%d', $a, $b) => LinkStrategyEnum::NONE->value,
            sprintf('%d-%d', $b, $c) => LinkStrategyEnum::INHERITED->value,
        ], $this->getEntityLinks(0));

        // Executed again, nothing changes.
        $result = (new EscaladePluginMigration($DB))->execute();
        $this->assertTrue($result->isFullyProcessed());
        $this->assertSame([], $result->getCreatedItemsIds()[Group_Link::class] ?? []);
        $this->assertCount(2, $this->getEntityLinks(0));
    }

    public function testActiveFilterIsActivatedInTheRootEntity(): void
    {
        global $DB;

        $this->login();
        $this->updateItem(Config::class, Config::getConfig(0, false)->getID(), ['escalate_group_filter_is_active' => 0]);
        $this->setEscaladeFilter(true);

        $result = (new EscaladePluginMigration($DB))->execute();
        $this->assertTrue($result->isFullyProcessed());
        $this->assertFalse($result->hasErrors());

        $this->assertSame(1, (int) Config::getConfig(0, false)->fields['escalate_group_filter_is_active']);
    }

    public function testInactiveFilterChangesNothing(): void
    {
        global $DB;

        $this->login();
        $this->updateItem(Config::class, Config::getConfig(0, false)->getID(), ['escalate_group_filter_is_active' => 1]);
        $this->setEscaladeFilter(false);

        $result = (new EscaladePluginMigration($DB))->execute();
        $this->assertTrue($result->isFullyProcessed());

        $this->assertSame(1, (int) Config::getConfig(0, false)->fields['escalate_group_filter_is_active']);
    }

    public function testUserBypassesAreReported(): void
    {
        global $DB;

        $this->login();
        $DB->insert(EscaladePluginMigration::USERS_TABLE, ['users_id' => 2, 'bypass_filter_assign_group' => 1]);
        $DB->insert(EscaladePluginMigration::USERS_TABLE, ['users_id' => 3, 'bypass_filter_assign_group' => 0]);

        $result = (new EscaladePluginMigration($DB))->execute();
        $this->assertTrue($result->isFullyProcessed());

        $warnings = $this->getMessages($result, MessageType::Warning);
        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('1 user was allowed to bypass', $warnings[0]);
    }

    public function testSimulationChangesNothing(): void
    {
        global $DB;

        $this->login();
        ['A' => $a, 'B' => $b] = $this->createGroups(['A', 'B'], $this->getRootEntityId(), true);
        $this->insertEscaladeLinks([[$a, $b]]);

        $result = (new EscaladePluginMigration($DB))->execute(true);
        $this->assertTrue($result->isFullyProcessed());
        $this->assertSame([], $this->getEntityLinks(0));
    }
}
