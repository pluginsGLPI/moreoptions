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

use GlpiPlugin\Moreoptions\Config;
use GlpiPlugin\Moreoptions\Tests\MoreOptionsTestCase;

class ConfigTest extends MoreOptionsTestCase
{
    /**
     * Test mandatory fields for tasks
     */
    public function testTaskMandatoryField(): void
    {
        $conf = $this->getCurrentConfig();

        $result = $this->updateTestConfig($conf, [
            'is_active'               => 1,
            'entities_id'             => 0,
            'mandatory_task_category' => 1,
            'mandatory_task_duration' => 1,
            'mandatory_task_user'     => 1,
            'mandatory_task_group'    => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        //Create a ticket
        $ticket = new \Ticket();
        $ticket->add(
            [
                'name'          => 'Test ticket task mandatory fields',
                'content'       => 'Test content',
            ],
        );
        $this->assertNotFalse($ticket->getID());

        //Create a task without mandatory fields (Expected to fail)
        $task = new \TicketTask();
        $result = $task->add(
            [
                'tickets_id'    => $ticket->getID(),
                'content'          => 'Test task',
                'state'             => \Planning::TODO,
            ],
        );
        $this->assertFalse($result);

        // Create category
        $category = new \TaskCategory();
        $result = $category->add(
            [
                'name' => 'Test category',
            ],
        );
        $this->assertNotFalse($result);

        //Create a task with mandatory fields (Expected to succeed)
        $task = new \TicketTask();
        $result = $task->add(
            [
                'tickets_id'    => $ticket->getID(),
                'content'          => 'Test task',
                'taskcategories_id' => 1,
                'users_id_tech'      => 1,
                'groups_id_tech'     => 1,
                'actiontime'         => 300,
                'state'             => \Planning::TODO,
            ],
        );
        $this->assertNotFalse($result);

        // Create task without user (Expected to fail)
        $task = new \TicketTask();
        $result = $task->add(
            [
                'tickets_id'    => $ticket->getID(),
                'content'          => 'Test task without user',
                'taskcategories_id' => 1,
                'groups_id_tech'     => 1,
                'actiontime'         => 300,
                'state'             => \Planning::TODO,
            ],
        );
        $this->assertFalse($result);

        // Create task without group (Expected to fail)
        $task = new \TicketTask();
        $result = $task->add(
            [
                'tickets_id'    => $ticket->getID(),
                'content'          => 'Test task without group',
                'taskcategories_id' => 1,
                'users_id_tech'      => 1,
                'actiontime'         => 300,
                'state'             => \Planning::TODO,
            ],
        );
        $this->assertFalse($result);

        // Create task without duration (Expected to fail)
        $task = new \TicketTask();
        $result = $task->add(
            [
                'tickets_id'    => $ticket->getID(),
                'content'          => 'Test task without duration',
                'taskcategories_id' => 1,
                'users_id_tech'      => 1,
                'groups_id_tech'     => 1,
                'state'             => \Planning::TODO,
            ],
        );
        $this->assertFalse($result);

        // Create task without category (Expected to fail)
        $task = new \TicketTask();
        $result = $task->add(
            [
                'tickets_id'    => $ticket->getID(),
                'content'          => 'Test task without category',
                'users_id_tech'      => 1,
                'groups_id_tech'     => 1,
                'actiontime'         => 300,
                'state'             => \Planning::TODO,
            ],
        );
        $this->assertFalse($result);

        //Check if we have only 1 task
        $tasks = new \TicketTask();
        $tasks = count($tasks->find(['tickets_id' => $ticket->getID()]));
        $this->assertEquals(1, $tasks);

        // Reset config
        $resetResult = $this->updateTestConfig($conf, [
            'mandatory_task_category' => 0,
            'mandatory_task_duration' => 0,
            'mandatory_task_user'     => 0,
            'mandatory_task_group'    => 0,
        ]);
        $this->assertTrue($resetResult);
    }

    /**
     * Test mandatory fields before closing a ticket
     */
    public function testTicketMandatoryFieldsBeforeCloseTicket(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        // Configure mandatory fields before closing
        $result = $this->updateTestConfig($conf, [
            'is_active'                              => 1,
            'entities_id'                            => 0,
            'require_technician_to_close_ticket'    => 1,
            'require_technicians_group_to_close_ticket' => 1,
            'require_category_to_close_ticket'       => 1,
            'require_location_to_close_ticket'       => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        //Create a ticket without mandatory fields (Expected to succeed)
        $ticket = new \Ticket();
        $tid = $ticket->add(
            [
                'name'          => 'Test ticket close',
                'content'       => 'Test content',
            ],
        );
        $this->assertGreaterThan(0, $tid);

        // Create group
        $group = new \Group();
        $gid = $group->add(
            [
                'name' => 'Test group close ticket',
            ],
        );
        $this->assertNotFalse($gid);

        // Close the ticket without mandatory fields (Expected to fail)
        $ticket = new \Ticket();
        $result = $ticket->update(
            [
                'id'          => $tid,
                'status'      => \Ticket::CLOSED,
            ],
        );
        $this->assertFalse($result);

        // Create category
        $category = new \ITILCategory();
        $cid = $category->add(
            [
                'name' => 'Test category close ticket',
            ],
        );
        $this->assertNotFalse($cid);

        // Create location
        $location = new \Location();
        $lid = $location->add(
            [
                'name' => 'Test location close ticket',
            ],
        );
        $this->assertNotFalse($lid);

        // Add technician group to the ticket
        $gticket = new \Group_Ticket();
        $this->assertNotFalse($gticket->add(
            [
                'tickets_id' => $tid,
                'groups_id'  => $gid,
                'type'       => \Group_Ticket::ASSIGN,
            ],
        ));

        // Add technician to the ticket
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'glpi',
            ],
        ));

        $uticket = new \Ticket_User();
        $this->assertNotFalse($uticket->add(
            [
                'tickets_id' => $tid,
                'users_id'   => $user->getID(),
                'type'       => \Ticket_User::ASSIGN,
            ],
        ));

        // Close the ticket without location and category (Expected to fail)
        $ticket = new \Ticket();
        $this->assertFalse($ticket->update(
            [
                'id'                => $tid,
                'status'            => \Ticket::CLOSED,
            ],
        ));

        // Close the ticket with location and category (Expected to succeed)
        $ticket = new \Ticket();
        $this->assertTrue($ticket->update(
            [
                'id'                => $tid,
                'locations_id'     => $lid,
                'itilcategories_id' => $cid,
                'status'            => \Ticket::CLOSED,
            ],
        ));

        // Reset config
        $resetResult = $this->updateTestConfig($conf, [
            'require_technician_to_close_ticket'     => 0,
            'require_technicians_group_to_close_ticket' => 0,
            'require_category_to_close_ticket'        => 0,
            'require_location_to_close_ticket'        => 0,
        ]);
        $this->assertTrue($resetResult);
    }

    /**
     * Test mandatory fields before closing a change
     */
    public function testChangeMandatoryFieldsBeforeCloseChange(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        // Configure mandatory fields before closing
        $result = $this->updateTestConfig($conf, [
            'is_active'                              => 1,
            'entities_id'                            => 0,
            'require_technician_to_close_change'    => 1,
            'require_technicians_group_to_close_change' => 1,
            'require_category_to_close_change'       => 1,
            'require_location_to_close_change'       => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        //Create a change without mandatory fields (Expected to succeed)
        $change = new \Change();
        $cid = $change->add(
            [
                'name'          => 'Test change close',
                'content'       => 'Test content',
            ],
        );
        $this->assertGreaterThan(0, $cid);

        // Create group
        $group = new \Group();
        $gid = $group->add(
            [
                'name' => 'Test group close change',
            ],
        );
        $this->assertNotFalse($gid);

        // Close the change without mandatory fields (Expected to fail)
        $change = new \Change();
        $result = $change->update(
            [
                'id'          => $cid,
                'status'      => \Change::CLOSED,
            ],
        );
        $this->assertFalse($result);

        // Create category
        $category = new \ITILCategory();
        $catid = $category->add(
            [
                'name' => 'Test category close change',
            ],
        );
        $this->assertNotFalse($catid);

        // Create location
        $location = new \Location();
        $lid = $location->add(
            [
                'name' => 'Test location close change',
            ],
        );
        $this->assertNotFalse($lid);

        // Add technician group to the change
        $gchange = new \Change_Group();
        $this->assertNotFalse($gchange->add(
            [
                'changes_id' => $cid,
                'groups_id'  => $gid,
                'type'       => \Change_Group::ASSIGN,
            ],
        ));

        // Add technician to the change
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'glpi',
            ],
        ));

        $uchange = new \Change_User();
        $this->assertNotFalse($uchange->add(
            [
                'changes_id' => $cid,
                'users_id'   => $user->getID(),
                'type'       => \Change_User::ASSIGN,
            ],
        ));

        // Close the change without location and category (Expected to fail)
        $change = new \Change();
        $this->assertFalse($change->update(
            [
                'id'                => $cid,
                'status'            => \Change::CLOSED,
            ],
        ));

        // Close the change with location and category (Expected to succeed)
        $change = new \Change();
        $this->assertTrue($change->update(
            [
                'id'                => $cid,
                'locations_id'     => $lid,
                'itilcategories_id' => $catid,
                'status'            => \Change::CLOSED,
            ],
        ));

        // Reset config
        $resetResult = $this->updateTestConfig($conf, [
            'require_technician_to_close_change'     => 0,
            'require_technicians_group_to_close_change' => 0,
            'require_category_to_close_change'        => 0,
            'require_location_to_close_change'        => 0,
        ]);
        $this->assertTrue($resetResult);
    }

