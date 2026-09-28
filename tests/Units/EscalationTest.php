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
use ITILFollowup;
use Log;
use GlpiPlugin\Moreoptions\Config;
use GlpiPlugin\Moreoptions\Escalation;
use GlpiPlugin\Moreoptions\Tests\MoreOptionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Problem;
use Session;
use Symfony\Component\DomCrawler\Crawler;
use Ticket;
use User;

use function Safe\json_decode;
use function Safe\ob_get_clean;
use function Safe\ob_start;

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
     * @return iterable<string, array{class-string<CommonITILObject>, bool}>
     */
    public static function removeTechnicianProvider(): iterable
    {
        foreach ([Ticket::class, Change::class, Problem::class] as $itemtype) {
            yield $itemtype . ' with "Remove technician after escalation" enabled' => [$itemtype, true];
            yield $itemtype . ' with "Remove technician after escalation" disabled' => [$itemtype, false];
        }
    }

    /**
     * @param class-string<CommonITILObject> $itemtype
     */
    #[DataProvider('removeTechnicianProvider')]
    public function testRemoveTechnicianAfterEscalation(string $itemtype, bool $remove_technician): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);
        $this->enableEscalation($entities_id, ['escalate_remove_technician' => (int) $remove_technician]);

        $item = $this->createItem($itemtype, [
            'name'        => 'Test escalation',
            'content'     => 'Test content',
            'entities_id' => $entities_id,
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        // Two technicians assigned to the item, plus a requester and an observer
        $technicians = [$this->getUser('tech'), $this->getUser('normal')];
        foreach ($technicians as $technician) {
            $this->addUserActor($item, $technician, CommonITILActor::ASSIGN);
        }
        $requester = $this->getUser('post-only');
        $observer = $this->getUser('glpi');
        $this->addUserActor($item, $requester, CommonITILActor::REQUESTER);
        $this->addUserActor($item, $observer, CommonITILActor::OBSERVER);

        $this->assertSame($this->getIdsOf($technicians), $this->getUserActorIds($item, CommonITILActor::ASSIGN));

        $target_group = $this->createGroup($entities_id, 'Target group');
        $this->createItem(Escalation::class, [
            'itemtype'  => $item::class,
            'items_id'  => $item->getID(),
            'groups_id' => $target_group->getID(),
        ]);

        // The technicians are removed only when the option is enabled
        $this->assertSame(
            $remove_technician ? [] : $this->getIdsOf($technicians),
            $this->getUserActorIds($item, CommonITILActor::ASSIGN),
        );

        // The other actors are always kept, and the target group is assigned in both cases
        $this->assertContains($requester->getID(), $this->getUserActorIds($item, CommonITILActor::REQUESTER));
        $this->assertContains($observer->getID(), $this->getUserActorIds($item, CommonITILActor::OBSERVER));
        $this->assertSame([$target_group->getID()], $this->getAssignedGroupIds($item));
    }

    /**
     * @return iterable<string, array{class-string<CommonITILObject>, array<class-string<CommonITILObject>, int>, bool, int|null}>
     */
    public static function statusAfterEscalationProvider(): iterable
    {
        foreach ([Ticket::class, Change::class, Problem::class] as $itemtype) {
            yield $itemtype . ' gets the configured status' => [
                $itemtype,
                [$itemtype => CommonITILObject::WAITING],
                false,
                CommonITILObject::WAITING,
            ];
            yield $itemtype . ' already having the configured status is not updated' => [
                $itemtype,
                [$itemtype => CommonITILObject::WAITING],
                true,
                CommonITILObject::WAITING,
            ];
            yield $itemtype . ' is not affected by the status configured for the other types' => [
                $itemtype,
                array_fill_keys(array_diff([Ticket::class, Change::class, Problem::class], [$itemtype]), CommonITILObject::WAITING),
                false,
                null,
            ];
        }
    }

    /**
     * @param class-string<CommonITILObject> $itemtype
     * @param array<class-string<CommonITILObject>, int> $statuses Status to set after escalation, by itemtype
     * @param int|null $expected_status Expected status after escalation, null if it must not be changed by the escalation
     */
    #[DataProvider('statusAfterEscalationProvider')]
    public function testStatusAfterEscalation(string $itemtype, array $statuses, bool $already_has_status, ?int $expected_status): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);

        $options = [];
        foreach ([Ticket::class, Change::class, Problem::class] as $configured_itemtype) {
            $options['escalade_status_after_escalation_' . strtolower($configured_itemtype)] = $statuses[$configured_itemtype] ?? 0;
        }
        $this->enableEscalation($entities_id, $options);

        $item = $this->createItem($itemtype, [
            'name'        => 'Test escalation',
            'content'     => 'Test content',
            'entities_id' => $entities_id,
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        if ($already_has_status) {
            $this->updateItem($itemtype, $item->getID(), ['status' => $expected_status]);
        }

        // Status of the item after the escalation when the plugin does not change it (the core
        // may move a new item to "Processing (assigned)" when the target group is assigned).
        $reference = $this->createItem($itemtype, [
            'name'        => 'Test escalation reference',
            'content'     => 'Test content',
            'entities_id' => $entities_id,
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $reference);
        $this->createItem($reference->grouplinkclass, [
            $reference->getForeignKeyField() => $reference->getID(),
            'groups_id'                      => $this->createGroup($entities_id, 'Reference group')->getID(),
            'type'                           => CommonITILActor::ASSIGN,
        ]);
        $this->assertTrue($reference->getFromDB($reference->getID()));

        $nb_status_logs = $this->countStatusLogs($item);

        $this->createItem(Escalation::class, [
            'itemtype'  => $item::class,
            'items_id'  => $item->getID(),
            'groups_id' => $this->createGroup($entities_id, 'Target group')->getID(),
        ]);

        $this->assertTrue($item->getFromDB($item->getID()));
        $this->assertSame(
            $expected_status ?? (int) $reference->fields['status'],
            (int) $item->fields['status'],
        );

        if ($already_has_status) {
            // No useless update: the status history is left untouched
            $this->assertSame($nb_status_logs, $this->countStatusLogs($item));
        }
    }

    /**
     * @return iterable<string, array{class-string<CommonITILObject>, bool, bool}>
     */
    public static function formDefaultsProvider(): iterable
    {
        foreach ([Ticket::class, Change::class, Problem::class] as $itemtype) {
            yield $itemtype . ' with both options disabled' => [$itemtype, false, false];
            yield $itemtype . ' with "Assign me as observer by default" enabled' => [$itemtype, true, false];
            yield $itemtype . ' with "Private by default" enabled' => [$itemtype, false, true];
            yield $itemtype . ' with both options enabled' => [$itemtype, true, true];
        }
    }

    /**
     * The "Assign me as an observer" and "Private" switches of the escalation form are checked
     * by default according to the entity configuration.
     *
     * @param class-string<CommonITILObject> $itemtype
     */
    #[DataProvider('formDefaultsProvider')]
    public function testEscalationFormDefaults(string $itemtype, bool $observer_by_default, bool $private_by_default): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);
        $this->assertIsInt($entities_id);
        $this->enableEscalation($entities_id, [
            'escalade_assign_me_as_obsever_by_default' => (int) $observer_by_default,
            'escalade_is_private_by_default'           => (int) $private_by_default,
        ]);

        $item = $this->createItem($itemtype, [
            'name'        => 'Test escalation',
            'content'     => 'Test content',
            'entities_id' => $entities_id,
        ]);
        $this->assertInstanceOf(CommonITILObject::class, $item);

        ob_start();
        Escalation::showEscalationForm($item);
        $crawler = new Crawler(ob_get_clean());

        $observer_switch = $crawler->filter('input[type="checkbox"][name="add_me_as_observer"]');
        $this->assertCount(1, $observer_switch);
        $this->assertSame($observer_by_default, $observer_switch->attr('checked') !== null);

        $private_switch = $crawler->filter('input[type="checkbox"][name="is_private"]');
        $this->assertCount(1, $private_switch);
        $this->assertSame($private_by_default, $private_switch->attr('checked') !== null);
    }

    /**
     * @return iterable<string, array{class-string<CommonITILObject>, bool, bool}>
     */
    public static function addMeAsObserverProvider(): iterable
    {
        foreach ([Ticket::class, Change::class, Problem::class] as $itemtype) {
            yield $itemtype . ' with "Assign me as an observer" checked' => [$itemtype, true, false];
            yield $itemtype . ' with "Assign me as an observer" unchecked' => [$itemtype, false, false];
            yield $itemtype . ' with "Assign me as an observer" checked, already observer' => [$itemtype, true, true];
        }
    }

    /**
     * @param class-string<CommonITILObject> $itemtype
     */
    #[DataProvider('addMeAsObserverProvider')]
    public function testAddMeAsObserver(string $itemtype, bool $add_me_as_observer, bool $already_observer): void
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

        $me = $this->getUser(self::TU_USER);
        if ($already_observer) {
            $this->addUserActor($item, $me, CommonITILActor::OBSERVER);
        }

        $this->createItem(Escalation::class, [
            'itemtype'           => $item::class,
            'items_id'           => $item->getID(),
            'groups_id'          => $this->createGroup($entities_id, 'Target group')->getID(),
            'add_me_as_observer' => (int) $add_me_as_observer,
        ], ['add_me_as_observer']);

        // The author is an observer (only once) when asked, or when they already were
        $this->assertSame(
            $add_me_as_observer || $already_observer ? [$me->getID()] : [],
            $this->getUserActorIds($item, CommonITILActor::OBSERVER),
        );
    }

    /**
     * @return iterable<string, array{class-string<CommonITILObject>, bool}>
     */
    public static function privateEscalationProvider(): iterable
    {
        foreach ([Ticket::class, Change::class, Problem::class] as $itemtype) {
            yield $itemtype . ' with a private escalation' => [$itemtype, true];
            yield $itemtype . ' with a public escalation' => [$itemtype, false];
        }
    }

    /**
     * A private escalation is shown in the timeline only to the users allowed to see private
     * followups.
     *
     * @param class-string<CommonITILObject> $itemtype
     */
    #[DataProvider('privateEscalationProvider')]
    public function testPrivateEscalation(string $itemtype, bool $is_private): void
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

        $escalation = $this->createItem(Escalation::class, [
            'itemtype'   => $item::class,
            'items_id'   => $item->getID(),
            'groups_id'  => $this->createGroup($entities_id, 'Target group')->getID(),
            'is_private' => (int) $is_private,
        ]);
        $this->assertSame((int) $is_private, (int) $escalation->fields['is_private']);

        // Allowed to see private followups: the escalation is always shown, flagged as private
        $this->assertTrue(Session::haveRight('followup', ITILFollowup::SEEPRIVATE));
        $entries = $this->getEscalationTimelineEntries($item);
        $this->assertCount(1, $entries);
        $this->assertSame((int) $is_private, (int) $entries[0]['item']['is_private']);

        // Not allowed to see private followups: a private escalation is hidden
        $this->login('post-only', 'postonly');
        $this->assertFalse(Session::haveRight('followup', ITILFollowup::SEEPRIVATE));
        $this->assertCount($is_private ? 0 : 1, $this->getEscalationTimelineEntries($item));
    }

    /**
     * Enable the escalation option for the given entity.
     *
     * @param array<string, mixed> $options Other escalation options to set
     */
    private function enableEscalation(int $entities_id, array $options = []): void
    {
        $fields = ['escalate_is_active' => 1] + $options;

        $config = Config::getConfig($entities_id, false);
        if ($config->isNewItem()) {
            $this->createTestConfig(['entities_id' => $entities_id] + $fields);
        } else {
            $this->updateTestConfig($config, $fields);
        }
    }

    private function getUser(string $name): User
    {
        $user = new User();
        $this->assertTrue($user->getFromDBByCrit(['name' => $name]));

        return $user;
    }

    private function addUserActor(CommonITILObject $item, User $user, int $type): void
    {
        $this->createItem($item->userlinkclass, [
            $item->getForeignKeyField() => $item->getID(),
            'users_id'                  => $user->getID(),
            'type'                      => $type,
        ]);
    }

    /**
     * @return array<int>
     */
    private function getUserActorIds(CommonITILObject $item, int $type): array
    {
        $user_link = getItemForItemtype($item->userlinkclass);
        $this->assertInstanceOf(CommonITILActor::class, $user_link);

        return array_map(
            static fn(array $row): int => (int) $row['users_id'],
            array_values($user_link->find([
                $item->getForeignKeyField() => $item->getID(),
                'type'                      => $type,
            ], ['id ASC'])),
        );
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
     * @return array<array{type: string, item: array<string, mixed>}>
     */
    private function getEscalationTimelineEntries(CommonITILObject $item): array
    {
        $this->assertTrue($item->getFromDB($item->getID()));

        return array_values(array_filter(
            $item->getTimelineItems(),
            static fn(array $entry): bool => $entry['type'] === Escalation::class,
        ));
    }

    /**
     * Number of changes of the item status in its history.
     */
    private function countStatusLogs(CommonITILObject $item): int
    {
        return countElementsInTable(Log::getTable(), [
            'itemtype'         => $item::class,
            'items_id'         => $item->getID(),
            'id_search_option' => 12,
        ]);
    }

    /**
     * @param array<Group|User> $items
     * @return array<int>
     */
    private function getIdsOf(array $items): array
    {
        return array_map(static fn(Group|User $item): int => $item->getID(), $items);
    }
}
