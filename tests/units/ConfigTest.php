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
 * @link      https://gitlab.teclib.com/glpi-network/cancelsend/
 * @link      https://gitlab.teclib.com/glpi-network/cloudinventory/
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
        $this->assertNotNull($conf);

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
        $this->assertNotNull($conf);

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
    public function testTicketMandatoryFieldsBeforeClose(): void
    {
        $this->login();

        $conf = $this->getCurrentConfig();
        $this->assertNotNull($conf);

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
        $this->assertNotNull($conf);

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
     * Test take the requester group
     */
    public function testTakeTheRequesterGroup(): void
    {
        $conf = $this->getCurrentConfig();
        $this->assertNotNull($conf);

        // Configure to take all groups of the requester
        $result = $this->updateTestConfig($conf, [
            'is_active'                   => 1,
            'entities_id'                 => 0,
            'take_requester_group_ticket' => 2, // All
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();
        $this->assertNotNull($conf);

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
        $this->assertNotNull($conf);

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
     * Test prendre le groupe du technicien
     */
    public function testTakeTheTechnicianGroup(): void
    {
        $conf = $this->getCurrentConfig();
        $this->assertNotNull($conf);

        // Configurer pour prendre tous les groupes du technicien
        $result = $this->updateTestConfig($conf, [
            'is_active'                       => 1,
            'entities_id'                  => 0,
            'take_technician_group_ticket' => 2, // All
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();
        $this->assertNotNull($conf);

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

        // Configurer pour ne prendre que le groupe principal du technicien
        $result = $this->updateTestConfig($conf, [
            'is_active'                    => 1,
            'entities_id'                  => 0,
            'take_technician_group_ticket' => 1, // Default
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();
        $this->assertNotNull($conf);

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
     * Test prendre les groupes des éléments
     */
    public function testTakeItemGroups(): void
    {
        $conf = $this->getCurrentConfig();
        $this->assertNotNull($conf);

        // Setup to take the groups of the items
        $result = $this->updateTestConfig($conf, [
            'is_active'              => 1,
            'entities_id'            => 0,
            'take_item_group_ticket' => 1,
        ]);
        $this->assertTrue($result);

        $conf = Config::getCurrentConfig();
        $this->assertNotNull($conf);

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
                'type' => \CommonITILActor::OBSERVER,
            ],
        );
        $this->assertCount(1, $groups);
    }
}
