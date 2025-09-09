<?php

/**
 * -------------------------------------------------------------------------
 * More Options plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of More Options.
 *
 * More Options is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * More Options is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with More Options. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2022-2024 by More Options plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://gitlab.teclib.com/glpi-network/cancelsend/
 * -------------------------------------------------------------------------
 */

function plugin_moreoptions_install()
{
    $migration = new Migration(PLUGIN_MOREOPTIONS_VERSION);

    // Parse src directory
    foreach (glob(dirname(__FILE__) . '/src/*') as $filepath) {
        // Load *.class.php files and get the class name
        if (preg_match("/src\/(.+).php$/", $filepath, $matches)) {
            $classname = 'GlpiPlugin\\Moreoptions\\' . ucfirst($matches[1]);
            $refl = new ReflectionClass($classname);
            // If the install method exists, load it
            if (method_exists($classname, 'install') && !$refl->isTrait()) {
                Toolbox::logDebug($classname);
                $classname::install($migration);
            }
        }
    }
    $migration->executeMigration();
    return true;
}

function plugin_moreoptions_uninstall()
{
    $migration = new Migration(PLUGIN_MOREOPTIONS_VERSION);

    // Parse src directory
    foreach (glob(dirname(__FILE__) . '/src/*') as $filepath) {
        // Load *.class.php files and get the class name
        if (preg_match("/src\/(.+).php/", $filepath, $matches)) {
            $classname = 'GlpiPlugin\\Moreoptions\\' . ucfirst($matches[1]);
            $refl = new ReflectionClass($classname);
            // If the install method exists, load it
            if (method_exists($classname, 'uninstall') && !$refl->isTrait()) {
                $classname::uninstall($migration);
            }
        }
    }

    return true;
}
