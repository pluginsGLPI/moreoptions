/*
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

window.GlpiPluginMoreoptionsEscalationGraph = class {
    /** Temporary node following the pointer while a link is drawn */
    static TMP_NODE = 'mo-gl-tmp-node';

    /** Pointer move after which a press on an output point draws a link */
    static DRAG_THRESHOLD = 5;

    /** Duration of the moves of the groups when the layout changes, in milliseconds. */
    static ANIMATION_DURATION = 300;

    /** Number of changes that can be undone. */
    static HISTORY_SIZE = 20;

    /** Key of the folded sections of the group list in the storage of the browser. */
    static FOLDED_KEY = 'moreoptions-escalation-folded';

    /** Key of the orientation and the drawing strategy of the user in the storage of the browser. */
    static PREFERENCES_KEY = 'moreoptions-escalation-preferences';

    /**
     * Editors of the page: the ones of a reloaded tab are destroyed. Kept when this script runs
     * again, with the tab.
     */
    static instances = window.GlpiPluginMoreoptionsEscalationGraph?.instances ?? new Set();

    /**
     * @param {HTMLElement} container Wrapper of the editor, with its configuration in `data-mo-config`
     */
    constructor(container) {
        this.constructor.instances.forEach(editor => !editor.container.isConnected && editor.destroy());
        this.constructor.instances.add(this);
        this.container = container;

        // Listeners on the page, removed with the editor
        this.listeners = new AbortController();
        this.config = JSON.parse(container.dataset.moConfig);
        this.orientation = null;

        // Changes are sent one at a time, in order: each one with the draft of the previous response.
        this.queue = [];
        this.pending = false;
        this.colors = new Map();
        this.badges = new Map();
        this.segments = new WeakMap();
        // Link being drawn (see enableLinkDrawing()): the graph is only updated once it is drawn.
        this.drawing = null;
        this.outdated = false;
        // Sections of the group list folded by the user (see group_list.html.twig)
        this.folded = new Set(this.readStorage(this.constructor.FOLDED_KEY) ?? []);
        // Drafts before the changes made (undo), and before the changes undone (redo)
        this.history = { undo: [], redo: [] };

        this.foldSections(this.form);
        this.createGraph();
        this.updateGraph();
        this.updateHistoryButtons();
        // The first render shows the whole graph; the view is then the one of the user.
        this.fit();
        this.bindEditorEvents();
        this.bindKeyboard();
        this.bindGroupDragAndDrop();
        this.restorePreferences();
    }

    get form() {
        return this.container.querySelector('[data-mo-graph]');
    }

    get canEdit() {
        return this.form?.hasAttribute('data-mo-canedit') ?? false;
    }

    get hasSelection() {
        return this.form.hasAttribute('data-mo-has-selection');
    }

    /** The draft held by the editor (see TreeEditor::getState()), as sent back with each change */
    get state() {
        return this.form.elements.state.value;
    }

    /** Duration and easing of the animations of the graph */
    get animationOptions() {
        return { duration: this.constructor.ANIMATION_DURATION, easing: 'ease-in-out' };
    }

    get reducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /** Stops the editor of a tab gone: its graph, its context menu and its listeners on the page. */
    destroy() {
        this.constructor.instances.delete(this);
        this.listeners.abort();
        this.queue = [];
        this.request?.abort();
        if (this.cy !== undefined && !this.cy.destroyed()) {
            this.cy.contextMenus('get').destroy();
            this.cy.destroy();
        }
    }

    // ----------------------------------------------------------------------------------------
    // Graph
    // ----------------------------------------------------------------------------------------

    /** Creates the graph, once: its elements are then updated by updateGraph(). */
    createGraph() {
        this.cy = cytoscape({
            container: this.container.querySelector('[data-mo-cy]'),
            layout: { name: 'preset' },
            wheelSensitivity: 0.25,
            minZoom: 0.5,
            maxZoom: 2,
            // Positions are computed server side, and the selection is the one of the server.
            autoungrabify: true,
            autounselectify: true,
            boxSelectionEnabled: false,
        });
        this.cy.on('tap', 'node', (event) => {
            if (event.target.id() !== this.constructor.TMP_NODE) {
                this.send('select_node', { group: event.target.id() });
            }
        });
        this.cy.on('tap', 'edge', event => this.send('select_link', { link: event.target.id() }));
        this.cy.on('tap', (event) => {
            if (event.target === this.cy && this.hasSelection) {
                this.send('clear');
            }
        });
        this.cy.on('mouseover', 'node, edge', () => { this.cy.container().style.cursor = 'pointer'; });
        this.cy.on('mouseout', 'node, edge', () => { this.cy.container().style.cursor = ''; });

        if (this.canEdit) {
            this.enableLinkDrawing();
            this.enableContextMenu();
        }
    }

    /**
     * Applies the elements of the editor to the graph. Cytoscape compares them by id: elements gone
     * are removed, new ones added, the others updated (data, position, classes such as the selection).
     */
    updateGraph() {
        this.outdated = false;
        this.cy.elements().stop(true, true);

        // Get the elements of the graph to get data
        const form = this.form;
        const elements = JSON.parse(this.container.querySelector('[data-mo-elements]').textContent);
        elements.edges.forEach((edge) => { edge.data.color = this.resolveColor(edge.data.color); });

        // Animation of the groups moving to their new position
        const previousPositions = new Map(this.cy.nodes().map(node => [node.id(), { ...node.position() }]));
        this.cy.json({ elements });
        if (previousPositions.size > 0) {
            this.animateChanges(previousPositions);
        }

        // Set the style of the graph, depending on the orientation
        if (form.dataset.moOrientation !== this.orientation) {
            this.orientation = form.dataset.moOrientation;
            this.cy.style(this.getStyle(this.orientation === 'vertical'));
        }

        // Center the graph on the element that was added
        const added = this.cy.getElementById(form.dataset.moScrollTo ?? '');
        if (added.nonempty()) {
            const box = added.boundingBox();
            const view = this.cy.extent();
            if (box.x1 < view.x1 || box.x2 > view.x2 || box.y1 < view.y1 || box.y2 > view.y2) {
                this.cy.animate({ center: { eles: added } }, { duration: this.reducedMotion ? 0 : this.constructor.ANIMATION_DURATION });
            }
        }
    }

    /**
     * The points where a link turns, as Cytoscape wants them for a "segments" edge: their position
     * along the line from the start of the link to its end (weight), and their distance from it.
     */
    getSegments(edge) {
        const route = edge.data('route');
        if (!this.segments.has(route)) {
            const { from, to, points } = route;
            const [dx, dy] = [to.x - from.x, to.y - from.y];
            const length = Math.hypot(dx, dy) || 1;
            // A straight link has no segment: neutral values, Cytoscape refusing empty ones
            this.segments.set(route, {
                weights: points.map(({ x, y }) => ((x - from.x) * dx + (y - from.y) * dy) / (length * length)).join(' ') || '0.5',
                distances: points.map(({ x, y }) => ((y - from.y) * dx - (x - from.x) * dy) / length).join(' ') || '0',
            });
        }
        return this.segments.get(route);
    }

    /**
     * The groups moved by the last change glide from their previous position to their new one, and
     * the new ones fade in, unless the user prefers reduced motion.
     *
     * @param {Map<string, {x: number, y: number}>} previousPositions Positions of the nodes before the change, by id
     */
    animateChanges(previousPositions) {
        if (this.reducedMotion) {
            return;
        }
        
        const groups = this.cy.nodes();
        const moved = groups.filter((group) => {
            const previous = previousPositions.get(group.id());
            return previous !== undefined && (previous.x !== group.position('x') || previous.y !== group.position('y'));
        });

        moved.forEach((group) => {
            const position = { ...group.position() };
            group.position(previousPositions.get(group.id())).animate({ position }, this.animationOptions);
        });
        this.fadeIn(groups.filter(group => !previousPositions.has(group.id())));
        // The routes of the links are the ones of the new layout: shown once the groups are in place.
        if (moved.nonempty()) {
            this.fadeIn(this.cy.edges(), this.constructor.ANIMATION_DURATION);
        }
    }

    /**
     * Makes elements of the graph appear: one animation per element, each one clearing its own
     * opacity once done.
     *
     * @param {Object} elements Collection of Cytoscape elements
     * @param {number} delay    Milliseconds before they appear
     */
    fadeIn(elements, delay = 0) {
        elements.style('opacity', 0).forEach(element => element.delay(delay).animate(
            { style: { opacity: 1 } },
            { ...this.animationOptions, complete: () => element.removeStyle('opacity') },
        ));
    }

    /**
     * Badge of the level of a group ("L2"), drawn on it as a background image: it follows the group,
     * the zoom and the animations.
     */
    getLevelBadge(level) {
        if (!this.badges.has(level)) {
            const text = this.config.strings.level.replace('%d', level);
            const width = 10 + text.length * 6;
            const [fill, color] = [this.resolveColor('color-mix(in srgb, var(--mo-gl-accent), var(--tblr-bg-surface) 85%)'), this.resolveColor('var(--mo-gl-accent)')];
            const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="16">`
                + `<rect width="${width}" height="16" rx="4" fill="${fill}"/>`
                + `<text x="${width / 2}" y="11.5" text-anchor="middle" font-family="monospace" font-size="10" font-weight="600" fill="${color}">${text}</text>`
                + '</svg>';
            this.badges.set(level, { image: 'data:image/svg+xml;utf8,' + encodeURIComponent(svg), width });
        }
        return this.badges.get(level);
    }

    /** Whole graph in view, without zooming in beyond 100%. */
    fit() {
        this.cy.fit(undefined, 30);
        if (this.cy.zoom() > 1) {
            this.cy.zoom(1);
            this.cy.center();
        }
    }

    /** Cytoscape needs actual colors: CSS colors (variables included) are resolved in the page. */
    resolveColor(value) {
        if (!this.colors.has(value)) {
            const probe = document.createElement('span');
            probe.style.color = value;
            this.container.append(probe);
            this.colors.set(value, getComputedStyle(probe).color);
            probe.remove();
        }
        return this.colors.get(value);
    }

    getStyle(vertical) {
        const [text, surface, border, accent, danger] = ['--tblr-body-color', '--tblr-bg-surface', '--tblr-border-color', '--mo-gl-accent', '--tblr-danger']
            .map(name => this.resolveColor(`var(${name})`));
        const { width, height } = this.config.node;
        const highlight = { 'border-width': 2, 'border-color': accent };

        return [
            // Stacking: links (0) under the groups (1), under the link being drawn (20)
            { selector: 'node, edge', style: { 'z-index-compare': 'manual', 'z-index': 0 } },
            {
                selector: 'node[label]',
                style: {
                    'z-index': 1, 'shape': 'round-rectangle', 'width': width, 'height': height,
                    'background-color': surface, 'border-width': 1, 'border-color': border,
                    'label': 'data(label)', 'color': text, 'font-family': getComputedStyle(this.container).fontFamily,
                    'font-size': 13, 'font-weight': 600, 'text-valign': 'center', 'text-halign': 'center',
                    'text-wrap': 'wrap', 'text-max-width': width - 14,
                    // Badge of the level, at the top left
                    'background-image': node => this.getLevelBadge(node.data('level')).image,
                    'background-width': node => this.getLevelBadge(node.data('level')).width,
                    'background-height': 16,
                    'background-fit': 'none',
                    'background-position-x': 6,
                    'background-position-y': 6,
                    'background-clip': 'none',
                },
            },
            { selector: 'node.current', style: { 'border-width': 2 } },
            // Light feedback while pressed (Cytoscape draws a large gray overlay by default)
            { selector: 'node:active, edge:active', style: { 'overlay-color': accent, 'overlay-opacity': 0.08, 'overlay-padding': 4 } },
            { selector: 'node.source', style: highlight },
            { selector: 'node.selected, node.drop', style: { ...highlight, 'underlay-color': accent, 'underlay-opacity': 0.15, 'underlay-padding': 4 } },
            // Ancestor of the group a link is drawn from: linking to it would make a loop.
            { selector: 'node.forbidden', style: { 'border-color': danger, 'border-style': 'dashed', 'opacity': 0.6 } },
            // Link being drawn: under the pointer, but not to be hovered or tapped
            { selector: '#' + this.constructor.TMP_NODE, style: { 'width': 1, 'height': 1, 'opacity': 0, 'events': 'no' } },
            {
                selector: 'edge',
                style: {
                    'width': 2.5, 'line-color': 'data(color)', 'target-arrow-color': 'data(color)', 'target-arrow-shape': 'triangle', 'arrow-scale': 1.2,
                    // From the output side of the source group to the input side of the target one
                    'source-endpoint': vertical ? '0% 50%' : '50% 0%', 'target-endpoint': vertical ? '0% -50%' : '-50% 0%',
                    'source-distance-from-node': 0, 'target-distance-from-node': 1,
                },
            },
            // Along the route computed server side, never crossing a group, from its start to its end
            {
                selector: 'edge[route]',
                style: {
                    'curve-style': edge => (edge.data('route').points.length > 0 ? 'segments' : 'straight'),
                    'edge-distances': 'endpoints',
                    'segment-weights': edge => this.getSegments(edge).weights,
                    'segment-distances': edge => this.getSegments(edge).distances,
                    'source-endpoint': edge => edge.data('endpoints').source,
                    'target-endpoint': edge => edge.data('endpoints').target,
                },
            },
            { selector: 'edge[?replicated]', style: { 'line-style': 'dashed', 'line-dash-pattern': [7, 5] } },
            { selector: 'edge.selected', style: { 'width': 4.5 } },
            {
                selector: 'edge.preview',
                style: {
                    'z-index': 20, 'events': 'no', 'curve-style': 'straight', 'line-style': 'dashed',
                    'source-endpoint': 'outside-to-node', 'target-endpoint': 'outside-to-node', 'source-distance-from-node': 0, 'target-distance-from-node': 0,
                },
            },
            { selector: 'edge.preview.forbidden', style: { 'line-color': danger, 'target-arrow-color': danger } },
        ];
    }

    /**
     * Dragging a group onto another group links them. A press without moving stays a tap
     * (selection of the group). A group cannot escalate to one of its ancestors: that would make a
     * loop (checked again server side).
     */
    enableLinkDrawing() {
        const TMP_NODE = this.constructor.TMP_NODE;
        const GROUPS = 'node[label]';
        // The group a link would go to: a group cannot escalate to one of its ancestors.
        const setTarget = (target) => {
            const forbidden = target !== null && this.drawing.group.predecessors().contains(target);
            this.drawing.target?.removeClass('drop forbidden');
            target?.addClass(forbidden ? 'forbidden' : 'drop');
            this.cy.getElementById(TMP_NODE + '-edge').toggleClass('forbidden', forbidden);
            this.drawing.target = target;
        };

        this.cy.on('tapstart', GROUPS, (event) => {
            this.drawing = { group: event.target, start: { ...event.renderedPosition }, started: false, target: null };
            this.cy.userPanningEnabled(false);
        });

        this.cy.on('tapdrag', (event) => {
            if (this.drawing === null) {
                return;
            }
            if (!this.drawing.started) {
                const { x, y } = event.renderedPosition;
                if (Math.hypot(x - this.drawing.start.x, y - this.drawing.start.y) < this.constructor.DRAG_THRESHOLD) {
                    return;
                }
                this.drawing.started = true;
                this.drawing.group.addClass('source');
                this.cy.container().style.cursor = 'crosshair';
                // Drawn with the arrow of the strategy chosen above the graph
                const strategy = this.config.strategies.find(({ value }) => value === this.form.dataset.moDrawingStrategy);
                this.cy.add([
                    { group: 'nodes', data: { id: TMP_NODE }, position: { ...event.position } },
                    {
                        group: 'edges',
                        data: { id: TMP_NODE + '-edge', source: this.drawing.group.id(), target: TMP_NODE, color: this.resolveColor(strategy?.color ?? 'var(--mo-gl-accent)') },
                        classes: 'preview',
                    },
                ]);
            }
            this.cy.getElementById(TMP_NODE).position({ ...event.position });
        });

        // The group hovered while drawing
        this.cy.on('tapdragover', GROUPS, (event) => {
            if (this.drawing?.started && event.target !== this.drawing.group) {
                setTarget(event.target);
            }
        });
        this.cy.on('tapdragout', GROUPS, (event) => {
            if (this.drawing?.started && event.target === this.drawing.target) {
                setTarget(null);
            }
        });

        this.cy.on('tapend', () => {
            if (this.drawing === null) {
                return;
            }
            const { group, started, target } = this.drawing;
            this.drawing = null;
            this.cy.userPanningEnabled(true);
            if (started) {
                this.cy.getElementById(TMP_NODE).remove();
                this.cy.container().style.cursor = '';
                group.removeClass('source');
                target?.removeClass('drop forbidden');
                // A response came while drawing
                if (this.outdated) {
                    this.updateGraph();
                }
                // A forbidden target is sent anyway: the server refuses it, and explains why.
                if (target !== null) {
                    this.send('link', { from: group.id(), to: target.id() });
                }
            }
        });
    }

    /** Context menu (right click), as in the impact analysis. */
    enableContextMenu() {
        const { strings, strategies } = this.config;
        const item = (id, content, selector, action, params = {}) => ({
            id: 'mo-gl-' + id,
            content,
            selector,
            onClickFunction: event => this.send(action, { [selector.startsWith('node') ? 'group' : 'link']: event.target.id(), ...params }),
        });

        this.cy.contextMenus({
            menuItems: [
                item('remove-node', strings.remove_node, 'node[label]', 'remove_node'),
                // A replicated link cannot be changed, only removed from the entity.
                ...strategies.map(strategy => item(
                    'type-' + strategy.value,
                    strings.link_type.replace('%s', strategy.label),
                    `edge[!replicated][type != "${strategy.value}"]`,
                    'set_link_type',
                    { value: strategy.value },
                )),
                item('delete-link', strings.delete_link, 'edge', 'delete_link'),
            ],
        });
        // Buttons in the form of the editor: not to submit it (Enter in a field would click them).
        this.cy.container().querySelectorAll('.cy-context-menus-cxt-menuitem').forEach((button) => { button.type = 'button'; });
    }

    // ----------------------------------------------------------------------------------------
    // Editor
    // ----------------------------------------------------------------------------------------

    /**
     * Sends a change, along with the draft, and renders the editor of the response. A change made
     * while another one is being sent waits for it. Undoing (`undo`) sends back the draft before the
     * last change, redoing (`redo`) the one before the last change undone.
     *
     * @param {string} action
     * @param {Object} params
     * @param {'change'|'undo'|'redo'} kind
     */
    send(action, params = {}, kind = 'change') {
        // The fields as they are now: the editor may be rendered again before the change is sent.
        const fields = Object.fromEntries(new FormData(this.form));
        delete fields.state;
        this.queue.push({ action, params: { ...fields, ...params }, kind });
        if (!this.pending) {
            this.sendNext();
        }
    }

    sendNext() {
        const next = this.queue.shift();
        this.pending = next !== undefined;
        this.container.classList.toggle('mo-gl-busy', this.pending);
        if (next === undefined) {
            return;
        }
        const before = this.state;
        // The draft to go back to, when undoing or redoing
        const restored = next.kind === 'change' ? undefined : this.history[next.kind].pop();
        if (next.kind !== 'change' && restored === undefined) {
            this.sendNext();
            return;
        }

        this.request = $.post(this.config.url, { ...next.params, state: restored ?? before, action: next.action });
        this.request.done((html) => {
            if (this.cy.destroyed()) {
                return;
            }
            this.replaceEditor(html);
            this.outdated = this.drawing?.started ?? false;
            if (!this.outdated) {
                this.updateGraph();
            }
            this.recordHistory(next, before);
            this.writeStorage(this.constructor.PREFERENCES_KEY, this.preferences);
            if (next.action === 'save') {
                displayAjaxMessageAfterRedirect();
            }
        }).fail(() => {
            if (this.cy.destroyed()) {
                return;
            }
            // The changes waiting were made on the draft the server refused.
            this.queue = [];
            if (restored !== undefined) {
                this.history[next.kind].push(restored);
            }
            glpi_toast_error(this.config.strings.error);
        }).always(() => {
            this.updateHistoryButtons();
            this.sendNext();
        });
    }

    // ----------------------------------------------------------------------------------------
    // History
    // ----------------------------------------------------------------------------------------

    /**
     * The part of a draft the history follows: the tree (groups, links, removed links, reset), not
     * the selection.
     */
    treeOf({ nodes, links, removed, reset }) {
        return JSON.stringify({ nodes, links, removed, reset });
    }

    /**
     * Keeps the draft before a change, to undo it. A change undone can be redone, until another
     * change is made. Saving, or reloading the graph, starts a new history.
     *
     * @param {{action: string, kind: string}} change
     * @param {string} before Draft before the change
     */
    recordHistory(change, before) {
        const { undo, redo } = this.history;
        const [previous, current] = [JSON.parse(before), JSON.parse(this.state)];
        // Saved, or reloaded (another entity, links saved by someone else): a new history.
        if (previous.entities_id !== current.entities_id || previous.version !== current.version) {
            this.history = { undo: [], redo: [] };
        } else if (change.kind === 'undo') {
            redo.push(before);
        } else if (change.kind === 'redo') {
            undo.push(before);
        } else if (this.treeOf(previous) !== this.treeOf(current)) {
            undo.push(before);
            undo.splice(0, undo.length - this.constructor.HISTORY_SIZE);
            redo.length = 0;
        }
    }

    undo() {
        this.send('render', {}, 'undo');
    }

    redo() {
        this.send('render', {}, 'redo');
    }

    updateHistoryButtons() {
        this.container.querySelector('[data-mo-undo]')?.toggleAttribute('disabled', this.history.undo.length === 0);
        this.container.querySelector('[data-mo-redo]')?.toggleAttribute('disabled', this.history.redo.length === 0);
    }

    /**
     * Replaces the editor by the one of the response, around the container of the graph, which
     * stays in the page. Inserted with jQuery, so that the scripts of the GLPI dropdowns run. The
     * focus, and the search typed meanwhile, are kept. When the element focused is gone, the focus
     * goes to the panel of the selection, or back to the group selected in the list of the groups.
     */
    replaceEditor(html) {
        // Parsed out of the page: its scripts only run once inserted.
        const freshForm = $('<div>').append($.parseHTML(html, document, true)).find('[data-mo-graph]')[0];
        this.foldSections(freshForm);
        const liveForm = this.form;
        const focused = this.selectorOf(document.activeElement);
        const selected = JSON.parse(this.state).selected_node;
        const search = liveForm.querySelector('[data-mo-search]');
        const typed = search === null ? null : { value: search.value, start: search.selectionStart, end: search.selectionEnd };
        // Shown tooltips and popovers would stay on the page once their element is replaced: hidden
        // (disposed while fading out, Bootstrap would fail at the end of the fade).
        liveForm.querySelectorAll('[data-bs-toggle="tooltip"], [data-bs-toggle="popover"]').forEach((element) => {
            bootstrap.Tooltip.getInstance(element)?.hide();
            bootstrap.Popover.getInstance(element)?.hide();
        });
        [...liveForm.attributes].forEach(attribute => liveForm.removeAttribute(attribute.name));
        [...freshForm.attributes].forEach(attribute => liveForm.setAttribute(attribute.name, attribute.value));

        // The parts of the form around its body, then the parts of the body around the graph
        const replaceAround = (live, fresh, selector) => {
            const keep = live.querySelector(selector);
            const pivot = fresh.querySelector(selector);
            const children = [...fresh.childNodes];
            [...live.childNodes].filter(node => node !== keep).forEach(node => node.remove());
            $(keep).before(children.slice(0, children.indexOf(pivot))).after(children.slice(children.indexOf(pivot) + 1));
            return [keep, pivot];
        };
        const [liveBody, freshBody] = replaceAround(liveForm, freshForm, ':scope > .mo-gl-body');
        replaceAround(liveBody, freshBody, ':scope > [data-mo-cy]');

        const freshSearch = liveForm.querySelector('[data-mo-search]');
        if (typed !== null && freshSearch !== null && freshSearch.value !== typed.value) {
            freshSearch.value = typed.value;
            freshSearch.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const element = focused === null ? null : liveForm.querySelector(focused)
            ?? liveForm.querySelector('[data-mo-panel-title]')
            ?? liveForm.querySelector(`[data-mo-list] [data-mo-group="${selected}"]`);
        element?.focus({ preventScroll: true });
        if (element !== null && element === freshSearch && typed !== null) {
            freshSearch.setSelectionRange(typed.start, typed.end);
        }
    }

    /**
     * A selector of an element of the editor, finding it again once the editor is replaced.
     *
     * @returns {string|null}
     */
    selectorOf(element) {
        if (!(element instanceof HTMLElement) || !this.form.contains(element)) {
            return null;
        }
        if (element.id !== '') {
            return '#' + CSS.escape(element.id);
        }
        const attributes = ['name', 'data-mo-action', 'data-mo-group', 'data-mo-link', 'data-mo-direction', 'data-mo-search', 'data-mo-undo', 'data-mo-redo', 'data-mo-fit', 'data-mo-panel-title']
            .filter(attribute => element.hasAttribute(attribute))
            .map(attribute => `[${attribute}="${CSS.escape(element.getAttribute(attribute))}"]`);

        return attributes.length > 0 ? element.localName + attributes.join('') : null;
    }

    /** Folds the sections of the group list the user folded. */
    foldSections(form) {
        form.querySelectorAll('[data-mo-section]').forEach((section) => {
            const folded = this.folded.has(section.dataset.moSection);
            section.classList.toggle('show', !folded);
            const toggle = form.querySelector(`[data-bs-target="#${CSS.escape(section.id)}"]`);
            toggle?.classList.toggle('collapsed', folded);
            toggle?.setAttribute('aria-expanded', String(!folded));
        });
    }

    /** A value kept in the browser, null when none or when the browser keeps nothing. */
    readStorage(key) {
        try {
            return JSON.parse(window.localStorage.getItem(key));
        } catch {
            return null;
        }
    }

    writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch {
            // Not kept: the sections are unfolded on the next page.
        }
    }

    /**
     * The preferences of the user, sent with each change by the fields of the toolbar (see
     * TreeEditor::setPreferences()): the tab is rendered with the default ones, so it is rendered
     * again with the ones kept in the browser, if they differ.
     */
    restorePreferences() {
        const stored = this.readStorage(this.constructor.PREFERENCES_KEY);
        const current = this.preferences;
        if (stored !== null && (stored._orientation !== current._orientation || stored._drawing_strategy !== current._drawing_strategy)) {
            this.send('render', { ...current, ...stored });
        }
    }

    /** The preferences of the user the editor is rendered with. */
    get preferences() {
        return { _orientation: this.form.dataset.moOrientation, _drawing_strategy: this.form.dataset.moDrawingStrategy };
    }

    /** Buttons, fields and search of the editor. */
    bindEditorEvents() {
        // Sections of the group list folded or unfolded
        ['shown.bs.collapse', 'hidden.bs.collapse'].forEach(type => this.container.addEventListener(type, (event) => {
            const key = event.target.dataset.moSection;
            if (key !== undefined) {
                if (type === 'hidden.bs.collapse') {
                    this.folded.add(key);
                } else {
                    this.folded.delete(key);
                }
                this.writeStorage(this.constructor.FOLDED_KEY, [...this.folded]);
            }
        }));

        this.container.addEventListener('click', (event) => {
            if (event.target.closest('[data-mo-fit]') !== null) {
                this.fit();
                return;
            }
            if (event.target.closest('[data-mo-undo]') !== null) {
                this.undo();
                return;
            }
            if (event.target.closest('[data-mo-redo]') !== null) {
                this.redo();
                return;
            }
            const trigger = event.target.closest('[data-mo-action]');
            // Fields send their change on "change"; a change with a confirmation is only sent once confirmed.
            if (trigger !== null && !trigger.matches('input')) {
                const { moAction, moGroup = '', moLink = '', moDirection = '', moConfirm } = trigger.dataset;
                const send = () => this.send(moAction, { group: moGroup, link: moLink, direction: moDirection });
                if (moConfirm === undefined) {
                    send();
                } else {
                    // The message of the dialog is HTML.
                    const message = document.createElement('p');
                    message.textContent = moConfirm;
                    glpi_confirm({ title: trigger.getAttribute('aria-label') ?? undefined, message: message.outerHTML, confirm_callback: send });
                }
            }
        });

        this.container.addEventListener('change', (event) => {
            if (event.target.matches('input[data-mo-action]')) {
                this.send(event.target.dataset.moAction, { value: event.target.value });
            }
        });

        // Filtering the groups while typing does not need the server.
        this.container.addEventListener('input', (event) => {
            if (!event.target.matches('[data-mo-search]')) {
                return;
            }
            const query = event.target.value.trim().toLowerCase();
            this.container.querySelectorAll('[data-mo-list]').forEach((list) => {
                const items = [...list.querySelectorAll('[data-mo-name]')];
                items.forEach((item) => { item.hidden = !item.dataset.moName.includes(query); });
                list.querySelector('[data-mo-list-empty]').hidden = items.some(item => !item.hidden);
            });
        });

        this.container.addEventListener('submit', event => event.preventDefault());
    }

    /**
     * Keyboard shortcuts of the editor, when the focus is in it (or nowhere), unless a field is
     * being typed in:
     * - Escape closes the panel of the selected group or link;
     * - Ctrl+Z (Cmd+Z) undoes, Ctrl+Y or Ctrl+Shift+Z (Cmd+Shift+Z) redoes;
     * - the Delete key (Backspace on Mac keyboards) removes the selected group or link: the one
     *   of the draft it is applied to, as the changes are sent one at a time.
     */
    bindKeyboard() {
        document.addEventListener('keydown', (event) => {
            // The tab was reloaded: this editor is gone.
            if (!this.container.isConnected) {
                this.destroy();
                return;
            }
            const focus = document.activeElement;
            if (
                event.defaultPrevented
                || event.isComposing
                || this.container.offsetParent === null
                || (focus !== null && focus !== document.body && !this.container.contains(focus))
                || event.target.closest('input, textarea, select, [contenteditable], .select2-container') !== null
            ) {
                return;
            }
            if (event.key === 'Escape' && this.hasSelection) {
                event.preventDefault();
                this.send('clear');
                return;
            }
            if (!this.canEdit) {
                return;
            }
            const key = event.key.toLowerCase();
            if ((event.ctrlKey || event.metaKey) && !event.altKey && ['z', 'y'].includes(key)) {
                event.preventDefault();
                if (key === 'y' || event.shiftKey) {
                    this.redo();
                } else {
                    this.undo();
                }
                return;
            }
            if (['Delete', 'Backspace'].includes(event.key) && (this.pending || this.hasSelection)) {
                event.preventDefault();
                this.send('delete_selection');
            }
        }, { signal: this.listeners.signal });
    }

    /** Dragging a group of the list onto the graph (`data-mo-drop-zone`) places it. */
    bindGroupDragAndDrop() {
        let dragged = null;
        // The drop zone under the pointer, while a group of the list is dragged
        const zoneOf = event => (dragged !== null ? event.target.closest('[data-mo-drop-zone]') : null);
        const end = () => {
            this.container.querySelectorAll('.mo-gl-dragging, .mo-gl-drop-target')
                .forEach(element => element.classList.remove('mo-gl-dragging', 'mo-gl-drop-target'));
            dragged = null;
        };

        this.container.addEventListener('dragstart', (event) => {
            const item = event.target.closest('.mo-gl-pool-item');
            if (item !== null) {
                dragged = item.dataset.moGroup;
                item.classList.add('mo-gl-dragging');
                event.dataTransfer.effectAllowed = 'copy';
                event.dataTransfer.setData('text/plain', item.textContent.trim());
            }
        });
        this.container.addEventListener('dragover', (event) => {
            const zone = zoneOf(event);
            if (zone !== null) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
                zone.classList.add('mo-gl-drop-target');
            }
        });
        this.container.addEventListener('dragleave', (event) => {
            const zone = zoneOf(event);
            if (zone !== null && !zone.contains(event.relatedTarget)) {
                zone.classList.remove('mo-gl-drop-target');
            }
        });
        this.container.addEventListener('drop', (event) => {
            if (zoneOf(event) !== null) {
                event.preventDefault();
                const group = dragged;
                end();
                this.send('add_node', { group });
            }
        });
        this.container.addEventListener('dragend', end);
    }
};
