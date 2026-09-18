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
import { test, expect, Page, APIRequestContext } from '../fixtures/moreoptions_fixture';
import { MoreOptionsConfigPage } from '../pages/MoreOptionsConfigPage';
// eslint-disable-next-line playwright/no-raw-locators
import { GlpiPage } from '../../../../../tests/e2e/pages/GlpiPage';
// eslint-disable-next-line playwright/no-raw-locators
import { Profiles } from '../../../../../tests/e2e/utils/Profiles';
// eslint-disable-next-line playwright/no-raw-locators
import { getWorkerEntityId, getWorkerUserId } from '../../../../../tests/e2e/utils/WorkerEntities';
// eslint-disable-next-line playwright/no-raw-locators
import { Config as E2EConfig } from '../../../../../tests/e2e/utils/Config';

const ASSIGN = 2;

type ItilDef = {
    label: string,
    url: string,
    api: string,
    userlink: string,
    grouplink: string,
    fk: string,
};

const ITIL_TYPES: ItilDef[] = [
    { label: 'Ticket', url: 'ticket', api: 'Ticket', userlink: 'Ticket_User', grouplink: 'Group_Ticket', fk: 'tickets_id' },
    { label: 'Change', url: 'change', api: 'Change', userlink: 'Change_User', grouplink: 'Change_Group', fk: 'changes_id' },
    { label: 'Problem', url: 'problem', api: 'Problem', userlink: 'Problem_User', grouplink: 'Group_Problem', fk: 'problems_id' },
];

/**
 * Attempt to add a solution through the "View other actions" menu.
 */
async function doAttemptSolution(page: Page, glpi: GlpiPage, content: string): Promise<void>
{
    await glpi.getButton('View other actions').click();
    await page.getByRole('listitem', { name: 'Add a solution' }).click();
    const solution_block = page.getByTestId('new-ITILSolution-block');
    const solution_content = await glpi.initRichTextByLabel('Solution', solution_block);
    await solution_content.click();
    await page.keyboard.type(content);
    await solution_block.getByRole('button', { name: 'Add', exact: true }).click();
}

type ApiSession = {
    request: APIRequestContext,
    base: string,
    token: string,
};

/**
 * Open a fresh, standalone REST API session, independent from the `api`
 * fixture's worker-cached one.
 */
async function requestWithSession(request: APIRequestContext): Promise<ApiSession>
{
    const base = E2EConfig.getBaseUrl();
    const credentials = Buffer.from('e2e_api_account:e2e_api_account').toString('base64');
    const response = await request.post(`${base}/apirest.php/initSession`, {
        headers: { Authorization: `Basic ${credentials}` },
    });
    const body = await response.json();
    return { request, base, token: body.session_token };
}

async function changeActiveEntity(session: ApiSession, entities_id: number): Promise<void>
{
    await session.request.post(`${session.base}/apirest.php/changeActiveEntities`, {
        headers: { 'Session-Token': session.token },
        data: { entities_id, is_recursive: true },
    });
}

async function createItem(session: ApiSession, itemtype: string, fields: object): Promise<number>
{
    const response = await session.request.post(`${session.base}/apirest.php/${itemtype}`, {
        headers: { 'Session-Token': session.token },
        data: { input: fields },
    });
    const body = await response.json();
    return Number(body.id);
}

/**
 * Best-effort update: a business-rule rejection from the plugin is expected
 * here just as often as a success, so the outcome is read back separately
 * rather than asserted on this response.
 */
async function updateItem(session: ApiSession, itemtype: string, id: number, fields: object): Promise<void>
{
    await session.request.put(`${session.base}/apirest.php/${itemtype}/${id}`, {
        headers: { 'Session-Token': session.token },
        data: { input: fields },
    });
}

async function getItilStatus(session: ApiSession, itemtype: string, id: number): Promise<number>
{
    const response = await session.request.get(`${session.base}/apirest.php/${itemtype}/${id}`, {
        headers: { 'Session-Token': session.token },
    });
    const body = await response.json();
    return Number(body.status);
}

