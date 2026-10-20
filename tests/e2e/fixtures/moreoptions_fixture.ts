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
import { Page } from '@playwright/test';
// eslint-disable-next-line playwright/no-raw-locators
import { Api } from '../../../../../tests/e2e/utils/Api';
// eslint-disable-next-line playwright/no-raw-locators
import { EntitySwitcher } from '../../../../../tests/e2e/utils/EntitySwitcher';
// eslint-disable-next-line playwright/no-raw-locators
import { getWorkerEntityId } from '../../../../../tests/e2e/utils/WorkerEntities';
import { MoreOptionsConfigPage } from '../pages/MoreOptionsConfigPage';

// Re-export the core GLPI fixture so plugin specs share the same authenticated
// `test`/`expect` and the worker/profile/api helpers.
export * from '../../../../../tests/e2e/fixtures/glpi_fixture';

/**
 * Create a sub-entity of the worker's entity, switch to it and apply the
 * given moreoptions settings to it.
 *
 * Each test gets its own entity: the settings it turns on block closures or
 * task creations for everything in scope, which would otherwise interfere
 * with unrelated specs sharing the worker's entity.
 */
export async function createConfiguredEntity(
    page: Page,
    api: Api,
    entity: EntitySwitcher,
    config: Record<string, number>,
): Promise<number> {
    const entities_id = await api.createItem('Entity', {
        'name': `MoreOptions ${Object.keys(config).join(' ')} ${randomUUID()}`,
        'entities_id': getWorkerEntityId(),
    });
    // A freshly created entity is not immediately usable otherwise: its
    // recursive rights are only picked up on the next explicit entity switch.
    await entity.switchToWithRecursion(entities_id);
    // The `api` fixture caches one authenticated session per worker for its
    // whole lifetime: without a refresh, it never sees an entity created
    // after that session's first request.
    api.refreshSession();

    await new MoreOptionsConfigPage(page).doSetConfig(entities_id, config);

    return entities_id;
}
