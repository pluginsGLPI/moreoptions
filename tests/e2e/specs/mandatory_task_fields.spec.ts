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
import { test, expect, Page, createConfiguredEntity } from '../fixtures/moreoptions_fixture';
import { YES } from '../pages/MoreOptionsConfigPage';
// eslint-disable-next-line playwright/no-raw-locators
import { GlpiPage } from '../../../../../tests/e2e/pages/GlpiPage';
// eslint-disable-next-line playwright/no-raw-locators
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';

type TaskDef = {
    label: string,
    url: string,
    api: string,
    taskApi: string,
};

const TICKET: TaskDef = { label: 'Ticket', url: 'ticket', api: 'Ticket', taskApi: 'TicketTask' };
const ITIL_TYPES: TaskDef[] = [
    TICKET,
    { label: 'Change', url: 'change', api: 'Change', taskApi: 'ChangeTask' },
    { label: 'Problem', url: 'problem', api: 'Problem', taskApi: 'ProblemTask' },
];

/**
 * Open the item and the "Create a task" form, fill its content.
 */
async function doOpenTaskForm(page: Page, glpi: GlpiPage, itil: TaskDef, item_id: number, content: string)
{
    await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
    await glpi.getButton('View other actions').click();
    await page.getByRole('listitem', { name: 'Create a task' }).click();
    const task_block = page.getByTestId(`new-${itil.taskApi}-block`);
    await glpi.getRichTextByLabel('Task', task_block).fill(content);
    return task_block;
}

// The task settings are shared by the three ITIL types, while the check runs
// on each task itemtype: every type is covered with the category, the other
// fields only on tickets.
test.describe('MoreOptions - Mandatory task category', () => {
    for (const itil of ITIL_TYPES) {
        test(`blocks a task without a category, allows it once one is set (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);
            const entities_id = await createConfiguredEntity(page, api, entity, { 'mandatory_task_category': YES });
            const item_id = await api.createItem(itil.api, {
                name: `Test mandatory task category ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });
            const glpi = new GlpiPage(page);

            let task_block = await doOpenTaskForm(page, glpi, itil, item_id, 'Task without a category');
            await task_block.getByRole('button', { name: 'Add', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'Category' })).toBeVisible();
            await expect(page.getByText('Task without a category')).not.toBeAttached();

            const task_category_name = `Test task category ${randomUUID()}`;
            await api.createItem('TaskCategory', {
                name: task_category_name,
                entities_id: entities_id,
                is_active: true,
            });

            task_block = await doOpenTaskForm(page, glpi, itil, item_id, 'Task with a category');
            await glpi.doSetDropdownValue(
                // Not exact: moreoptions marks the field as required, which appends a "*" to its label.
                glpi.getDropdownByLabel('Category', task_block, false),
                task_category_name,
                false,
            );
            await task_block.getByRole('button', { name: 'Add', exact: true }).click();
            await expect(page.getByText('Task with a category')).toBeVisible();
        });
    }
});

test.describe('MoreOptions - Mandatory task fields (Ticket)', () => {
    test.beforeEach(async ({ profile }) => {
        await profile.set(Profiles.SuperAdmin);
    });

    test('blocks a task without a duration, allows it once one is set', async ({ page, api, entity }) => {
        const entities_id = await createConfiguredEntity(page, api, entity, { 'mandatory_task_duration': YES });
        const item_id = await api.createItem(TICKET.api, {
            name: `Test mandatory task duration ${randomUUID()}`,
            content: 'Test item',
            entities_id: entities_id,
        });
        const glpi = new GlpiPage(page);

        let task_block = await doOpenTaskForm(page, glpi, TICKET, item_id, 'Task without a duration');
        await task_block.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'Duration' })).toBeVisible();
        await expect(page.getByText('Task without a duration')).not.toBeAttached();

        task_block = await doOpenTaskForm(page, glpi, TICKET, item_id, 'Task with a duration');
        await glpi.doSetDropdownValue(glpi.getDropdownByLabel('Duration', task_block), '1h30');
        await task_block.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.getByText('Task with a duration')).toBeVisible();
    });

    test('blocks a task without a user, allows it once one is set', async ({ page, api, entity }) => {
        const entities_id = await createConfiguredEntity(page, api, entity, { 'mandatory_task_user': YES });
        const item_id = await api.createItem(TICKET.api, {
            name: `Test mandatory task user ${randomUUID()}`,
            content: 'Test item',
            entities_id: entities_id,
        });
        const glpi = new GlpiPage(page);

        let task_block = await doOpenTaskForm(page, glpi, TICKET, item_id, 'Task without a user');
        // The user field is pre-filled with the current session user: clear it.
        // Not exact: moreoptions marks the field as required, which appends a "*" to its label.
        await glpi.doSetDropdownValue(glpi.getDropdownByLabel('User', task_block, false), '-----', false);
        await task_block.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'User' })).toBeVisible();
        await expect(page.getByText('Task without a user')).not.toBeAttached();

        // Left untouched: pre-filled with the current session user.
        task_block = await doOpenTaskForm(page, glpi, TICKET, item_id, 'Task with a user');
        await task_block.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.getByText('Task with a user')).toBeVisible();
    });

    test('blocks a task without a group, allows it once one is set', async ({ page, api, entity }) => {
        const entities_id = await createConfiguredEntity(page, api, entity, { 'mandatory_task_group': YES });
        const item_id = await api.createItem(TICKET.api, {
            name: `Test mandatory task group ${randomUUID()}`,
            content: 'Test item',
            entities_id: entities_id,
        });
        const glpi = new GlpiPage(page);

        let task_block = await doOpenTaskForm(page, glpi, TICKET, item_id, 'Task without a group');
        await task_block.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'Group' })).toBeVisible();
        await expect(page.getByText('Task without a group')).not.toBeAttached();

        const group_name = `Test task group ${randomUUID()}`;
        // `groups_id_tech` on a task only lists groups flagged for task usage.
        await api.createItem('Group', {
            name: group_name,
            entities_id: entities_id,
            is_task: true,
        });

        task_block = await doOpenTaskForm(page, glpi, TICKET, item_id, 'Task with a group');
        // Not exact: moreoptions marks the field as required, which appends a "*" to its label.
        await glpi.doSetDropdownValue(glpi.getDropdownByLabel('Group', task_block, false), group_name, false);
        await task_block.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.getByText('Task with a group')).toBeVisible();
    });
});
