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

(function () {
    'use strict';

    function isEmpty(value) {
        return value === null || value === undefined || value === '' || value === '0';
    }

    function initConfigForm() {
        document.querySelectorAll('form[data-moreoptions-config-form]:not([data-moreoptions-config-form-ready])').forEach(function (form) {
            form.setAttribute('data-moreoptions-config-form-ready', '');
            // The page is reloaded once saved: back on the tab it was saved from.
            var key = 'moreoptions_config_tab';
            try {
                var saved = sessionStorage.getItem(key);
                sessionStorage.removeItem(key);
                var tab = saved !== null ? form.querySelector('[data-mo-tab="' + CSS.escape(saved) + '"]') : null;
                if (tab !== null) {
                    bootstrap.Tab.getOrCreateInstance(tab).show();
                }
            } catch (e) {
                // No storage (private browsing): the first tab is shown.
            }

            form.addEventListener('submit', function () {
                try {
                    var active = form.querySelector('.nav-link.active');
                    sessionStorage.setItem(key, active && active.dataset.moTab ? active.dataset.moTab : '');
                } catch (e) {
                    // No storage: the tab is not remembered.
                }
            });
        });
    }

    function initSolutionWarning() {
        document.querySelectorAll('[data-moreoptions-solution-warning]:not([data-moreoptions-solution-warning-ready])').forEach(function (marker) {
            marker.setAttribute('data-moreoptions-solution-warning-ready', '');
            var footer = marker.closest('#itil-footer');
            var solutionToggle = footer ? footer.querySelector('[data-bs-target="#new-ITILSolution-block"]') : null;
            if (!solutionToggle) {
                return;
            }

            solutionToggle.style.fontWeight = 'normal';
            solutionToggle.style.cursor = 'not-allowed';

            solutionToggle.style.display = 'flex';
            solutionToggle.style.alignItems = 'center';
            solutionToggle.style.justifyContent = 'space-between';
            solutionToggle.style.gap = '.5rem';
            solutionToggle.style.opacity = '0.6';

            var contentWrapper = document.createElement('span');
            contentWrapper.style.display = 'inline-flex';
            contentWrapper.style.alignItems = 'center';
            contentWrapper.style.gap = '.5rem';
            contentWrapper.style.opacity = '0.6';
            while (solutionToggle.firstChild) {
                contentWrapper.appendChild(solutionToggle.firstChild);
            }
            solutionToggle.appendChild(contentWrapper);

            var lock = document.createElement('i');
            lock.className = 'ti ti-lock text-muted';
            lock.setAttribute('aria-hidden', 'true');
            solutionToggle.appendChild(lock);

            // A tooltip, not a popover: core styles popovers with a light background, tooltips stay dark.
            solutionToggle.setAttribute('data-bs-toggle', 'tooltip');
            solutionToggle.setAttribute('data-bs-placement', 'right');
            solutionToggle.setAttribute('data-bs-trigger', 'hover focus');
            solutionToggle.setAttribute('data-bs-html', 'true');
            solutionToggle.setAttribute('title', JSON.parse(marker.getAttribute('data-moreoptions-solution-warning')));

            if (typeof initTooltips === 'function') {
                initTooltips(footer);
            }
        });
    }

    function initMandatoryFields() {
        document.querySelectorAll('[data-moreoptions-mandatory-fields]:not([data-moreoptions-mandatory-fields-ready])').forEach(function (marker) {
            marker.setAttribute('data-moreoptions-mandatory-fields-ready', '');
            var config = JSON.parse(marker.getAttribute('data-moreoptions-mandatory-fields'));
            var fieldLabels = config.labels;
            var form = marker.closest('form');
            if (!form) {
                return;
            }

            Object.keys(fieldLabels).forEach(function (name) {
                var fieldContainer = form.querySelector('[data-testid="form-field-' + name + '"]');
                var label = fieldContainer ? fieldContainer.querySelector('label') : null;
                if (label && !label.querySelector('.moreoptions-required-mark')) {
                    var mark = document.createElement('span');
                    mark.className = 'required moreoptions-required-mark';
                    mark.textContent = '*';
                    label.appendChild(document.createTextNode(' '));
                    label.appendChild(mark);
                }
            });

            form.addEventListener('submit', function (e) {
                var missing = [];
                Object.keys(fieldLabels).forEach(function (name) {
                    var field = form.querySelector('[name="' + name + '"]');
                    var value = field ? field.value : '';
                    if (isEmpty(value)) {
                        missing.push(fieldLabels[name]);
                    }
                });
                if (missing.length > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    window.alert(config.error + '\n- ' + missing.join('\n- '));
                }
            });
        });
    }

    initConfigForm();
    initSolutionWarning();
    initMandatoryFields();
})();