for (const itil of ITIL_TYPES) {
    test.describe(`MoreOptions - Mandatory fields to close (${itil.label})`, () => {
        test(`blocks closing without a technician, allows it once one is assigned (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions require technician ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);
            // The `api` fixture caches one authenticated session per worker for its
            // whole lifetime: without a refresh, it never sees an entity created
            // after that session's first request.
            api.refreshSession();

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown(`require_technician_to_close_${itil.url}`);
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            const item_id = await api.createItem(itil.api, {
                name: `Test require technician ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            const glpi = new GlpiPage(page);
            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);

            await doAttemptSolution(page, glpi, 'Rejected: no technician assigned.');
            await expect(page.getByRole('alert').filter({ hasText: 'Technician' })).toBeVisible();
            await expect(page.getByText('Rejected: no technician assigned.')).not.toBeAttached();

            await api.createItem(itil.userlink, {
                [itil.fk]: item_id,
                users_id: getWorkerUserId(),
                type: ASSIGN,
            });

            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
            await doAttemptSolution(page, glpi, 'Accepted: technician assigned.');
            await expect(page.getByText('Accepted: technician assigned.')).toBeVisible();
        });

        test(`blocks closing without a technician group, allows it once one is assigned (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions require tech group ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);
            // The `api` fixture caches one authenticated session per worker for its
            // whole lifetime: without a refresh, it never sees an entity created
            // after that session's first request.
            api.refreshSession();

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown(`require_technicians_group_to_close_${itil.url}`);
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            const item_id = await api.createItem(itil.api, {
                name: `Test require technician group ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            const glpi = new GlpiPage(page);
            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);

            await doAttemptSolution(page, glpi, 'Rejected: no technician group assigned.');
            await expect(page.getByRole('alert').filter({ hasText: 'Technician group' })).toBeVisible();
            await expect(page.getByText('Rejected: no technician group assigned.')).not.toBeAttached();

            const groups_id = await api.createItem('Group', {
                name: `Test group ${randomUUID()}`,
                entities_id: entities_id,
            });
            await api.createItem(itil.grouplink, {
                [itil.fk]: item_id,
                groups_id: groups_id,
                type: ASSIGN,
            });

            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
            await doAttemptSolution(page, glpi, 'Accepted: technician group assigned.');
            await expect(page.getByText('Accepted: technician group assigned.')).toBeVisible();
        });

        test(`blocks closing without a category, allows it once one is set (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions require category ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);
            // The `api` fixture caches one authenticated session per worker for its
            // whole lifetime: without a refresh, it never sees an entity created
            // after that session's first request.
            api.refreshSession();

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown(`require_category_to_close_${itil.url}`);
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            const item_id = await api.createItem(itil.api, {
                name: `Test require category ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            const glpi = new GlpiPage(page);
            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);

            await doAttemptSolution(page, glpi, 'Rejected: no category set.');
            await expect(page.getByRole('alert').filter({ hasText: 'Category' })).toBeVisible();
            await expect(page.getByText('Rejected: no category set.')).not.toBeAttached();

            const itilcategories_id = await api.createItem('ITILCategory', {
                name: `Test category ${randomUUID()}`,
                entities_id: entities_id,
            });
            await api.updateItem(itil.api, item_id, { itilcategories_id: itilcategories_id });

            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
            await doAttemptSolution(page, glpi, 'Accepted: category set.');
            await expect(page.getByText('Accepted: category set.')).toBeVisible();
        });

        test(`blocks closing without a location, allows it once one is set (${itil.label})`, async ({ page, profile, api, entity }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions require location ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);
            // The `api` fixture caches one authenticated session per worker for its
            // whole lifetime: without a refresh, it never sees an entity created
            // after that session's first request.
            api.refreshSession();

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown(`require_location_to_close_${itil.url}`);
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            const item_id = await api.createItem(itil.api, {
                name: `Test require location ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            const glpi = new GlpiPage(page);
            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);

            await doAttemptSolution(page, glpi, 'Rejected: no location set.');
            await expect(page.getByRole('alert').filter({ hasText: 'Location' })).toBeVisible();
            await expect(page.getByText('Rejected: no location set.')).not.toBeAttached();

            const locations_id = await api.createItem('Location', {
                name: `Test location ${randomUUID()}`,
                entities_id: entities_id,
            });
            await api.updateItem(itil.api, item_id, { locations_id: locations_id });

            await page.goto(`/front/${itil.url}.form.php?id=${item_id}`);
            await doAttemptSolution(page, glpi, 'Accepted: location set.');
            await expect(page.getByText('Accepted: location set.')).toBeVisible();
        });

        test(`blocks a direct close without a solution, allows it once one exists (${itil.label})`, async ({ page, profile, api, entity, request }) => {
            await profile.set(Profiles.SuperAdmin);

            const entities_id = await api.createItem('Entity', {
                'name': `MoreOptions require solution ${itil.label} ${randomUUID()}`,
                'entities_id': getWorkerEntityId(),
            });
            await entity.switchToWithRecursion(entities_id);

            const config_page = new MoreOptionsConfigPage(page);
            await config_page.goto(entities_id);
            const dropdown = config_page.getConfigDropdown(`require_solution_to_close_${itil.url}`);
            await config_page.doSetDropdownValue(dropdown, 'Yes');
            await config_page.save_button.click();
            await expect(dropdown).toHaveText('Yes');

            // `require_solution_to_close` is checked through a direct status
            // change to Closed, a transition the sidebar's Status field only
            // allows one step at a time and doesn't expose reliably through
            // the UI in an automatable way. It's driven through the REST API
            // instead, with its own freshly authenticated (and explicitly
            // entity-switched) session: `Config::getConfig()` resolves the
            // *session's* active entity here, not the item's own, so reusing
            // the shared `api` fixture's session (scoped to the worker entity)
            // would silently check the wrong entity's configuration.
            const rest = await requestWithSession(request);
            await changeActiveEntity(rest, entities_id);

            const item_id = await createItem(rest, itil.api, {
                name: `Test require solution ${randomUUID()}`,
                content: 'Test item',
                entities_id: entities_id,
            });

            await updateItem(rest, itil.api, item_id, { status: 6 });
            expect(await getItilStatus(rest, itil.api, item_id)).not.toBe(6);

            await createItem(rest, 'ITILSolution', {
                itemtype: itil.api,
                items_id: item_id,
                content: 'A solution before closing.',
            });

            await updateItem(rest, itil.api, item_id, { status: 6 });
            expect(await getItilStatus(rest, itil.api, item_id)).toBe(6);
        });
    });
}