    /**
     * Test mandatory fields before closing a problem
     */
    public function testProblemMandatoryFieldsBeforeCloseProblem(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        // Configure mandatory fields before closing
        $result = $this->updateTestConfig($conf, [
            'is_active'                              => 1,
            'entities_id'                            => 0,
            'require_technician_to_close_problem'    => 1,
            'require_technicians_group_to_close_problem' => 1,
            'require_category_to_close_problem'       => 1,
            'require_location_to_close_problem'       => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        //Create a problem without mandatory fields (Expected to succeed)
        $problem = new \Problem();
        $pid = $problem->add(
            [
                'name'          => 'Test problem close',
                'content'       => 'Test content',
            ],
        );
        $this->assertGreaterThan(0, $pid);

        // Create group
        $group = new \Group();
        $gid = $group->add(
            [
                'name' => 'Test group close problem',
            ],
        );
        $this->assertNotFalse($gid);

        // Close the problem without mandatory fields (Expected to fail)
        $problem = new \Problem();
        $result = $problem->update(
            [
                'id'          => $pid,
                'status'      => \Problem::CLOSED,
            ],
        );
        $this->assertFalse($result);

        // Create category
        $category = new \ITILCategory();
        $catid = $category->add(
            [
                'name' => 'Test category close problem',
            ],
        );
        $this->assertNotFalse($catid);

        // Create location
        $location = new \Location();
        $lid = $location->add(
            [
                'name' => 'Test location close problem',
            ],
        );
        $this->assertNotFalse($lid);

        // Add technician group to the problem
        $gproblem = new \Group_Problem();
        $this->assertNotFalse($gproblem->add(
            [
                'problems_id' => $pid,
                'groups_id'  => $gid,
                'type'       => \Group_Problem::ASSIGN,
            ],
        ));

        // Add technician to the problem
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'glpi',
            ],
        ));

