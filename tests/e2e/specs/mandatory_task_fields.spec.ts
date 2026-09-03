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
import { GlpiPage } from '../../../../../tests/e2e/pages/GlpiPage';
// eslint-disable-next-line playwright/no-raw-locators
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';
// eslint-disable-next-line playwright/no-raw-locators
import { getWorkerEntityId } from '../../../../../tests/e2e/utils/WorkerEntities';

type TaskDef = {
    label: string,
    url: string,
    api: string,
    taskApi: string,
};

const ITIL_TYPES: TaskDef[] = [
    { label: 'Ticket', url: 'ticket', api: 'Ticket', taskApi: 'TicketTask' },
    { label: 'Change', url: 'change', api: 'Change', taskApi: 'ChangeTask' },
    { label: 'Problem', url: 'problem', api: 'Problem', taskApi: 'ProblemTask' },
];

for (const itil of ITIL_TYPES) {
    test.describe(`MoreOptions - Mandatory task fields (${itil.label})`, () => {
        test(`blocks a task without a category, allows it once one is set (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions mandatory task category ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);
            // The `api` fixture caches one authenticated session per worker for its
            // whole lifetime: without a refresh, it never sees an entity created
            // after that session's first request.
            api.refreshSession();

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown('mandatory_task_category');
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            const item_id = await api.createItem(itil.api, {
                name: `Test mandatory task category ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            const glpi = new GlpiPage(page);
            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);

            await glpi.getButton('View other actions').click();
            await page.getByRole('listitem', { name: 'Create a task' }).click();
            const task_block = page.getByTestId(`new-${itil.taskApi}-block`);
            await glpi.getRichTextByLabel('Task', task_block).fill('Task without a category');
            await task_block.getByRole('button', { name: 'Add', exact: true }).click();

            await expect(page.getByRole('alert').filter({ hasText: 'Category' })).toBeVisible();
            await expect(page.getByText('Task without a category')).not.toBeAttached();

            const task_category_name = `Test task category ${randomUUID()}`;
            await api.createItem('TaskCategory', {
                name: task_category_name,
                entities_id: entities_id,
                is_active: true,
            });

            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
            await glpi.getButton('View other actions').click();
            await page.getByRole('listitem', { name: 'Create a task' }).click();
            const retry_block = page.getByTestId(`new-${itil.taskApi}-block`);
            await glpi.getRichTextByLabel('Task', retry_block).fill('Task with a category');
            await glpi.doSetDropdownValue(
                glpi.getDropdownByLabel('Category', retry_block),
                task_category_name,
                false,
            );
            await retry_block.getByRole('button', { name: 'Add', exact: true }).click();
            await expect(page.getByText('Task with a category')).toBeVisible();
        });

        test(`blocks a task without a duration, allows it once one is set (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions mandatory task duration ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);
            // The `api` fixture caches one authenticated session per worker for its
            // whole lifetime: without a refresh, it never sees an entity created
            // after that session's first request.
            api.refreshSession();

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown('mandatory_task_duration');
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            const item_id = await api.createItem(itil.api, {
                name: `Test mandatory task duration ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            const glpi = new GlpiPage(page);
            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);

            await glpi.getButton('View other actions').click();
            await page.getByRole('listitem', { name: 'Create a task' }).click();
            const task_block = page.getByTestId(`new-${itil.taskApi}-block`);
            await glpi.getRichTextByLabel('Task', task_block).fill('Task without a duration');
            await task_block.getByRole('button', { name: 'Add', exact: true }).click();

            await expect(page.getByRole('alert').filter({ hasText: 'Duration' })).toBeVisible();
            await expect(page.getByText('Task without a duration')).not.toBeAttached();

            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
            await glpi.getButton('View other actions').click();
            await page.getByRole('listitem', { name: 'Create a task' }).click();
            const retry_block = page.getByTestId(`new-${itil.taskApi}-block`);
            await glpi.getRichTextByLabel('Task', retry_block).fill('Task with a duration');
            await glpi.doSetDropdownValue(glpi.getDropdownByLabel('Duration', retry_block), '1h30');
            await retry_block.getByRole('button', { name: 'Add', exact: true }).click();
            await expect(page.getByText('Task with a duration')).toBeVisible();
        });

        test(`blocks a task without a user, allows it once one is set (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions mandatory task user ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);
            // The `api` fixture caches one authenticated session per worker for its
            // whole lifetime: without a refresh, it never sees an entity created
            // after that session's first request.
            api.refreshSession();

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown('mandatory_task_user');
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            const item_id = await api.createItem(itil.api, {
                name: `Test mandatory task user ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            const glpi = new GlpiPage(page);
            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);

            await glpi.getButton('View other actions').click();
            await page.getByRole('listitem', { name: 'Create a task' }).click();
            const task_block = page.getByTestId(`new-${itil.taskApi}-block`);
            await glpi.getRichTextByLabel('Task', task_block).fill('Task without a user');
            // The user field is pre-filled with the current session user: clear it.
            await glpi.doSetDropdownValue(glpi.getDropdownByLabel('User', task_block), '-----', false);
            await task_block.getByRole('button', { name: 'Add', exact: true }).click();

            await expect(page.getByRole('alert').filter({ hasText: 'User' })).toBeVisible();
            await expect(page.getByText('Task without a user')).not.toBeAttached();

            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
            await glpi.getButton('View other actions').click();
            await page.getByRole('listitem', { name: 'Create a task' }).click();
            const retry_block = page.getByTestId(`new-${itil.taskApi}-block`);
            await glpi.getRichTextByLabel('Task', retry_block).fill('Task with a user');
            // Left untouched: pre-filled with the current session user.
            await retry_block.getByRole('button', { name: 'Add', exact: true }).click();
            await expect(page.getByText('Task with a user')).toBeVisible();
        });

        test(`blocks a task without a group, allows it once one is set (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions mandatory task group ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);
            // The `api` fixture caches one authenticated session per worker for its
            // whole lifetime: without a refresh, it never sees an entity created
            // after that session's first request.
            api.refreshSession();

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown('mandatory_task_group');
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            const item_id = await api.createItem(itil.api, {
                name: `Test mandatory task group ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            const glpi = new GlpiPage(page);
            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);

            await glpi.getButton('View other actions').click();
            await page.getByRole('listitem', { name: 'Create a task' }).click();
            const task_block = page.getByTestId(`new-${itil.taskApi}-block`);
            await glpi.getRichTextByLabel('Task', task_block).fill('Task without a group');
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

            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
            await glpi.getButton('View other actions').click();
            await page.getByRole('listitem', { name: 'Create a task' }).click();
            const retry_block = page.getByTestId(`new-${itil.taskApi}-block`);
            await glpi.getRichTextByLabel('Task', retry_block).fill('Task with a group');
            await glpi.doSetDropdownValue(glpi.getDropdownByLabel('Group', retry_block), group_name, false);
            await retry_block.getByRole('button', { name: 'Add', exact: true }).click();
            await expect(page.getByText('Task with a group')).toBeVisible();
        });
    });
}
