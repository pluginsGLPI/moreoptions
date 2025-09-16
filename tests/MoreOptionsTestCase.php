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

namespace GlpiPlugin\Moreoptions\Tests;

use Auth;
use GlpiPlugin\Moreoptions\Config;
use PHPUnit\Framework\TestCase;
use Session;

abstract class MoreOptionsTestCase extends TestCase
{
    public const TU_USER = 'glpi';
    public const TU_PASS = 'glpi';

    protected function setUp(): void
    {
        global $DB;

        // Start a transaction for each test
        $DB->beginTransaction();

        // Connect the test user
        $this->login();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        global $DB;

        // Rollback the transaction to clean up the database
        $DB->rollback();

        parent::tearDown();
    }

    /**
     * Login with the test user
     */
    protected function login(
        string $user_name = self::TU_USER,
        string $user_pass = self::TU_PASS,
        bool $noauto = true,
        bool $expected = true,
    ): Auth {
        Session::destroy();
        Session::start();

        $auth = new Auth();
        $this->assertEquals($expected, $auth->login($user_name, $user_pass, $noauto));

        return $auth;
    }

    /**
     * Logout the current user
     */
    protected function logOut(): void
    {
        $ctime = $_SESSION['glpi_currenttime'] ?? null;
        Session::destroy();
        if ($ctime) {
            $_SESSION['glpi_currenttime'] = $ctime;
        }
    }

    /**
     * Create a test configuration for the plugin
     * @param array<string, mixed> $options
     */
    protected function createTestConfig(array $options = []): Config
    {
        $config = new Config();

        $default_config = [
            'is_active' => 1,
            'entities_id' => 0,
            'take_item_group_ticket' => 0,
            'take_requester_group_ticket' => 0,
            'take_technician_group_ticket' => 0,
            'take_item_group_change' => 0,
            'take_requester_group_change' => 0,
            'take_technician_group_change' => 0,
            'take_item_group_problem' => 0,
            'take_requester_group_problem' => 0,
            'take_technician_group_problem' => 0,
            'require_technician_to_close_ticket' => 0,
            'require_technicians_group_to_close_ticket' => 0,
            'require_category_to_close_ticket' => 0,
            'require_location_to_close_ticket' => 0,
            'require_solution_to_close_ticket' => 0,
            'prevent_closure_ticket' => 0,
            'prevent_closure_change' => 0,
            'prevent_closure_problem' => 0,
            'mandatory_task_category' => 0,
            'mandatory_task_duration' => 0,
            'mandatory_task_user' => 0,
            'mandatory_task_group' => 0,
        ];

        $input = array_merge($default_config, $options);

        $result = $config->add($input);
        $this->assertGreaterThan(0, $result, 'Failed to create test config');

        return $config;
    }

    /**
     * Update the test configuration
     * @param array<string, mixed> $updates
     */
    protected function updateTestConfig(Config $config, array $updates): bool
    {
        $input = array_merge(['id' => $config->getID()], $updates);
        return $config->update($input);
    }

    /**
     * Get the current configuration or create one
     */
    protected function getCurrentConfig(): Config
    {
        $config = Config::getCurrentConfig();
        if (empty($config->fields) || $config->isNewItem()) {
            $config = $this->createTestConfig();
        }
        return $config;
    }
}
