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
import { TicketPage } from '../../../../../tests/e2e/pages/TicketPage';
// eslint-disable-next-line playwright/no-raw-locators
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';
// eslint-disable-next-line playwright/no-raw-locators
import { getWorkerEntityId } from '../../../../../tests/e2e/utils/WorkerEntities';

test.describe('MoreOptions - Prevent closure with tasks in To Do status', () => {
    test('a ticket cannot be solved while it has a task still To Do', async ({ page, profile, api, entity }) => {
        await profile.set(Profiles.SuperAdmin);

        // Isolate this test in its own entity: it turns on a setting that
        // blocks every ticket closure in scope, which would otherwise be able
        // to interfere with unrelated specs sharing the worker's entity.
        const entity_name = `MoreOptions prevent closure ${randomUUID()}`;
        const entities_id = await api.createItem('Entity', {
            'name': entity_name,
            'entities_id': getWorkerEntityId(),
        });
        // A freshly created entity is not immediately usable otherwise: its
        // recursive rights are only picked up on the next explicit entity
        // switch.
        await entity.switchToWithRecursion(entities_id);

        const config_page = new MoreOptionsConfigPage(page);
        await config_page.goto(entities_id);
        const prevent_closure_dropdown = config_page.getConfigDropdown('prevent_closure_ticket');
        await config_page.doSetDropdownValue(prevent_closure_dropdown, 'Yes');
        await config_page.save_button.click();
        await expect(prevent_closure_dropdown).toHaveText('Yes');

        const ticket = new TicketPage(page);
        await ticket.gotoCreationPage();
        await ticket.getTextbox('Title').fill(`Test prevent closure ${randomUUID()}`);
        await ticket.getRichTextByLabel('Description').fill('Test ticket');
        await ticket.doSetEntityDropdown(entity_name);
        await ticket.getButton('Add').click();
        await page.waitForURL(/ticket\.form\.php\?id=\d+/);

        // Add a task and leave its status at its default (To Do).
        await ticket.getButton('View other actions').click();
        await page.getByRole('listitem', { name: 'Create a task' }).click();
        const task_block = page.getByTestId('new-TicketTask-block');
        await ticket.getRichTextByLabel('Task', task_block).fill('Task still to do');
        await task_block.getByRole('button', { name: 'Add', exact: true }).click();
        const task = page.getByTestId('timeline-TicketTask').last();
        // The checkbox's accessible name reflects its current state ("To do"
        // vs "Done"), so it can't be located by a fixed name.
        const task_done_checkbox = task.getByRole('checkbox');
        await expect(task_done_checkbox).not.toBeChecked();

        // Attempt to solve the ticket: blocked by the task still in To Do.
        await ticket.getButton('View other actions').click();
        await page.getByRole('listitem', { name: 'Add a solution' }).click();
        const solution_block = page.getByTestId('new-ITILSolution-block');
        const solution_content = await ticket.initRichTextByLabel('Solution', solution_block);
        await solution_content.click();
        await page.keyboard.type('This solution should be rejected.');
        await solution_block.getByRole('button', { name: 'Add', exact: true }).click();

        await expect(
            page.getByRole('alert').filter({
                hasText: 'The ticket you wish to close has tasks that need to be completed.',
            })
        ).toBeVisible();
        await expect(page.getByText('This solution should be rejected.')).not.toBeAttached();

        // Complete the task: the same solution is now accepted.
        await task_done_checkbox.check();
        await expect(task_done_checkbox).toBeChecked();

        await ticket.getButton('View other actions').click();
        await page.getByRole('listitem', { name: 'Add a solution' }).click();
        const retry_block = page.getByTestId('new-ITILSolution-block');
        const retry_content = await ticket.initRichTextByLabel('Solution', retry_block);
        await retry_content.click();
        await page.keyboard.type('This solution should be accepted.');
        await retry_block.getByRole('button', { name: 'Add', exact: true }).click();

        await expect(page.getByText('This solution should be accepted.')).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toContainText('Solved');
    });
});
