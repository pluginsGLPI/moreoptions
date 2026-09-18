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

import { randomUUID } from 'crypto';
import { test, expect } from '../fixtures/moreoptions_fixture';
import { MoreOptionsConfigPage } from '../pages/MoreOptionsConfigPage';
// eslint-disable-next-line playwright/no-raw-locators
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';
// eslint-disable-next-line playwright/no-raw-locators
import { getWorkerEntityId } from '../../../../../tests/e2e/utils/WorkerEntities';

test.describe('MoreOptions - Entity configuration tab', () => {
    test('a new sub-entity inherits its parent config and can override it', async ({ page, profile, api, entity }) => {
        await profile.set(Profiles.SuperAdmin);

        const entities_id = await api.createItem('Entity', {
            'name': `MoreOptions config ${randomUUID()}`,
            'entities_id': getWorkerEntityId(),
        });
        // A freshly created entity is not immediately usable otherwise: its
        // recursive rights are only picked up on the next explicit entity
        // switch.
        await entity.switchToWithRecursion(entities_id);

        const config_page = new MoreOptionsConfigPage(page);
        await config_page.goto(entities_id);

        const dropdown = config_page.getConfigDropdown('require_solution_to_close_ticket');
        await expect(dropdown).toHaveText('Inheritance of the parent entity');

        await config_page.doSetDropdownValue(dropdown, 'Yes');
        await config_page.save_button.click();
        await expect(dropdown).toHaveText('Yes');

        // The override survives a reload.
        await config_page.goto(entities_id);
        await expect(config_page.getConfigDropdown('require_solution_to_close_ticket')).toHaveText('Yes');
    });
});
