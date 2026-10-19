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
import { test, expect, createConfiguredEntity } from '../fixtures/moreoptions_fixture';
import { YES } from '../pages/MoreOptionsConfigPage';
// eslint-disable-next-line playwright/no-raw-locators
import { TicketPage } from '../../../../../tests/e2e/pages/TicketPage';
// eslint-disable-next-line playwright/no-raw-locators
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';

test.describe('MoreOptions - Prevent closure with tasks in To Do status', () => {
    test('a ticket cannot be solved while it has a task still To Do', async ({ page, profile, api, entity }) => {
        await profile.set(Profiles.SuperAdmin);

        const entities_id = await createConfiguredEntity(page, api, entity, { 'prevent_closure_ticket': YES });

        // Created through the API: the creation form is not what is tested here.
        const ticket_id = await api.createItem('Ticket', {
            name: `Test prevent closure ${randomUUID()}`,
            content: 'Test ticket',
            entities_id: entities_id,
        });
        const ticket = new TicketPage(page);
        await page.goto(`/front/ticket.form.php?id=${ticket_id}`);

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
