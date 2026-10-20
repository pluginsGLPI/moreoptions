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

import { expect, Locator, Page } from '@playwright/test';
// eslint-disable-next-line playwright/no-raw-locators
import { GlpiPage } from '../../../../../tests/e2e/pages/GlpiPage';

// The plugin registers a single tab (index 1) on the Entity item.
const CONFIG_TAB = 'GlpiPlugin\\Moreoptions\\Config$1';

// Entity::CONFIG_PARENT: the "Inherit" choice, only offered below the root entity.
export const INHERIT = -2;
export const NO = 0;
export const YES = 1;

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
     * Each setting is a group of radio inputs sharing the field name (see
     * templates/components/segmented_control.html.twig). The inputs are
     * visually hidden: they are toggled through their adjacent <label>.
     */
    // eslint-disable-next-line playwright/no-raw-locators
    public getConfigOption(field: string, value: number): Locator
    {
        return this.page.locator(`input[name="${field}"][value="${value}"]`);
    }

    /**
     * Settings are split across sub-tabs: ticket / change / problem settings
     * carry the type as a suffix, task settings carry none.
     */
    // eslint-disable-next-line playwright/no-raw-locators
    public getConfigSubTab(field: string): Locator
    {
        const tab = field.match(/_(ticket|change|problem)$/)?.[1] ?? 'task';
        return this.page.locator(`[data-mo-tab="${tab}"]`);
    }

    public async doSetConfigValue(field: string, value: number): Promise<void>
    {
        await this.getConfigSubTab(field).click();
        // eslint-disable-next-line playwright/no-raw-locators
        await this.getConfigOption(field, value).locator('+ label').click();
    }

    /**
     * Open the entity's config tab, set the given settings, save, and check
     * they were stored.
     */
    public async doSetConfig(entities_id: number, values: Record<string, number>): Promise<void>
    {
        await this.goto(entities_id);
        for (const [field, value] of Object.entries(values)) {
            await this.doSetConfigValue(field, value);
        }
        // The form posts then redirects back: wait for that reload, otherwise
        // the checks below would pass on the not-yet-saved page.
        const reloaded = this.page.waitForEvent('framenavigated', (frame) => frame === this.page.mainFrame());
        await this.save_button.click();
        await reloaded;
        for (const [field, value] of Object.entries(values)) {
            await expect(this.getConfigOption(field, value)).toBeChecked();
        }
    }
}
