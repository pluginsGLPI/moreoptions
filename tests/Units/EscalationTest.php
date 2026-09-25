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
use Group;
use GlpiPlugin\Moreoptions\Config;
use GlpiPlugin\Moreoptions\Escalation;
use GlpiPlugin\Moreoptions\Tests\MoreOptionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Problem;
use Session;
use Ticket;

use function Safe\json_decode;

class EscalationTest extends MoreOptionsTestCase
{
    /**
     * @return iterable<string, array{class-string<CommonITILObject>, int}>
     */
    public static function escalationProvider(): iterable
    {
        foreach ([Ticket::class, Change::class, Problem::class] as $itemtype) {
            yield $itemtype . ' without group before escalation' => [$itemtype, 0];
            yield $itemtype . ' with one group before escalation' => [$itemtype, 1];
            yield $itemtype . ' with two groups before escalation' => [$itemtype, 2];
        }
    }

    /**
     * @param class-string<CommonITILObject> $itemtype
     */
    #[DataProvider('escalationProvider')]
    public function testEscalation(string $itemtype, int $nb_source_groups): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);
        $this->enableEscalation($entities_id);

        $item = $this->createItem($itemtype, [
            'name'        => 'Test escalation',
            'content'     => 'Test content',
            'entities_id' => $entities_id,
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        // Groups assigned to the item before the escalation
        $source_groups = [];
        for ($i = 1; $i <= $nb_source_groups; $i++) {
            $source_groups[] = $this->createGroup($entities_id, 'Source group ' . $i);
        }

        foreach ($source_groups as $group) {
            $this->createItem($item->grouplinkclass, [
                $item->getForeignKeyField() => $item->getID(),
                'groups_id'                 => $group->getID(),
                'type'                      => CommonITILActor::ASSIGN,
            ]);
        }

        $this->assertSame($this->getIdsOf($source_groups), $this->getAssignedGroupIds($item));

        // Escalate to a new group
        $target_group = $this->createGroup($entities_id, 'Target group');
        $escalation = $this->createItem(Escalation::class, [
            'itemtype'  => $item::class,
            'items_id'  => $item->getID(),
            'groups_id' => $target_group->getID(),
            'content'   => 'Escalation comment',
        ]);

        // The escalation keeps track of its author and of the previously assigned groups
        $this->assertSame(Session::getLoginUserID(), (int) $escalation->fields['users_id']);
        $this->assertSame(
            $this->getIdsOf($source_groups),
            json_decode($escalation->fields['groups_ids_source'], true),
        );

        // Only the target group remains assigned to the item
        $this->assertSame([$target_group->getID()], $this->getAssignedGroupIds($item));

        // The escalation is shown in the item timeline
        $this->assertTrue($item->getFromDB($item->getID()));
        /** @var array<array{type: string, item: array<string, mixed>}> $timeline_entries */
        $timeline_entries = array_values(array_filter(
            $item->getTimelineItems(),
            static fn(array $entry): bool => $entry['type'] === Escalation::class,
        ));
        $this->assertCount(1, $timeline_entries);

        $entry = $timeline_entries[0]['item'];
        $this->assertSame($escalation->getID(), (int) $entry['id']);
        $this->assertSame(Session::getLoginUserID(), (int) $entry['users_id']);
        $this->assertSame(CommonITILObject::TIMELINE_LEFT, $entry['timeline_position']);

        $content = $entry['content'];
        $this->assertStringContainsString('Target group', $content);
        $this->assertStringContainsString('Escalation comment', $content);
        if ($nb_source_groups === 0) {
            $this->assertStringContainsString('Escalate to', $content);
            $this->assertStringNotContainsString('Escalate from', $content);
        } else {
            $this->assertStringContainsString('Escalate from', $content);
        }

        foreach ($source_groups as $group) {
            $this->assertStringContainsString($group->fields['name'], $content);
        }
    }

    /**
     * @return iterable<string, array{class-string<CommonITILObject>}>
     */
    public static function itemtypeProvider(): iterable
    {
        foreach ([Ticket::class, Change::class, Problem::class] as $itemtype) {
            yield $itemtype => [$itemtype];
        }
    }

    /**
     * @param class-string<CommonITILObject> $itemtype
     */
    #[DataProvider('itemtypeProvider')]
    public function testEscalationToAlreadyAssignedGroupIsRefused(string $itemtype): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);
        $this->enableEscalation($entities_id);

        $item = $this->createItem($itemtype, [
            'name'        => 'Test escalation',
            'content'     => 'Test content',
            'entities_id' => $entities_id,
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        $assigned_groups = [
            $this->createGroup($entities_id, 'Assigned group 1'),
            $this->createGroup($entities_id, 'Assigned group 2'),
        ];
        foreach ($assigned_groups as $group) {
            $this->createItem($item->grouplinkclass, [
                $item->getForeignKeyField() => $item->getID(),
                'groups_id'                 => $group->getID(),
                'type'                      => CommonITILActor::ASSIGN,
            ]);
        }

        $escalation = new Escalation();
        $this->assertFalse($escalation->add([
            'itemtype'  => $item::class,
            'items_id'  => $item->getID(),
            'groups_id' => $assigned_groups[0]->getID(),
        ]));
        $this->hasSessionMessages(ERROR, ['This group is already assigned.']);

        // Nothing changed: no escalation, and the assigned groups are kept
        $this->assertSame(0, countElementsInTable(Escalation::getTable(), [
            'itemtype' => $item::class,
            'items_id' => $item->getID(),
        ]));
        $this->assertSame($this->getIdsOf($assigned_groups), $this->getAssignedGroupIds($item));

        // Nothing is added to the timeline
        $this->assertTrue($item->getFromDB($item->getID()));
        $this->assertSame([], array_values(array_filter(
            $item->getTimelineItems(),
            static fn(array $entry): bool => $entry['type'] === Escalation::class,
        )));
    }

    /**
     * Enable the escalation option for the given entity.
     */
    private function enableEscalation(int $entities_id): void
    {
        $config = Config::getConfig($entities_id, false);
        if ($config->isNewItem()) {
            $this->createTestConfig([
                'entities_id'        => $entities_id,
                'escalate_is_active' => 1,
            ]);
        } else {
            $this->updateTestConfig($config, ['escalate_is_active' => 1]);
        }
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

    /**
     * @param array<Group> $groups
     * @return array<int>
     */
    private function getIdsOf(array $groups): array
    {
        return array_map(static fn(Group $group): int => $group->getID(), $groups);
    }
}
