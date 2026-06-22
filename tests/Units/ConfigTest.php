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
            'entities_id'             => 0,
            'mandatory_task_category' => 1,
            'mandatory_task_duration' => 1,
            'mandatory_task_user'     => 1,
            'mandatory_task_group'    => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        //Create a ticket
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'          => 'Test ticket task mandatory fields',
                'content'       => 'Test content',
            ],
        );

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
        $this->clearSessionMessages();

        // Create category
        $category = $this->createItem(
            \TaskCategory::class,
            [
                'name' => 'Test category',
            ],
        );

        //Create a task with mandatory fields (Expected to succeed)
        $task = $this->createItem(
            \TicketTask::class,
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
        $this->clearSessionMessages();

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
        $this->clearSessionMessages();

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
        $this->clearSessionMessages();

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
        $this->clearSessionMessages();

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
            'entities_id'                            => 0,
            'require_technician_to_close_ticket'    => 1,
            'require_technicians_group_to_close_ticket' => 1,
            'require_category_to_close_ticket'       => 1,
            'require_location_to_close_ticket'       => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        //Create a ticket without mandatory fields (Expected to succeed)
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'          => 'Test ticket close',
                'content'       => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        // Create group
        $group = $this->createItem(
            \Group::class,
            [
                'name' => 'Test group close ticket',
            ],
        );
        $gid = $group->getID();

        // Close the ticket without mandatory fields (Expected to fail)
        $ticket = new \Ticket();
        $result = $ticket->update(
            [
                'id'          => $tid,
                'status'      => \Ticket::CLOSED,
            ],
        );
        $this->assertFalse($result);
        $this->clearSessionMessages();

        // Create category
        $category = $this->createItem(
            \ITILCategory::class,
            [
                'name' => 'Test category close ticket',
            ],
        );
        $cid = $category->getID();

        // Create location
        $location = $this->createItem(
            \Location::class,
            [
                'name' => 'Test location close ticket',
            ],
        );
        $lid = $location->getID();

        // Add technician group to the ticket
        $this->createItem(
            \Group_Ticket::class,
            [
                'tickets_id' => $tid,
                'groups_id'  => $gid,
                'type'       => \Group_Ticket::ASSIGN,
            ],
        );

        // Add technician to the ticket
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'glpi',
            ],
        ));

        $this->createItem(
            \Ticket_User::class,
            [
                'tickets_id' => $tid,
                'users_id'   => $user->getID(),
                'type'       => \Ticket_User::ASSIGN,
            ],
        );

        // Close the ticket without location and category (Expected to fail)
        $ticket = new \Ticket();
        $this->assertFalse($ticket->update(
            [
                'id'                => $tid,
                'status'            => \Ticket::CLOSED,
            ],
        ));
        $this->clearSessionMessages();

        // Close the ticket with location and category (Expected to succeed)
        $this->updateItem(
            \Ticket::class,
            $tid,
            [
                'locations_id'     => $lid,
                'itilcategories_id' => $cid,
                'status'            => \Ticket::CLOSED,
            ],
        );

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
     * Test mandatory fields before adding a solution
     */
    public function testCannotAddSolutionWhenMissingMandatoryFields(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        // Configure mandatory fields before closing (which impacts solutions too)
        $result = $this->updateTestConfig($conf, [
            'entities_id'                        => 0,
            'require_technician_to_close_ticket' => 1,
            'require_category_to_close_ticket'   => 1,
        ]);
        $this->assertTrue($result);

        // Create a ticket without mandatory fields
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'    => 'Test ticket solution',
                'content' => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        // Attempt to add a solution (Expected to fail because missing tech and category)
        $solution = new \ITILSolution();
        $resultFields = $solution->add([
            'itemtype' => \Ticket::class,
            'items_id' => $tid,
            'content'  => 'My test solution',
            'status'   => \CommonITILObject::SOLVED,
        ]);

        $this->clearSessionMessages();
        $this->assertFalse($resultFields);

        // Add technician to the ticket
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(['name' => 'glpi']));

        $this->createItem(
            \Ticket_User::class,
            [
                'tickets_id' => $tid,
                'users_id'   => $user->getID(),
                'type'       => \Ticket_User::ASSIGN,
            ],
        );

        // Create category and update ticket
        $category = $this->createItem(
            \ITILCategory::class,
            [
                'name' => 'Test category for solution test',
            ],
        );
        $this->updateItem(
            \Ticket::class,
            $tid,
            [
                'itilcategories_id' => $category->getID(),
            ],
        );

        // Attempt to add solution with all mandatory fields present (Expected to succeed)
        $solution2 = new \ITILSolution();
        $resultOk = $solution2->add([
            'itemtype'   => \Ticket::class,
            'items_id'   => $tid,
            'solutiontypes_id' => 0,
            'content'    => 'My test solution with fields ok',
            'status'     => \CommonITILObject::SOLVED,
        ]);
        $this->assertIsInt($resultOk);
        $this->clearSessionMessages();

        // Reset config
        $resetResult = $this->updateTestConfig($conf, [
            'require_technician_to_close_ticket' => 0,
            'require_category_to_close_ticket'   => 0,
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
            'entities_id'                            => 0,
            'require_technician_to_close_change'    => 1,
            'require_technicians_group_to_close_change' => 1,
            'require_category_to_close_change'       => 1,
            'require_location_to_close_change'       => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        //Create a change without mandatory fields (Expected to succeed)
        $change = $this->createItem(
            \Change::class,
            [
                'name'          => 'Test change close',
                'content'       => 'Test content',
            ],
        );
        $cid = $change->getID();

        // Create group
        $group = $this->createItem(
            \Group::class,
            [
                'name' => 'Test group close change',
            ],
        );
        $gid = $group->getID();

        // Close the change without mandatory fields (Expected to fail)
        $change = new \Change();
        $result = $change->update(
            [
                'id'          => $cid,
                'status'      => \Change::CLOSED,
            ],
        );
        $this->assertFalse($result);
        $this->clearSessionMessages();

        // Create category
        $category = $this->createItem(
            \ITILCategory::class,
            [
                'name' => 'Test category close change',
            ],
        );
        $catid = $category->getID();

        // Create location
        $location = $this->createItem(
            \Location::class,
            [
                'name' => 'Test location close change',
            ],
        );
        $lid = $location->getID();

        // Add technician group to the change
        $this->createItem(
            \Change_Group::class,
            [
                'changes_id' => $cid,
                'groups_id'  => $gid,
                'type'       => \Change_Group::ASSIGN,
            ],
        );

        // Add technician to the change
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'glpi',
            ],
        ));

        $this->createItem(
            \Change_User::class,
            [
                'changes_id' => $cid,
                'users_id'   => $user->getID(),
                'type'       => \Change_User::ASSIGN,
            ],
        );

        // Close the change without location and category (Expected to fail)
        $change = new \Change();
        $result = $change->update(
            [
                'id'                => $cid,
                'status'            => \Change::CLOSED,
            ],
        );
        $this->assertFalse($result);
        $this->clearSessionMessages();

        // Close the change with location and category (Expected to succeed)
        $this->updateItem(
            \Change::class,
            $cid,
            [
                'locations_id'     => $lid,
                'itilcategories_id' => $catid,
                'status'            => \Change::CLOSED,
            ],
        );

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
            'entities_id'                            => 0,
            'require_technician_to_close_problem'    => 1,
            'require_technicians_group_to_close_problem' => 1,
            'require_category_to_close_problem'       => 1,
            'require_location_to_close_problem'       => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        //Create a problem without mandatory fields (Expected to succeed)
        $problem = $this->createItem(
            \Problem::class,
            [
                'name'          => 'Test problem close',
                'content'       => 'Test content',
            ],
        );
        $pid = $problem->getID();

        // Create group
        $group = $this->createItem(
            \Group::class,
            [
                'name' => 'Test group close problem',
            ],
        );
        $gid = $group->getID();

        // Close the problem without mandatory fields (Expected to fail)
        $problem = new \Problem();
        $result = $problem->update(
            [
                'id'          => $pid,
                'status'      => \Problem::CLOSED,
            ],
        );
        $this->assertFalse($result);
        $this->clearSessionMessages();

        // Create category
        $category = $this->createItem(
            \ITILCategory::class,
            [
                'name' => 'Test category close problem',
            ],
        );
        $catid = $category->getID();

        // Create location
        $location = $this->createItem(
            \Location::class,
            [
                'name' => 'Test location close problem',
            ],
        );
        $lid = $location->getID();

        // Add technician group to the problem
        $this->createItem(
            \Group_Problem::class,
            [
                'problems_id' => $pid,
                'groups_id'  => $gid,
                'type'       => \Group_Problem::ASSIGN,
            ],
        );

        // Add technician to the problem
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'glpi',
            ],
        ));

        $this->createItem(
            \Problem_User::class,
            [
                'problems_id' => $pid,
                'users_id'   => $user->getID(),
                'type'       => \Problem_User::ASSIGN,
            ],
        );

        // Close the problem without location and category (Expected to fail)
        $problem = new \Problem();
        $result = $problem->update(
            [
                'id'                => $pid,
                'status'            => \Problem::CLOSED,
            ],
        );
        $this->assertFalse($result);
        $this->clearSessionMessages();

        // Close the problem with location and category (Expected to succeed)
        $this->updateItem(
            \Problem::class,
            $pid,
            [
                'locations_id'     => $lid,
                'itilcategories_id' => $catid,
                'status'            => \Problem::CLOSED,
            ],
        );

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
            'entities_id'                 => 0,
            'take_requester_group_ticket' => 2, // All
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        // Create two groups
        $group1 = $this->createItem(
            \Group::class,
            [
                'name' => 'Test group 1',
            ],
        );

        $group2 = $this->createItem(
            \Group::class,
            [
                'name' => 'Test group 2',
            ],
        );

        // Get the user glpi
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'glpi',
            ],
        ));

        // Assign the user to the group
        $this->createItem(
            \Group_User::class,
            [
                'groups_id' => $group1->getID(),
                'users_id'  => $user->getID(),
            ],
        );

        $this->createItem(
            \Group_User::class,
            [
                'groups_id' => $group2->getID(),
                'users_id'  => $user->getID(),
            ],
        );

        //Create a ticket
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'          => 'Test ticket requester group',
                'content'       => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        $this->createItem(
            \Ticket_User::class,
            [
                'tickets_id' => $tid,
                'users_id'   => $user->getID(),
                'type'       => \Ticket_User::REQUESTER,
            ],
        );

        // Check if the group of the requester is in the actors
        $ticket_group = new \Group_Ticket();
        $groups = $ticket_group->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(2, $groups);

        $config = new Config();
        // Configurer pour ne prendre que le groupe principal du demandeur
        $result = $this->updateTestConfig($conf, [
            'entities_id'                 => 0,
            'take_requester_group_ticket' => 1, // Default
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        //Create a ticket
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'          => 'Test ticket requester group - 2',
                'content'       => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        //Add default group to the user
        $this->updateItem(
            \User::class,
            $user->getID(),
            [
                'groups_id' => $group1->getID(),
            ],
        );

        $user2 = new \User();
        $this->assertTrue($user2->getFromDB($user->getID()));

        $this->createItem(
            \Ticket_User::class,
            [
                'tickets_id' => $tid,
                'users_id'   => $user2->getID(),
                'type'       => \Ticket_User::REQUESTER,
            ],
        );

        // Check if the group of the requester is in the actors
        $ticket_group = new \Group_Ticket();
        $groups = $ticket_group->find(['tickets_id' => $tid]);
        $this->assertCount(1, $groups);

        // Reset config
        // Réinitialiser la configuration
        $resetResult = $this->updateTestConfig($conf, [
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
            'entities_id'                  => 0,
            'take_technician_group_ticket' => 2, // All
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        // Create two groups
        $group1 = $this->createItem(
            \Group::class,
            [
                'name' => 'Test group 1',
            ],
        );

        $group2 = $this->createItem(
            \Group::class,
            [
                'name' => 'Test group 2',
            ],
        );

        // Get the user tech
        $user = new \User();
        $this->assertTrue($user->getFromDBByCrit(
            [
                'name' => 'tech',
            ],
        ));

        // Assign the user to the group
        $this->createItem(
            \Group_User::class,
            [
                'groups_id' => $group1->getID(),
                'users_id'  => $user->getID(),
            ],
        );

        $this->createItem(
            \Group_User::class,
            [
                'groups_id' => $group2->getID(),
                'users_id'  => $user->getID(),
            ],
        );

        //Create a ticket
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'          => 'Test ticket',
                'content'       => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        $this->createItem(
            \Ticket_User::class,
            [
                'tickets_id' => $tid,
                'users_id'   => $user->getID(),
                'type'       => \Ticket_User::ASSIGN,
            ],
        );

        // Check if the group of the requester is in the actors
        $ticket_group = new \Group_Ticket();
        $groups = $ticket_group->find(['tickets_id' => $ticket->getID()]);
        $this->assertCount(2, $groups);

        // Setup to take only the main group of the technician
        $result = $this->updateTestConfig($conf, [
            'entities_id'                  => 0,
            'take_technician_group_ticket' => 1, // Default
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        //Create a ticket
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'          => 'Test ticket tech group - 2',
                'content'       => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        //Add default group to the user
        $this->updateItem(
            \User::class,
            $user->getID(),
            [
                'groups_id' => $group1->getID(),
            ],
        );

        $user2 = new \User();
        $this->assertTrue($user2->getFromDB($user->getID()));

        $this->createItem(
            \Ticket_User::class,
            [
                'tickets_id' => $tid,
                'users_id'   => $user2->getID(),
                'type'       => \Ticket_User::ASSIGN,
            ],
        );

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
            'entities_id'            => 0,
            'take_item_group_ticket' => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getConfig();

        // Create two groups
        $group1 = $this->createItem(
            \Group::class,
            [
                'name' => 'Test group 1',
            ],
        );

        //Create item computer
        $computer = $this->createItem(
            \Computer::class,
            [
                'name' => 'Test computer',
                'entities_id' => 0,
            ],
        );
        $cid = $computer->getID();

        //Create item ticket
        $this->createItem(
            \Group_Item::class,
            [
                'items_id'   => $computer->getID(),
                'itemtype'   => \Computer::class,
                'groups_id'  => $group1->getID(),
                'type'       => 1,
            ],
        );

        //Create a ticket
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'          => 'Test ticket item groups',
                'content'       => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        // Assign the computer to the ticket
        $this->createItem(
            \Item_Ticket::class,
            [
                'tickets_id' => $tid,
                'items_id'   => $computer->getID(),
                'itemtype'   => \Computer::class,
            ],
        );

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
            'entities_id' => 0,
            'assign_technical_manager_when_changing_category_ticket' => 1,
            'assign_technical_group_when_changing_category_ticket' => 1,
        ]);
        $this->assertTrue($result);

        // Create a group for the category
        $group = $this->createItem(
            \Group::class,
            [
                'name' => 'Test Technical Group',
            ],
        );
        $gid = $group->getID();

        // Create a user for technical manager
        $user = $this->createItem(
            \User::class,
            [
                'name' => 'test_tech_manager',
                'login' => 'test_tech_manager',
            ],
            ['login'],
        );
        $uid = $user->getID();

        // Create a category with technical manager and group
        $category = $this->createItem(
            \ITILCategory::class,
            [
                'name' => 'Test Category with Tech',
                'users_id' => $uid,
                'groups_id' => $gid,
            ],
        );
        $cid = $category->getID();

        // Create a ticket
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name' => 'Test ticket category update',
                'content' => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        // Update ticket with the category
        $this->updateItem(
            \Ticket::class,
            $tid,
            [
                'itilcategories_id' => $cid,
            ],
        );

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
            'entities_id' => 0,
            'assign_technical_manager_when_changing_category_change' => 1,
            'assign_technical_group_when_changing_category_change' => 1,
        ]);
        $this->assertTrue($result);

        // Create a group for the category
        $group = $this->createItem(
            \Group::class,
            [
                'name' => 'Test Technical Group Change',
            ],
        );
        $gid = $group->getID();

        // Create a user for technical manager
        $user = $this->createItem(
            \User::class,
            [
                'name' => 'test_tech_manager_change',
                'login' => 'test_tech_manager_change',
            ],
            ['login'],
        );
        $uid = $user->getID();

        // Create a category with technical manager and group
        $category = $this->createItem(
            \ITILCategory::class,
            [
                'name' => 'Test Category with Tech Change',
                'users_id' => $uid,
                'groups_id' => $gid,
            ],
        );
        $cid = $category->getID();

        // Create a change
        $change = $this->createItem(
            \Change::class,
            [
                'name' => 'Test change category update',
                'content' => 'Test content',
            ],
        );
        $chid = $change->getID();

        // Update change with the category
        $this->updateItem(
            \Change::class,
            $chid,
            [
                'itilcategories_id' => $cid,
            ],
        );

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
            'entities_id' => 0,
            'assign_technical_manager_when_changing_category_problem' => 1,
            'assign_technical_group_when_changing_category_problem' => 1,
        ]);
        $this->assertTrue($result);

        // Create a group for the category
        $group = $this->createItem(
            \Group::class,
            [
                'name' => 'Test Technical Group Problem',
            ],
        );
        $gid = $group->getID();

        // Create a user for technical manager
        $user = $this->createItem(
            \User::class,
            [
                'name' => 'test_tech_manager_problem',
                'login' => 'test_tech_manager_problem',
            ],
            ['login'],
        );
        $uid = $user->getID();

        // Create a category with technical manager and group
        $category = $this->createItem(
            \ITILCategory::class,
            [
                'name' => 'Test Category with Tech Problem',
                'users_id' => $uid,
                'groups_id' => $gid,
            ],
        );
        $cid = $category->getID();

        // Create a problem
        $problem = $this->createItem(
            \Problem::class,
            [
                'name' => 'Test problem category update',
                'content' => 'Test content',
            ],
        );
        $pid = $problem->getID();

        // Update problem with the category
        $this->updateItem(
            \Problem::class,
            $pid,
            [
                'itilcategories_id' => $cid,
            ],
        );

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
            'entities_id' => 0,
            'assign_technical_manager_when_changing_category_ticket' => 0,
            'assign_technical_group_when_changing_category_ticket' => 0,
        ]);
        $this->assertTrue($result);

        // Create a category with technical manager and group
        $group = $this->createItem(
            \Group::class,
            [
                'name' => 'Test Group Disabled',
            ],
        );
        $gid = $group->getID();

        $user = $this->createItem(
            \User::class,
            [
                'name' => 'test_user_disabled',
                'login' => 'test_user_disabled',
            ],
            ['login'],
        );
        $uid = $user->getID();

        $category = $this->createItem(
            \ITILCategory::class,
            [
                'name' => 'Test Category Disabled',
                'users_id' => $uid,
                'groups_id' => $gid,
            ],
        );
        $cid = $category->getID();

        // Create and update a ticket
        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name' => 'Test disabled config',
                'content' => 'Test content',
            ],
        );
        $tid = $ticket->getID();

        $this->updateItem(
            \Ticket::class,
            $tid,
            [
                'itilcategories_id' => $cid,
            ],
        );

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
        $this->login();

        // Create child entity under root (entities_id=0)
        $child_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Child Entity Test',
                'entities_id' => 0,
            ],
            ['name'],
        );
        $child_entity_id = $child_entity->getID();
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Configure root entity with specific settings
        $conf = Config::getConfig(0, false);
        $this->updateItem(
            Config::class,
            $conf->getID(),
            [
                'take_item_group_ticket' => 1,
                'prevent_closure_ticket' => 1,
                'require_technician_to_close_ticket' => 1,
                'mandatory_task_category' => 1,
            ],
        );

        // Configure child entity to inherit from parent (CONFIG_PARENT)
        $this->assertIsInt($child_entity_id);
        $child_conf = Config::getConfig($child_entity_id, false);
        $this->updateItem(
            Config::class,
            $child_conf->getID(),
            [
                'take_item_group_ticket' => Config::CONFIG_PARENT,
                'prevent_closure_ticket' => Config::CONFIG_PARENT,
                'require_technician_to_close_ticket' => Config::CONFIG_PARENT,
                'mandatory_task_category' => Config::CONFIG_PARENT,
            ],
        );

        // Test effective configuration for child entity — should inherit root values
        $effective_config = Config::getConfig($child_entity_id, true);

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
        $this->login();
        // Create grandparent entity (level 1)
        $grandparent_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Grandparent Entity Test',
                'entities_id' => 0, // Root entity as parent
            ],
            ['name'],
        );
        $grandparent_entity_id = $grandparent_entity->getID();
        $this->assertIsInt($grandparent_entity_id);
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Create parent entity (level 2)
        $parent_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Parent Entity Test Level 2',
                'entities_id' => $grandparent_entity_id,
            ],
            ['name', 'entities_id'],
        );
        $parent_entity_id = $parent_entity->getID();
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Create child entity (level 3)
        $child_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Child Entity Test Level 3',
                'entities_id' => $parent_entity_id,
            ],
            ['name', 'entities_id'],
        );
        $child_entity_id = $child_entity->getID();
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Configure grandparent entity with specific settings
        $grandparent_conf = Config::getConfig($grandparent_entity_id, false);
        $this->updateItem(
            Config::class,
            $grandparent_conf->getID(),
            [
                'entities_id' => $grandparent_entity_id,
                'take_item_group_ticket' => 1,
                'prevent_closure_ticket' => 1,
                'require_technician_to_close_ticket' => 1,
            ],
        );

        // Configure parent entity to inherit from grandparent
        $this->assertIsInt($parent_entity_id);
        $parent_conf = Config::getConfig($parent_entity_id, false);
        $this->updateItem(
            Config::class,
            $parent_conf->getID(),
            [
                'entities_id' => $parent_entity_id,
                'take_item_group_ticket' => Config::CONFIG_PARENT,
                'prevent_closure_ticket' => Config::CONFIG_PARENT,
                'require_technician_to_close_ticket' => Config::CONFIG_PARENT,
            ],
        );

        // Configure child entity to inherit from parent (which itself inherits from grandparent)
        $this->assertIsInt($child_entity_id);
        $child_conf = Config::getConfig($child_entity_id, false);
        $this->updateItem(
            Config::class,
            $child_conf->getID(),
            [
                'entities_id' => $child_entity_id,
                'take_item_group_ticket' => Config::CONFIG_PARENT,
                'prevent_closure_ticket' => Config::CONFIG_PARENT,
                'require_technician_to_close_ticket' => Config::CONFIG_PARENT,
            ],
        );

        // Child should cascade through parent to resolve grandparent's values
        $effective_config = Config::getConfig($child_entity_id, true);

        $this->assertEquals(1, $effective_config->fields['take_item_group_ticket']);
        $this->assertEquals(1, $effective_config->fields['prevent_closure_ticket']);
        $this->assertEquals(1, $effective_config->fields['require_technician_to_close_ticket']);
    }

    /**
     * Test that child entity without inheritance uses its own config
     */
    public function testChildEntityWithoutInheritanceUsesOwnConfig(): void
    {
        $this->initEntitySession();
        // Create parent entity
        $parent_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Parent Entity No Inherit Test',
                'entities_id' => 0,
            ],
            ['name'],
        );
        $parent_entity_id = $parent_entity->getID();
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Create child entity
        $child_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Child Entity No Inherit Test',
                'entities_id' => $parent_entity_id,
            ],
            ['name', 'entities_id'],
        );
        $child_entity_id = $child_entity->getID();
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Configure parent entity
        $this->assertIsInt($parent_entity_id);
        $parent_conf = Config::getConfig($parent_entity_id, false);
        $this->updateItem(
            Config::class,
            $parent_conf->getID(),
            [
                'entities_id' => $parent_entity_id,
                'take_item_group_ticket' => 1,
                'prevent_closure_ticket' => 1,
            ],
        );

        // Configure child entity WITHOUT inheritance
        $this->assertIsInt($child_entity_id);
        $child_conf = Config::getConfig($child_entity_id, false);
        $this->updateItem(
            Config::class,
            $child_conf->getID(),
            [
                'entities_id' => $child_entity_id,
                'take_item_group_ticket' => 0, // Different from parent
                'prevent_closure_ticket' => 0,
            ],
        );

        // Test effective configuration for child entity
        $effective_config = Config::getConfig($child_entity_id, true);

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
        $this->initEntitySession();
        // Create test entity
        $test_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Session Test Entity',
                'entities_id' => 0,
            ],
            ['name'],
        );
        $test_entity_id = $test_entity->getID();
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Configure this entity
        $this->assertIsInt($test_entity_id);
        $test_conf = Config::getConfig($test_entity_id, false);
        $this->updateItem(
            Config::class,
            $test_conf->getID(),
            [
                'entities_id' => $test_entity_id,
                'take_item_group_ticket' => 1,
            ],
        );

        // Store current session entity
        $original_entity = $_SESSION['glpiactive_entity'];

        // Set session to our test entity
        $_SESSION['glpiactive_entity'] = $test_entity_id;

        // Test getEffectiveConfig (should use session entity)
        $effective_config = Config::getConfig();
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
            $root_config = $this->createItem(
                Config::class,
                [
                    'entities_id' => 0,
                    'take_item_group_ticket' => 1,
                ],
            );
            $should_delete = true;
        } else {
            // Update existing root config
            $this->updateItem(
                Config::class,
                $root_config->getID(),
                [
                    'take_item_group_ticket' => 1,
                ],
            );
        }

        // Test effective configuration for root entity
        $effective_config = Config::getConfig(0, true);

        // Should return root config itself (cannot inherit)
        $this->assertEquals(0, $effective_config->fields['entities_id']);
        $this->assertEquals(1, $effective_config->fields['take_item_group_ticket']);

        // Clean up only if we created the config
        if ($should_delete) {
            $this->deleteItem(Config::class, $root_config->getID());
        }
    }

    /**
     * Test that Controller methods use effective configuration with inheritance
     */
    public function testControllerUsesEffectiveConfigWithInheritance(): void
    {
        $this->initEntitySession();
        // Create parent entity
        $parent_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Controller Parent Entity Test',
                'entities_id' => 0,
            ],
            ['name'],
        );
        $parent_entity_id = $parent_entity->getID();
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Create child entity
        $child_entity = $this->createItem(
            \Entity::class,
            [
                'name' => 'Controller Child Entity Test',
                'entities_id' => $parent_entity_id,
            ],
            ['name', 'entities_id'],
        );
        $child_entity_id = $child_entity->getID();
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Configure parent entity with specific settings
        $this->assertIsInt($parent_entity_id);
        $parent_conf = Config::getConfig($parent_entity_id, false);
        $this->updateItem(
            Config::class,
            $parent_conf->getID(),
            [
                'entities_id' => $parent_entity_id,
                'mandatory_task_category' => 1, // Enable mandatory task category
                'mandatory_task_duration' => 1,
            ],
        );

        // Configure child entity to inherit from parent
        $this->assertIsInt($child_entity_id);
        $child_conf = Config::getConfig($child_entity_id, false);
        $this->updateItem(
            Config::class,
            $child_conf->getID(),
            [
                'entities_id' => $child_entity_id,
                'mandatory_task_category' => 0, // Should be ignored
                'mandatory_task_duration' => 0,
            ],
        );

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

    public function testAssignTechnicianFromTask(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();

        $result = $this->updateTestConfig($conf, [
            'entities_id' => 0,
        ]);
        $this->assertTrue($result);

        $tech = $this->createItem(
            \User::class,
            [
                'name'         => 'tech_from_task',
                'password'     => 'tech_from_task',
                'password2'    => 'tech_from_task',
                '_profiles_id' => 4,
            ],
            ['password', 'password2'],
        );
        $tech_id = $tech->getID();

        $ticket = $this->createItem(
            \Ticket::class,
            [
                'name'    => 'Test ticket for task assignment',
                'content' => 'Test content',
            ],
        );
        $ticket_id = $ticket->getID();

        $ticket_user = new \Ticket_User();
        $assigned_users_before = $ticket_user->find([
            'tickets_id' => $ticket_id,
            'users_id'   => $tech_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(0, $assigned_users_before);

        $task = $this->createItem(
            \TicketTask::class,
            [
                'tickets_id'    => $ticket_id,
                'content'       => 'Test task',
                'users_id_tech' => $tech_id,
                'actiontime'    => 3600,
                'state'         => \Planning::TODO,
            ],
        );
        $task_id = $task->getID();

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
            'entities_id' => 0,
        ]);
        $this->assertTrue($result);

        $tech = $this->createItem(
            \User::class,
            [
                'name'         => 'tech_from_change_task',
                'password'     => 'tech_from_change_task',
                'password2'    => 'tech_from_change_task',
                '_profiles_id' => 4,
            ],
            ['password', 'password2'],
        );
        $tech_id = $tech->getID();

        $change = $this->createItem(
            \Change::class,
            [
                'name'    => 'Test change for task assignment',
                'content' => 'Test content',
            ],
        );
        $change_id = $change->getID();

        $change_user = new \Change_User();
        $assigned_users_before = $change_user->find([
            'changes_id' => $change_id,
            'users_id'   => $tech_id,
            'type'       => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(0, $assigned_users_before);

        $task = $this->createItem(
            \ChangeTask::class,
            [
                'changes_id'    => $change_id,
                'content'       => 'Test change task',
                'users_id_tech' => $tech_id,
                'actiontime'    => 3600,
                'state'         => \Planning::TODO,
            ],
        );
        $task_id = $task->getID();

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
            'entities_id' => 0,
        ]);
        $this->assertTrue($result);

        $tech = $this->createItem(
            \User::class,
            [
                'name'         => 'tech_from_problem_task',
                'password'     => 'tech_from_problem_task',
                'password2'    => 'tech_from_problem_task',
                '_profiles_id' => 4,
            ],
            ['password', 'password2'],
        );
        $tech_id = $tech->getID();

        $problem = $this->createItem(
            \Problem::class,
            [
                'name'    => 'Test problem for task assignment',
                'content' => 'Test content',
            ],
        );
        $problem_id = $problem->getID();

        $problem_user = new \Problem_User();
        $assigned_users_before = $problem_user->find([
            'problems_id' => $problem_id,
            'users_id'    => $tech_id,
            'type'        => \CommonITILActor::ASSIGN,
        ]);
        $this->assertCount(0, $assigned_users_before);

        $task = $this->createItem(
            \ProblemTask::class,
            [
                'problems_id'   => $problem_id,
                'content'       => 'Test problem task',
                'users_id_tech' => $tech_id,
                'actiontime'    => 3600,
                'state'         => \Planning::TODO,
            ],
        );
        $task_id = $task->getID();

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

    /**
     * Test that CONFIG_PARENT values are resolved through multiple entity levels.
     *
     * Hierarchy: Root(0) → A → B → C
     *
     * Root : take_item_group_ticket=1,  take_requester_group_ticket=2
     * A    : take_item_group_ticket=CONFIG_PARENT, take_requester_group_ticket=1 (own)
     * B    : take_item_group_ticket=0 (own),       take_requester_group_ticket=CONFIG_PARENT
     * C    : take_item_group_ticket=CONFIG_PARENT, take_requester_group_ticket=CONFIG_PARENT
     *
     * Expected resolved values:
     * A effective: take_item_group_ticket=1 (from root), take_requester_group_ticket=1 (own)
     * B effective: take_item_group_ticket=0 (own),       take_requester_group_ticket=1 (from A)
     * C effective: take_item_group_ticket=0 (from B),    take_requester_group_ticket=1 (from B→A)
     */
    public function testMultiLevelInheritanceResolvesConfigParentThroughChain(): void
    {
        $this->login();

        $entity_a = $this->createItem(
            \Entity::class,
            ['name' => 'Inheritance Test A', 'entities_id' => 0],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $entity_b = $this->createItem(
            \Entity::class,
            ['name' => 'Inheritance Test B', 'entities_id' => $entity_a->getID()],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $entity_c = $this->createItem(
            \Entity::class,
            ['name' => 'Inheritance Test C', 'entities_id' => $entity_b->getID()],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        // Root: explicit values
        $root_conf = Config::getConfig(0, false);
        $this->updateItem(Config::class, $root_conf->getID(), [
            'take_item_group_ticket'      => 1,
            'take_requester_group_ticket' => 2,
        ]);

        // A: inherit take_item_group_ticket from root, own take_requester_group_ticket=1
        $conf_a = Config::getConfig($entity_a->getID(), false);
        $this->updateItem(Config::class, $conf_a->getID(), [
            'take_item_group_ticket'      => Config::CONFIG_PARENT,
            'take_requester_group_ticket' => 1,
        ]);

        // B: own take_item_group_ticket=0, inherit take_requester_group_ticket from A
        $conf_b = Config::getConfig($entity_b->getID(), false);
        $this->updateItem(Config::class, $conf_b->getID(), [
            'take_item_group_ticket'      => 0,
            'take_requester_group_ticket' => Config::CONFIG_PARENT,
        ]);

        // C: inherit everything
        $conf_c = Config::getConfig($entity_c->getID(), false);
        $this->updateItem(Config::class, $conf_c->getID(), [
            'take_item_group_ticket'      => Config::CONFIG_PARENT,
            'take_requester_group_ticket' => Config::CONFIG_PARENT,
        ]);

        // Entity A: CONFIG_PARENT resolves to root's value
        $resolved_a = Config::getConfig($entity_a->getID());
        $this->assertEquals(1, $resolved_a->fields['take_item_group_ticket'], 'A should inherit take_item_group_ticket=1 from root');
        $this->assertEquals(1, $resolved_a->fields['take_requester_group_ticket'], 'A should keep its own take_requester_group_ticket=1');

        // Entity B: own value wins over parent, CONFIG_PARENT resolves through A
        $resolved_b = Config::getConfig($entity_b->getID());
        $this->assertEquals(0, $resolved_b->fields['take_item_group_ticket'], 'B should keep its own take_item_group_ticket=0');
        $this->assertEquals(1, $resolved_b->fields['take_requester_group_ticket'], 'B should inherit take_requester_group_ticket=1 from A');

        // Entity C: CONFIG_PARENT resolves to B's effective values (not root's)
        $resolved_c = Config::getConfig($entity_c->getID());
        $this->assertEquals(0, $resolved_c->fields['take_item_group_ticket'], 'C should inherit take_item_group_ticket=0 from B (not root=1)');
        $this->assertEquals(1, $resolved_c->fields['take_requester_group_ticket'], 'C should inherit take_requester_group_ticket=1 through B→A');
    }

    /**
     * Test that a child entity resolves grandparent values through a full CONFIG_PARENT chain.
     *
     * Hierarchy: Grandparent (explicit) → Parent (CONFIG_PARENT) → Child (CONFIG_PARENT)
     * Expected: child gets grandparent's values, not CONFIG_PARENT sentinel (-2).
     */
    public function testGrandparentToParentToChildAllInherit(): void
    {
        $this->login();

        $grandparent = $this->createItem(
            \Entity::class,
            ['name' => 'GP2P2C Grandparent', 'entities_id' => 0],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $parent = $this->createItem(
            \Entity::class,
            ['name' => 'GP2P2C Parent', 'entities_id' => $grandparent->getID()],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $child = $this->createItem(
            \Entity::class,
            ['name' => 'GP2P2C Child', 'entities_id' => $parent->getID()],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $gp_conf = Config::getConfig($grandparent->getID(), false);
        $this->updateItem(Config::class, $gp_conf->getID(), [
            'take_item_group_ticket' => 1,
            'prevent_closure_ticket' => 1,
            'mandatory_task_category' => 1,
        ]);

        $parent_conf = Config::getConfig($parent->getID(), false);
        $this->updateItem(Config::class, $parent_conf->getID(), [
            'take_item_group_ticket' => Config::CONFIG_PARENT,
            'prevent_closure_ticket' => Config::CONFIG_PARENT,
            'mandatory_task_category' => Config::CONFIG_PARENT,
        ]);

        $child_conf = Config::getConfig($child->getID(), false);
        $this->updateItem(Config::class, $child_conf->getID(), [
            'take_item_group_ticket' => Config::CONFIG_PARENT,
            'prevent_closure_ticket' => Config::CONFIG_PARENT,
            'mandatory_task_category' => Config::CONFIG_PARENT,
        ]);

        $resolved = Config::getConfig($child->getID());

        $this->assertEquals(
            1,
            $resolved->fields['take_item_group_ticket'],
            'Child should resolve to grandparent value (1) through the full CONFIG_PARENT chain',
        );
        $this->assertEquals(
            1,
            $resolved->fields['prevent_closure_ticket'],
            'Child should resolve to grandparent value (1) through the full CONFIG_PARENT chain',
        );
        $this->assertEquals(
            1,
            $resolved->fields['mandatory_task_category'],
            'Child should resolve to grandparent value (1) through the full CONFIG_PARENT chain',
        );
    }

    /**
     * Test that a child using CONFIG_PARENT gets the parent's own value, not the grandparent's,
     * when the parent has an explicit override.
     *
     * Hierarchy: Grandparent (value=1) → Parent (own value=2) → Child (CONFIG_PARENT)
     * Expected: child gets 2 (parent's override), not 1 (grandparent's value).
     */
    public function testChildInheritsParentOverrideNotGrandparent(): void
    {
        $this->login();

        $grandparent = $this->createItem(
            \Entity::class,
            ['name' => 'Override GP', 'entities_id' => 0],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $parent = $this->createItem(
            \Entity::class,
            ['name' => 'Override Parent', 'entities_id' => $grandparent->getID()],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $child = $this->createItem(
            \Entity::class,
            ['name' => 'Override Child', 'entities_id' => $parent->getID()],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $gp_conf = Config::getConfig($grandparent->getID(), false);
        $this->updateItem(Config::class, $gp_conf->getID(), [
            'take_item_group_ticket' => 1,
            'take_requester_group_ticket' => 1,
        ]);

        // Parent overrides with different explicit values
        $parent_conf = Config::getConfig($parent->getID(), false);
        $this->updateItem(Config::class, $parent_conf->getID(), [
            'take_item_group_ticket' => 2,
            'take_requester_group_ticket' => 0,
        ]);

        $child_conf = Config::getConfig($child->getID(), false);
        $this->updateItem(Config::class, $child_conf->getID(), [
            'take_item_group_ticket' => Config::CONFIG_PARENT,
            'take_requester_group_ticket' => Config::CONFIG_PARENT,
        ]);

        $resolved = Config::getConfig($child->getID());

        $this->assertEquals(
            2,
            $resolved->fields['take_item_group_ticket'],
            'Child should inherit parent override (2), not grandparent value (1)',
        );
        $this->assertEquals(
            0,
            $resolved->fields['take_requester_group_ticket'],
            'Child should inherit parent override (0), not grandparent value (1)',
        );
    }

    /**
     * Test that different fields in the same entity can be resolved at different levels
     * of a three-level hierarchy.
     *
     * Hierarchy: Grandparent → Parent → Child
     * - take_item_group_ticket:      GP=1, Parent=CONFIG_PARENT, Child=CONFIG_PARENT → child gets 1
     * - take_requester_group_ticket: GP=2, Parent=0 (own),        Child=CONFIG_PARENT → child gets 0
     * - prevent_closure_ticket:      GP=1, Parent=1 (explicit),   Child=0 (own)       → child keeps 0
     */
    public function testMixedFieldInheritanceThroughThreeLevels(): void
    {
        $this->login();

        $grandparent = $this->createItem(
            \Entity::class,
            ['name' => 'Mixed GP', 'entities_id' => 0],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $parent = $this->createItem(
            \Entity::class,
            ['name' => 'Mixed Parent', 'entities_id' => $grandparent->getID()],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $child = $this->createItem(
            \Entity::class,
            ['name' => 'Mixed Child', 'entities_id' => $parent->getID()],
            ['name'],
        );
        $this->clearLogEntriesContaining('glpiactiveentities_string');

        $gp_conf = Config::getConfig($grandparent->getID(), false);
        $this->updateItem(Config::class, $gp_conf->getID(), [
            'take_item_group_ticket'      => 1,
            'take_requester_group_ticket' => 2,
            'prevent_closure_ticket'      => 1,
        ]);

        // Parent inherits field_a, overrides field_b, sets field_c explicitly
        $parent_conf = Config::getConfig($parent->getID(), false);
        $this->updateItem(Config::class, $parent_conf->getID(), [
            'take_item_group_ticket'      => Config::CONFIG_PARENT, // inherits GP's 1
            'take_requester_group_ticket' => 0,                    // own override of GP's 2
            'prevent_closure_ticket'      => 1,                    // same as GP but explicit
        ]);

        // Child inherits field_a and field_b from parent, owns field_c
        $child_conf = Config::getConfig($child->getID(), false);
        $this->updateItem(Config::class, $child_conf->getID(), [
            'take_item_group_ticket'      => Config::CONFIG_PARENT, // cascades to GP's 1
            'take_requester_group_ticket' => Config::CONFIG_PARENT, // parent's override: 0
            'prevent_closure_ticket'      => 0,                    // own explicit value
        ]);

        $resolved = Config::getConfig($child->getID());

        $this->assertEquals(
            1,
            $resolved->fields['take_item_group_ticket'],
            'Child should resolve to 1 from grandparent (parent also inherits this field)',
        );
        $this->assertEquals(
            0,
            $resolved->fields['take_requester_group_ticket'],
            'Child should get parent override (0), not grandparent value (2)',
        );
        $this->assertEquals(
            0,
            $resolved->fields['prevent_closure_ticket'],
            'Child should keep its own explicit value (0), ignoring parent and grandparent',
        );
    }
}