        $uproblem = new \Problem_User();
        $this->assertNotFalse($uproblem->add(
            [
                'problems_id' => $pid,
                'users_id'   => $user->getID(),
                'type'       => \Problem_User::ASSIGN,
            ],
        ));

        // Close the problem without location and category (Expected to fail)
        $problem = new \Problem();
        $this->assertFalse($problem->update(
            [
                'id'                => $pid,
                'status'            => \Problem::CLOSED,
            ],
        ));

        // Close the problem with location and category (Expected to succeed)
        $problem = new \Problem();
        $this->assertTrue($problem->update(
            [
                'id'                => $pid,
                'locations_id'     => $lid,
                'itilcategories_id' => $catid,
                'status'            => \Problem::CLOSED,
            ],
        ));

        // Reset config
        $resetResult = $this->updateTestConfig($conf, [
            'require_technician_to_close_problem'     => 0,
            'require_technicians_group_to_close_problem' => 0,
            'require_category_to_close_problem'        => 0,
            'require_location_to_close_problem'        => 0,
        ]);
        $this->assertTrue($resetResult);
    }

    /**
     * Test take the requester group
     */
    public function testTakeTheRequesterGroup(): void
    {
        $conf = $this->getCurrentConfig();

        // Configure to take all groups of the requester
        $result = $this->updateTestConfig($conf, [
            'is_active'                   => 1,
            'entities_id'                 => 0,
            'take_requester_group_ticket' => 2, // All
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        // Create two groups
        $group1 = new \Group();
        $result = $group1->add(
            [
                'name' => 'Test group 1',
            ],
        );
        $this->assertNotFalse($result);

        $group2 = new \Group();
        $result = $group2->add(
            [
                'name' => 'Test group 2',
            ],
        );
        $this->assertNotFalse($result);

        // Get the user glpi
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'glpi',
            ],
        ));

        // Assign the user to the group
        $group_user = new \Group_User();
        $result = $group_user->add(
            [
                'groups_id' => $group1->getID(),
                'users_id'  => $user->getID(),
            ],
        );
        $this->assertNotFalse($result);

        $result = $group_user->add(
            [
                'groups_id' => $group2->getID(),
                'users_id'  => $user->getID(),
            ],
        );
        $this->assertNotFalse($result);

        //Create a ticket
        $ticket = new \Ticket();
        $tid = $ticket->add(
            [
                'name'          => 'Test ticket requester group',
                'content'       => 'Test content',
            ],
        );
        $this->assertGreaterThan(0, $tid);

        $uticket = new \Ticket_User();
        $this->assertNotFalse($uticket->add(
            [
                'tickets_id' => $tid,
                'users_id'   => $user->getID(),
                'type'       => \Ticket_User::REQUESTER,
            ],
        ));

        // Check if the group of the requester is in the actors
        $ticket_group = new \Group_Ticket();
        $groups = $ticket_group->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(2, $groups);

        $config = new Config();
        // Configurer pour ne prendre que le groupe principal du demandeur
        $result = $this->updateTestConfig($conf, [
            'is_active'                   => 1,
            'entities_id'                 => 0,
            'take_requester_group_ticket' => 1, // Default
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        //Create a ticket
        $ticket = new \Ticket();
        $tid = $ticket->add(
            [
                'name'          => 'Test ticket requester group - 2',
                'content'       => 'Test content',
            ],
        );
        $this->assertNotFalse($ticket->getID());

        //Add default group to the user
        $user2 = new \User();
        $this->assertTrue($user2->update(
            [
                'id'   => $user->getID(),
                'groups_id' => $group1->getID(),
            ],
        ));

        $uticket = new \Ticket_User();
        $this->assertNotFalse($uticket->add(
            [
                'tickets_id' => $tid,
                'users_id'   => $user2->getID(),
                'type'       => \Ticket_User::REQUESTER,
            ],
        ));

        // Check if the group of the requester is in the actors
        $ticket_group = new \Group_Ticket();
        $groups = $ticket_group->find(['tickets_id' => $tid]);
        $this->assertCount(1, $groups);

        // Reset config
        // Réinitialiser la configuration
        $resetResult = $this->updateTestConfig($conf, [
            'is_active'                   => 1,
            'entities_id'                 => 0,
            'take_requester_group_ticket' => 0, // Default
        ]);
        $this->assertTrue($resetResult);
    }

    /**
     * Test take the technician group
     */
    public function testTakeTheTechnicianGroup(): void
    {
        $conf = $this->getCurrentConfig();

        // Setup to take all groups of the technician
        $result = $this->updateTestConfig($conf, [
            'is_active'                       => 1,
            'entities_id'                  => 0,
            'take_technician_group_ticket' => 2, // All
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        // Create two groups
        $group1 = new \Group();
        $result = $group1->add(
            [
                'name' => 'Test group 1',
            ],
        );
        $this->assertNotFalse($result);

        $group2 = new \Group();
        $result = $group2->add(
            [
                'name' => 'Test group 2',
            ],
        );
        $this->assertNotFalse($result);

        // Get the user tech
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'tech',
            ],
        ));

        // Assign the user to the group
        $group_user = new \Group_User();
        $result = $group_user->add(
            [
                'groups_id' => $group1->getID(),
                'users_id'  => $user->getID(),
            ],
        );
        $this->assertNotFalse($result);

        $result = $group_user->add(
            [
                'groups_id' => $group2->getID(),
                'users_id'  => $user->getID(),
            ],
        );
        $this->assertNotFalse($result);

        //Create a ticket
        $ticket = new \Ticket();
        $tid = $ticket->add(
            [
                'name'          => 'Test ticket',
                'content'       => 'Test content',
            ],
        );
        $this->assertNotFalse($ticket->getID());

        $uticket = new \Ticket_User();
        $this->assertNotFalse($uticket->add(
            [
                'tickets_id' => $tid,
                'users_id'   => $user->getID(),
                'type'       => \Ticket_User::ASSIGN,
            ],
        ));

        // Check if the group of the requester is in the actors
        $ticket_group = new \Group_Ticket();
        $groups = $ticket_group->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(2, $groups);

        // Setup to take only the main group of the technician
        $result = $this->updateTestConfig($conf, [
            'is_active'                    => 1,
            'entities_id'                  => 0,
            'take_technician_group_ticket' => 1, // Default
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        //Create a ticket
        $ticket = new \Ticket();
        $tid = $ticket->add(
            [
                'name'          => 'Test ticket tech group - 2',
                'content'       => 'Test content',
            ],
        );
        $this->assertNotFalse($ticket->getID());

        //Add default group to the user
        $user2 = new \User();
        $this->assertTrue($user2->update(
            [
                'id'   => $user->getID(),
                'groups_id' => $group1->getID(),
            ],
        ));

        $uticket = new \Ticket_User();
        $this->assertNotFalse($uticket->add(
            [
                'tickets_id' => $tid,
                'users_id'   => $user2->getID(),
                'type'       => \Ticket_User::ASSIGN,
            ],
        ));

        // Check if the group of the requester is in the actors
        $ticket_group = new \Group_Ticket();
        $groups = $ticket_group->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(1, $groups);
    }

    /**
     * Test take the item groups
     */
    public function testTakeItemGroups(): void
    {
        $conf = $this->getCurrentConfig();

        // Setup to take the groups of the items
        $result = $this->updateTestConfig($conf, [
            'is_active'              => 1,
            'entities_id'            => 0,
            'take_item_group_ticket' => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();

        // Create two groups
        $group1 = new \Group();
        $result = $group1->add(
            [
                'name' => 'Test group 1',
            ],
        );
        $this->assertNotFalse($result);

        //Create item computer
        $computer = new \Computer();
        $cid = $computer->add(
            [
                'name' => 'Test computer',
            ],
        );
        $this->assertNotFalse($cid);

        //Create item ticket
        $group_item = new \Group_Item();
        $this->assertNotFalse($group_item->add(
            [
                'items_id'   => $computer->getID(),
                'itemtype'   => \Computer::class,
                'groups_id'  => $group1->getID(),
                'type'       => 1,
            ],
        ));

        //Create a ticket
        $ticket = new \Ticket();
        $tid = $ticket->add(
            [
                'name'          => 'Test ticket item groups',
                'content'       => 'Test content',
            ],
        );
        $this->assertGreaterThan(0, $tid);

        // Assign the computer to the ticket
        $item_ticket = new \Item_Ticket();
        $this->assertNotFalse($item_ticket->add(
            [
                'tickets_id' => $tid,
                'items_id'   => $computer->getID(),
                'itemtype'   => \Computer::class,
            ],
        ));

        // Check if the groups are in the actors
        $ticket_group = new \Group_Ticket();
        $groups = $ticket_group->find(
            [
                'tickets_id' => $ticket->getID(),
                'type' => \CommonITILActor::ASSIGN,
            ],
        );
        $this->assertCount(1, $groups);
    }

    /**
     * Test automatic assignment of technical manager and group when updating ticket category
     */
    public function testUpdateTicketActorsOnCategoryChange(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        // Configure to assign technical manager and group when changing category
        $result = $this->updateTestConfig($conf, [
            'is_active' => 1,
            'entities_id' => 0,
            'assign_technical_manager_when_changing_category_ticket' => 1,
            'assign_technical_group_when_changing_category_ticket' => 1,
        ]);
        $this->assertTrue($result);

        // Create a group for the category
        $group = new \Group();
        $gid = $group->add([
            'name' => 'Test Technical Group',
        ]);
        $this->assertNotFalse($gid);

        // Create a user for technical manager
        $user = new \User();
        $uid = $user->add([
            'name' => 'test_tech_manager',
            'login' => 'test_tech_manager',
        ]);
        $this->assertNotFalse($uid);

        // Create a category with technical manager and group
        $category = new \ITILCategory();
        $cid = $category->add([
            'name' => 'Test Category with Tech',
            'users_id' => $uid,
            'groups_id' => $gid,
        ]);
        $this->assertNotFalse($cid);

        // Create a ticket
        $ticket = new \Ticket();
        $tid = $ticket->add([
            'name' => 'Test ticket category update',
            'content' => 'Test content',
        ]);
        $this->assertGreaterThan(0, $tid);

        // Update ticket with the category
        $ticket = new \Ticket();
        $this->assertTrue($ticket->update([
            'id' => $tid,
            'itilcategories_id' => $cid,
        ]));

        // Check if technical manager was assigned
        $ticket_user = new \Ticket_User();
        $assigned_users = $ticket_user->find([
            'tickets_id' => $tid,
            'users_id' => $uid,
            'type' => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(1, $assigned_users);

        // Check if technical group was assigned
        $ticket_group = new \Group_Ticket();
        $assigned_groups = $ticket_group->find([
            'tickets_id' => $tid,
            'groups_id' => $gid,
            'type' => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(1, $assigned_groups);

        // Reset config
        $resetResult = $this->updateTestConfig($conf, [
            'assign_technical_manager_when_changing_category_ticket' => 0,
            'assign_technical_group_when_changing_category_ticket' => 0,
        ]);
        $this->assertTrue($resetResult);
    }

    /**
     * Test automatic assignment of technical manager and group when updating change category
     */
    public function testUpdateChangeActorsOnCategoryChange(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        // Configure to assign technical manager and group when changing category
        $result = $this->updateTestConfig($conf, [
            'is_active' => 1,
            'entities_id' => 0,
            'assign_technical_manager_when_changing_category_change' => 1,
            'assign_technical_group_when_changing_category_change' => 1,
        ]);
        $this->assertTrue($result);

        // Create a group for the category
        $group = new \Group();
        $gid = $group->add([
            'name' => 'Test Technical Group Change',
        ]);
        $this->assertNotFalse($gid);

        // Create a user for technical manager
        $user = new \User();
        $uid = $user->add([
            'name' => 'test_tech_manager_change',
            'login' => 'test_tech_manager_change',
        ]);
        $this->assertNotFalse($uid);

        // Create a category with technical manager and group
        $category = new \ITILCategory();
        $cid = $category->add([
            'name' => 'Test Category with Tech Change',
            'users_id' => $uid,
            'groups_id' => $gid,
        ]);
        $this->assertNotFalse($cid);

        // Create a change
        $change = new \Change();
        $chid = $change->add([
            'name' => 'Test change category update',
            'content' => 'Test content',
        ]);
        $this->assertGreaterThan(0, $chid);

        // Update change with the category
        $change = new \Change();
        $this->assertTrue($change->update([
            'id' => $chid,
            'itilcategories_id' => $cid,
        ]));

        // Check if technical manager was assigned
        $change_user = new \Change_User();
        $assigned_users = $change_user->find([
            'changes_id' => $chid,
            'users_id' => $uid,
            'type' => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(1, $assigned_users);

        // Check if technical group was assigned
        $change_group = new \Change_Group();
        $assigned_groups = $change_group->find([
            'changes_id' => $chid,
            'groups_id' => $gid,
            'type' => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(1, $assigned_groups);

        // Reset config
        $resetResult = $this->updateTestConfig($conf, [
            'assign_technical_manager_when_changing_category_change' => 0,
            'assign_technical_group_when_changing_category_change' => 0,
        ]);
        $this->assertTrue($resetResult);
    }

    /**
     * Test automatic assignment of technical manager and group when updating problem category
     */
    public function testUpdateProblemActorsOnCategoryChange(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        // Configure to assign technical manager and group when changing category
        $result = $this->updateTestConfig($conf, [
            'is_active' => 1,
            'entities_id' => 0,
            'assign_technical_manager_when_changing_category_problem' => 1,
            'assign_technical_group_when_changing_category_problem' => 1,
        ]);
        $this->assertTrue($result);

        // Create a group for the category
        $group = new \Group();
        $gid = $group->add([
            'name' => 'Test Technical Group Problem',
        ]);
        $this->assertNotFalse($gid);

        // Create a user for technical manager
        $user = new \User();
        $uid = $user->add([
            'name' => 'test_tech_manager_problem',
            'login' => 'test_tech_manager_problem',
        ]);
        $this->assertNotFalse($uid);

        // Create a category with technical manager and group
        $category = new \ITILCategory();
        $cid = $category->add([
            'name' => 'Test Category with Tech Problem',
            'users_id' => $uid,
            'groups_id' => $gid,
        ]);
        $this->assertNotFalse($cid);

        // Create a problem
        $problem = new \Problem();
        $pid = $problem->add([
            'name' => 'Test problem category update',
            'content' => 'Test content',
        ]);
        $this->assertGreaterThan(0, $pid);

        // Update problem with the category
        $problem = new \Problem();
        $this->assertTrue($problem->update([
            'id' => $pid,
            'itilcategories_id' => $cid,
        ]));

        // Check if technical manager was assigned
        $problem_user = new \Problem_User();
        $assigned_users = $problem_user->find([
            'problems_id' => $pid,
            'users_id' => $uid,
            'type' => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(1, $assigned_users);

        // Check if technical group was assigned
        $problem_group = new \Group_Problem();
        $assigned_groups = $problem_group->find([
            'problems_id' => $pid,
            'groups_id' => $gid,
            'type' => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(1, $assigned_groups);

        // Reset config
        $resetResult = $this->updateTestConfig($conf, [
            'assign_technical_manager_when_changing_category_problem' => 0,
            'assign_technical_group_when_changing_category_problem' => 0,
        ]);
        $this->assertTrue($resetResult);
    }

    /**
     * Test that actors are not assigned when configuration is disabled
     */
    public function testUpdateActorsDisabledConfiguration(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        // Ensure configuration is disabled
        $result = $this->updateTestConfig($conf, [
            'is_active' => 1,
            'entities_id' => 0,
            'assign_technical_manager_when_changing_category_ticket' => 0,
            'assign_technical_group_when_changing_category_ticket' => 0,
        ]);
        $this->assertTrue($result);

        // Create a category with technical manager and group
        $group = new \Group();
        $gid = $group->add(['name' => 'Test Group Disabled']);
        $this->assertNotFalse($gid);

        $user = new \User();
        $uid = $user->add(['name' => 'test_user_disabled', 'login' => 'test_user_disabled']);
        $this->assertNotFalse($uid);

        $category = new \ITILCategory();
        $cid = $category->add([
            'name' => 'Test Category Disabled',
            'users_id' => $uid,
            'groups_id' => $gid,
        ]);
        $this->assertNotFalse($cid);

        // Create and update a ticket
        $ticket = new \Ticket();
        $tid = $ticket->add(['name' => 'Test disabled config', 'content' => 'Test content']);
        $this->assertGreaterThan(0, $tid);

        $ticket = new \Ticket();
        $this->assertTrue($ticket->update(['id' => $tid, 'itilcategories_id' => $cid]));

        // Verify no technical actors were assigned
        $ticket_user = new \Ticket_User();
        $assigned_users = $ticket_user->find([
            'tickets_id' => $tid,
            'users_id' => $uid,
            'type' => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(0, $assigned_users);

        $ticket_group = new \Group_Ticket();
        $assigned_groups = $ticket_group->find([
            'tickets_id' => $tid,
            'groups_id' => $gid,
            'type' => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(0, $assigned_groups);
    }
  
    /**
     * Test parent entity configuration inheritance
     */
    public function testParentEntityConfigInheritance(): void
    {
        $parent_entity_id = 0;
        // Create child entity
        $child_entity = new \Entity();
        $child_entity_id = $child_entity->add([
            'name' => 'Child Entity Test',
            'entities_id' => 0, // Parent entity as parent
        ]);
        $this->assertGreaterThan(0, $child_entity_id);

        // Configure parent entity with specific settings
        $conf = Config::getEffectiveConfigForEntity(0);
        $parent_config = new Config();
        $parent_config_id = $parent_config->update([
            'id' => $conf->getID(),
            'entities_id' => $parent_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 0, // This is the source config
            'take_item_group_ticket' => 1,
            'prevent_closure_ticket' => 1,
            'require_technician_to_close_ticket' => 1,
            'mandatory_task_category' => 1,
        ]);
        $this->assertGreaterThan(0, $parent_config_id);

        // Configure child entity to use parent configuration
        $this->assertIsInt($child_entity_id);
        $child_conf = Config::getEffectiveConfigForEntity($child_entity_id);
        $child_config = new Config();
        $child_config_id = $child_config->update([
            'id' => $child_conf->getID(),
            'entities_id' => $child_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 1, // Enable inheritance
            'take_item_group_ticket' => 0, // These values should be ignored
            'prevent_closure_ticket' => 0,
            'require_technician_to_close_ticket' => 0,
            'mandatory_task_category' => 0,
        ]);
        $this->assertGreaterThan(0, $child_config_id);

        // Test effective configuration for child entity
        $effective_config = Config::getEffectiveConfigForEntity($child_entity_id);

        // Should return parent config
        $this->assertEquals($parent_entity_id, $effective_config->fields['entities_id']);
        $this->assertEquals(1, $effective_config->fields['take_item_group_ticket']);
        $this->assertEquals(1, $effective_config->fields['prevent_closure_ticket']);
        $this->assertEquals(1, $effective_config->fields['require_technician_to_close_ticket']);
        $this->assertEquals(1, $effective_config->fields['mandatory_task_category']);
    }

    /**
     * Test multi-level parent entity configuration inheritance
     */
    public function testMultiLevelParentEntityConfigInheritance(): void
    {
        // Create grandparent entity (level 1)
        $grandparent_entity = new \Entity();
        $grandparent_entity_id = $grandparent_entity->add([
            'name' => 'Grandparent Entity Test',
            'entities_id' => 0, // Root entity as parent
        ]);
        $this->assertIsInt($grandparent_entity_id);
        $this->assertGreaterThan(0, $grandparent_entity_id);

        // Create parent entity (level 2)
        $parent_entity = new \Entity();
        $parent_entity_id = $parent_entity->add([
            'name' => 'Parent Entity Test Level 2',
            'entities_id' => $grandparent_entity_id,
        ]);
        $this->assertGreaterThan(0, $parent_entity_id);

        // Create child entity (level 3)
        $child_entity = new \Entity();
        $child_entity_id = $child_entity->add([
            'name' => 'Child Entity Test Level 3',
            'entities_id' => $parent_entity_id,
        ]);
        $this->assertGreaterThan(0, $child_entity_id);

        // Configure grandparent entity with specific settings
        $grandparent_conf = Config::getEffectiveConfigForEntity($grandparent_entity_id);
        $grandparent_config = new Config();
        $grandparent_config->update([
            'id' => $grandparent_conf->getID(),
            'entities_id' => $grandparent_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 0, // This is the source config
            'take_item_group_ticket' => 1,
            'prevent_closure_ticket' => 1,
            'require_technician_to_close_ticket' => 1,
        ]);
        $this->assertGreaterThan(0, $grandparent_conf->getID());

        // Configure parent entity to use parent configuration (cascade)
        $this->assertIsInt($parent_entity_id);
        $parent_conf = Config::getEffectiveConfigForEntity($parent_entity_id);
        $parent_config = new Config();
        $parent_config->update([
            'id' => $parent_conf->getID(),
            'entities_id' => $parent_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 1, // Cascade to grandparent
            'take_item_group_ticket' => 0, // Should be ignored
            'prevent_closure_ticket' => 0,
            'require_technician_to_close_ticket' => 0,
        ]);
        $this->assertGreaterThan(0, $parent_conf->getID());

        // Configure child entity to use parent configuration
        $this->assertIsInt($child_entity_id);
        $child_conf = Config::getEffectiveConfigForEntity($child_entity_id);
        $child_config = new Config();
        $child_config->update([
            'id' => $child_conf->getID(),
            'entities_id' => $child_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 1, // Should cascade to grandparent
            'take_item_group_ticket' => 0, // Should be ignored
            'prevent_closure_ticket' => 0,
            'require_technician_to_close_ticket' => 0,
        ]);
        $this->assertGreaterThan(0, $child_conf->getID());

        // Test effective configuration for child entity (should cascade to grandparent)
        $effective_config = Config::getEffectiveConfigForEntity($child_entity_id);

        // Should return grandparent config (skipping parent because it also has use_parent_entity = 1)
        $this->assertEquals($grandparent_entity_id, $effective_config->fields['entities_id']);
        $this->assertEquals(1, $effective_config->fields['take_item_group_ticket']);
        $this->assertEquals(1, $effective_config->fields['prevent_closure_ticket']);
        $this->assertEquals(1, $effective_config->fields['require_technician_to_close_ticket']);
    }

    /**
     * Test that child entity without use_parent_entity uses its own config
     */
    public function testChildEntityWithoutInheritanceUsesOwnConfig(): void
    {
        // Create parent entity
        $parent_entity = new \Entity();
        $parent_entity_id = $parent_entity->add([
            'name' => 'Parent Entity No Inherit Test',
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $parent_entity_id);

        // Create child entity
        $child_entity = new \Entity();
        $child_entity_id = $child_entity->add([
            'name' => 'Child Entity No Inherit Test',
            'entities_id' => $parent_entity_id,
        ]);
        $this->assertGreaterThan(0, $child_entity_id);

        // Configure parent entity
        $this->assertIsInt($parent_entity_id);
        $parent_conf = Config::getEffectiveConfigForEntity($parent_entity_id);
        $parent_config = new Config();
        $parent_config_id = $parent_config->update([
            'id' => $parent_conf->getID(),
            'entities_id' => $parent_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 0,
            'take_item_group_ticket' => 1,
            'prevent_closure_ticket' => 1,
        ]);
        $this->assertGreaterThan(0, $parent_config_id);

        // Configure child entity WITHOUT inheritance
        $this->assertIsInt($child_entity_id);
        $child_conf = Config::getEffectiveConfigForEntity($child_entity_id);
        $child_config = new Config();
        $child_config_id = $child_config->update([
            'id' => $child_conf->getID(),
            'entities_id' => $child_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 0, // NO inheritance
            'take_item_group_ticket' => 0, // Different from parent
            'prevent_closure_ticket' => 0,
        ]);
        $this->assertGreaterThan(0, $child_config_id);

        // Test effective configuration for child entity
        $effective_config = Config::getEffectiveConfigForEntity($child_entity_id);

        // Should return child's own config
        $this->assertEquals($child_entity_id, $effective_config->fields['entities_id']);
        $this->assertEquals(0, $effective_config->fields['take_item_group_ticket']);
        $this->assertEquals(0, $effective_config->fields['prevent_closure_ticket']);
    }

    /**
     * Test getEffectiveConfig method uses current session entity
     */
    public function testGetEffectiveConfigUsesCurrentSession(): void
    {
        // Create test entity
        $test_entity = new \Entity();
        $test_entity_id = $test_entity->add([
            'name' => 'Session Test Entity',
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $test_entity_id);

        // Configure this entity
        $this->assertIsInt($test_entity_id);
        $test_conf = Config::getEffectiveConfigForEntity($test_entity_id);
        $test_config = new Config();
        $test_config_id = $test_config->update([
            'id' => $test_conf->getID(),
            'entities_id' => $test_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 0,
            'take_item_group_ticket' => 1,
        ]);
        $this->assertGreaterThan(0, $test_config_id);

        // Store current session entity
        $original_entity = $_SESSION['glpiactive_entity'];

        // Set session to our test entity
        $_SESSION['glpiactive_entity'] = $test_entity_id;

        // Test getEffectiveConfig (should use session entity)
        $effective_config = Config::getEffectiveConfig();
        $this->assertEquals($test_entity_id, $effective_config->fields['entities_id']);
        $this->assertEquals(1, $effective_config->fields['take_item_group_ticket']);

        // Restore original session entity
        $_SESSION['glpiactive_entity'] = $original_entity;
    }

    /**
     * Test that root entity (ID=0) cannot inherit from parent
     */
    public function testRootEntityCannotInheritFromParent(): void
    {
        // Get or create config for root entity
        $root_config = new Config();
        $root_config->getFromDBByCrit(['entities_id' => 0]);

        $should_delete = false; // Initialize variable
        if (!$root_config->getID()) {
            // Create root config if it doesn't exist
            $root_config_id = $root_config->add([
                'entities_id' => 0,
                'is_active' => 1,
                'use_parent_entity' => 1, // This should be ignored for root entity
                'take_item_group_ticket' => 1,
            ]);
            $this->assertGreaterThan(0, $root_config_id);
            $should_delete = true;
        } else {
            // Update existing root config
            $root_config->update([
                'id' => $root_config->getID(),
                'use_parent_entity' => 1, // This should be ignored
                'take_item_group_ticket' => 1,
            ]);
        }

        // Test effective configuration for root entity
        $effective_config = Config::getEffectiveConfigForEntity(0);

        // Should return root config itself (cannot inherit)
        $this->assertEquals(0, $effective_config->fields['entities_id']);
        $this->assertEquals(1, $effective_config->fields['take_item_group_ticket']);

        // Clean up only if we created the config
        if ($should_delete) {
            $root_config->delete(['id' => $root_config->getID()]);
        }
    }

    /**
     * Test that Controller methods use effective configuration with inheritance
     */
    public function testControllerUsesEffectiveConfigWithInheritance(): void
    {
        // Create parent entity
        $parent_entity = new \Entity();
        $parent_entity_id = $parent_entity->add([
            'name' => 'Controller Parent Entity Test',
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $parent_entity_id);

        // Create child entity
        $child_entity = new \Entity();
        $child_entity_id = $child_entity->add([
            'name' => 'Controller Child Entity Test',
            'entities_id' => $parent_entity_id,
        ]);
        $this->assertGreaterThan(0, $child_entity_id);

        // Configure parent entity with specific settings
        $this->assertIsInt($parent_entity_id);
        $parent_conf = Config::getEffectiveConfigForEntity($parent_entity_id);
        $parent_config = new Config();
        $parent_config_id = $parent_config->update([
            'id' => $parent_conf->getID(),
            'entities_id' => $parent_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 0,
            'mandatory_task_category' => 1, // Enable mandatory task category
            'mandatory_task_duration' => 1,
        ]);
        $this->assertGreaterThan(0, $parent_config_id);

        // Configure child entity to inherit from parent
        $this->assertIsInt($child_entity_id);
        $child_conf = Config::getEffectiveConfigForEntity($child_entity_id);
        $child_config = new Config();
        $child_config_id = $child_config->update([
            'id' => $child_conf->getID(),
            'entities_id' => $child_entity_id,
            'is_active' => 1,
            'use_parent_entity' => 1, // Inherit from parent
            'mandatory_task_category' => 0, // Should be ignored
            'mandatory_task_duration' => 0,
        ]);
        $this->assertGreaterThan(0, $child_config_id);

        // Store original session and set to child entity
        $original_entity = $_SESSION['glpiactive_entity'];
        $_SESSION['glpiactive_entity'] = $child_entity_id;

        // Create a task item to test
        $task = new \TicketTask();
        $task->input = [
            'content' => 'Test task content',
            'taskcategories_id' => '', // Empty category - should trigger error due to inheritance
            'actiontime' => '', // Empty duration - should trigger error
        ];

        // Test Controller::checkTaskRequirements with inherited config
        $result_task = \GlpiPlugin\Moreoptions\Controller::checkTaskRequirements($task);

        // Task input should be set to false due to mandatory fields from inherited config
        $this->assertFalse($result_task->input);

        // Now test with filled mandatory fields
        $task2 = new \TicketTask();
        $task2->input = [
            'content' => 'Test task content',
            'taskcategories_id' => 1, // Valid category
            'actiontime' => 3600, // Valid duration
            'users_id_tech' => 1,
            'groups_id_tech' => 1,
        ];

        $result_task2 = \GlpiPlugin\Moreoptions\Controller::checkTaskRequirements($task2);
        // Should not be false since mandatory fields are filled
        $this->assertNotFalse($result_task2->input);

        // Restore original session
        $_SESSION['glpiactive_entity'] = $original_entity;
    }
}
