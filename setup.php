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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Moreoptions\Config;
use GlpiPlugin\Moreoptions\Controller;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_MOREOPTIONS_VERSION', '0.0.1');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_MOREOPTIONS_MIN_GLPI_VERSION", "11.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_MOREOPTIONS_MAX_GLPI_VERSION", "11.0.99");

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_moreoptions(): void
{
    /** @var array<string, mixed> $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['moreoptions'] = true;
    $PLUGIN_HOOKS['config_page']['moreoptions'] = "../../front/entity.form.php?id=" . Session::getActiveEntity() . "&forcetab=" . Config::class . "$1";
    $plugin = new Plugin();
    if ($plugin->isActivated('moreoptions') == false) {
        return ;
    }

    Plugin::registerClass(Config::class, ['addtabon' => 'Entity']);

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['moreoptions'][Entity::class] = [
        Config::class, 'addConfig',
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['moreoptions'][Ticket_User::class] = [
        Controller::class, 'useConfig',
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['moreoptions'][Change_User::class] = [
        Controller::class, 'useConfig',
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['moreoptions'][Problem_User::class] = [
        Controller::class, 'useConfig',
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['moreoptions'][ITILSolution::class] = [
        Controller::class, 'requireFieldsToClose',
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['moreoptions'][Ticket::class] = [
        Controller::class, 'beforeCloseTicket',
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['moreoptions'][Config::class] = [
        Config::class, 'preItemUpdate',
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['moreoptions'][TicketTask::class] = [
        Controller::class, 'checkTaskRequirements',
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['moreoptions'][Item_Ticket::class] = [
        Controller::class, 'addItemGroups',
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['moreoptions'][Change_Item::class] = [
        Controller::class, 'addItemGroups',
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['moreoptions'][Item_Problem::class] = [
        Controller::class, 'addItemGroups',
    ];
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_moreoptions(): array
{
    return [
        'name'           => 'MoreOptions',
        'version'        => PLUGIN_MOREOPTIONS_VERSION,
        'author'         => '<a href="http://www.teclib.com">Teclib\'</a>',
        'license'        => '',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_MOREOPTIONS_MIN_GLPI_VERSION,
                'max' => PLUGIN_MOREOPTIONS_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_moreoptions_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_moreoptions_check_config(bool $verbose = false): bool
{
    // Your configuration check
    return true;
}

function plugin_moreoptions_geturl(): string
{
    /** @var array<string, mixed> $CFG_GLPI */
    global $CFG_GLPI;
    return sprintf('%s/plugins/moreoptions/', (string) $CFG_GLPI['url_base']);
}
