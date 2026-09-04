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

import { Locator, Page } from '@playwright/test';
// eslint-disable-next-line playwright/no-raw-locators
import { GlpiPage } from '../../../../../tests/e2e/pages/GlpiPage';

// The plugin registers a single tab (index 1) on the Entity item.
const CONFIG_TAB = 'GlpiPlugin\\Moreoptions\\Config$1';

export class MoreOptionsConfigPage extends GlpiPage
{
    public readonly save_button: Locator;

    public constructor(page: Page)
    {
        super(page);

        this.save_button = this.getButton('Save');
    }

    public async goto(entities_id: number): Promise<void>
    {
        await this.page.goto(
            `/front/entity.form.php?id=${entities_id}&forcetab=${CONFIG_TAB}`
        );
    }

    /**
     * The config fields are rendered without an associated <label> (they sit
     * in a table, one row per setting, with a column per itemtype), so they
     * can't be located with `getDropdownByLabel`. The underlying <select>
     * keeps the field name though, which select2 renders as an adjacent span.
     */
    // eslint-disable-next-line playwright/no-raw-locators
    public getConfigDropdown(field: string): Locator
    {
        return this.page
            .locator(`select[name="${field}"]`)
            .locator('+ span')
            .getByRole('combobox')
        ;
    }
}
