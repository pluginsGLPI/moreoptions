<?php

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
 * @link      https://gitlab.teclib.com/glpi-network/moreoptions/
 * -------------------------------------------------------------------------
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Moreoptions\EscalationRule;

Session::checkLoginUser();

if (!EscalationRule::canManageRules()) {
    throw new AccessDeniedHttpException();
}

if (isset($_POST['update'])) {
    $result = EscalationRule::switchActions(is_array($_POST['fields'] ?? null) ? $_POST['fields'] : []);
    if ($result['updated'] > 0) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            _n('%d rule action has been updated.', '%d rule actions have been updated.', $result['updated'], 'moreoptions'),
            $result['updated'],
        )));
    }

    if ($result['failed'] > 0) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            _n('%d rule action cannot be updated.', '%d rule actions cannot be updated.', $result['failed'], 'moreoptions'),
            $result['failed'],
        )), false, ERROR);
    }

    Html::back();
}

Html::header(__('Escalation in business rules', 'moreoptions'), '', 'admin', 'rule');
EscalationRule::showRulesList();
Html::footer();
