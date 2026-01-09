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

    public function testAssignTechnicianFromTask(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        $result = $this->updateTestConfig($conf, [
            'is_active'   => 1,
            'entities_id' => 0,
        ]);
        $this->assertTrue($result);

        $tech = new \User();
        $tech_id = $tech->add([
            'name'         => 'tech_from_task',
            'password'     => 'tech_from_task',
            'password2'    => 'tech_from_task',
            '_profiles_id' => 4,
        ]);
        $this->assertGreaterThan(0, $tech_id);

        $ticket = new \Ticket();
        $ticket_id = $ticket->add([
            'name'    => 'Test ticket for task assignment',
            'content' => 'Test content',
        ]);
        $this->assertGreaterThan(0, $ticket_id);

        $ticket_user = new \Ticket_User();
        $assigned_users_before = $ticket_user->find([
            'tickets_id' => $ticket_id,
            'users_id'   => $tech_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(0, $assigned_users_before);

        $task = new \TicketTask();
        $task_id = $task->add([
            'tickets_id'    => $ticket_id,
            'content'       => 'Test task',
            'users_id_tech' => $tech_id,
            'actiontime'    => 3600,
            'state'         => \Planning::TODO,
        ]);
        $this->assertGreaterThan(0, $task_id);

        $assigned_users_after = $ticket_user->find([
            'tickets_id' => $ticket_id,
            'users_id'   => $tech_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]);

        $this->assertCount(1, $assigned_users_after);
        $assignedUser = reset($assigned_users_after);
        $this->assertEquals($tech_id, $assignedUser['users_id']);
        $this->assertEquals(\CommonITILActor::ASSIGN, $assignedUser['type']);
    }

    public function testAssignTechnicianFromChangeTask(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        $result = $this->updateTestConfig($conf, [
            'is_active'   => 1,
            'entities_id' => 0,
        ]);
        $this->assertTrue($result);

        $tech = new \User();
        $tech_id = $tech->add([
            'name'         => 'tech_from_change_task',
            'password'     => 'tech_from_change_task',
            'password2'    => 'tech_from_change_task',
            '_profiles_id' => 4,
        ]);
        $this->assertGreaterThan(0, $tech_id);

        $change = new \Change();
        $change_id = $change->add([
            'name'    => 'Test change for task assignment',
            'content' => 'Test content',
        ]);
        $this->assertGreaterThan(0, $change_id);

        $change_user = new \Change_User();
        $assigned_users_before = $change_user->find([
            'changes_id' => $change_id,
            'users_id'   => $tech_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(0, $assigned_users_before);

        $task = new \ChangeTask();
        $task_id = $task->add([
            'changes_id'    => $change_id,
            'content'       => 'Test change task',
            'users_id_tech' => $tech_id,
            'actiontime'    => 3600,
            'state'         => \Planning::TODO,
        ]);
        $this->assertGreaterThan(0, $task_id);

        $assigned_users_after = $change_user->find([
            'changes_id' => $change_id,
            'users_id'   => $tech_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]);

        $this->assertCount(1, $assigned_users_after);
        $assignedUser = reset($assigned_users_after);
        $this->assertEquals($tech_id, $assignedUser['users_id']);
        $this->assertEquals(\CommonITILActor::ASSIGN, $assignedUser['type']);
    }

    public function testAssignTechnicianFromProblemTask(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        $result = $this->updateTestConfig($conf, [
            'is_active'   => 1,
            'entities_id' => 0,
        ]);
        $this->assertTrue($result);

        $tech = new \User();
        $tech_id = $tech->add([
            'name'         => 'tech_from_problem_task',
            'password'     => 'tech_from_problem_task',
            'password2'    => 'tech_from_problem_task',
            '_profiles_id' => 4,
        ]);
        $this->assertGreaterThan(0, $tech_id);

        $problem = new \Problem();
        $problem_id = $problem->add([
            'name'    => 'Test problem for task assignment',
            'content' => 'Test content',
        ]);
        $this->assertGreaterThan(0, $problem_id);

        $problem_user = new \Problem_User();
        $assigned_users_before = $problem_user->find([
            'problems_id' => $problem_id,
            'users_id'    => $tech_id,
            'type'        => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(0, $assigned_users_before);

        $task = new \ProblemTask();
        $task_id = $task->add([
            'problems_id'   => $problem_id,
            'content'       => 'Test problem task',
            'users_id_tech' => $tech_id,
            'actiontime'    => 3600,
            'state'         => \Planning::TODO,
        ]);
        $this->assertGreaterThan(0, $task_id);

        $assigned_users_after = $problem_user->find([
            'problems_id' => $problem_id,
            'users_id'    => $tech_id,
            'type'        => \CommonITILActor::ASSIGN,
        ]);

        $this->assertCount(1, $assigned_users_after);
        $assignedUser = reset($assigned_users_after);
        $this->assertEquals($tech_id, $assignedUser['users_id']);
        $this->assertEquals(\CommonITILActor::ASSIGN, $assignedUser['type']);
    }
}
