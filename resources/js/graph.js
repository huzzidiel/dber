/**
 * Diagrama SVG con layout por capas, arrastre, zoom y pan.
 *
 * No usa librerías externas. Las posiciones se guardan en localStorage para
 * que recargar el esquema no desordene el diagrama.
 */

import { clear, clamp, formatDefault, svg } from './utils.js';

const NODE_MIN_WIDTH = 236;
const NODE_MAX_WIDTH = 392;
const HEADER_HEIGHT = 48;
const FIELD_HEIGHT = 20;
const NODE_BOTTOM_PADDING = 10;
const COLUMN_GAP = 132;
const ROW_GAP = 34;
const MIN_SCALE = 0.16;
const MAX_SCALE = 2.5;

export class Graph {
    constructor(options) {
        this.svgElement = options.svg;
        this.viewport = options.viewport;
        this.edgeLayer = options.edges;
        this.nodeLayer = options.nodes;
        this.onSelect = options.onSelect ?? (() => {});
        this.onEdit = options.onEdit ?? (() => {});
        this.onZoom = options.onZoom ?? (() => {});
        this.storageKey = options.storageKey ?? 'dber:layout';

        this.tables = [];
        this.byKey = new Map();
        this.relations = [];
        this.edgeEls = [];
        this.nodeEls = new Map();
        this.pivotSet = new Set();
        this.sizes = new Map();
        this.positions = new Map();
        this.selected = null;
        this.filter = '';
        this.showPivots = false;

        this.scale = 1;
        this.translateX = 0;
        this.translateY = 0;

        this.drag = null;
        this.pan = null;
        this.suppressClick = false;
        this.initialized = false;

        this.loadPositions();
        this.bindEvents();
    }

    setData({ tables, relations, pivotTables, selected, filter, showPivots, autoFit = false }) {
        const revealingPivots = showPivots === true && this.showPivots === false;

        this.tables = tables;
        this.byKey = new Map(tables.map((table) => [table.key, table]));
        this.relations = relations;
        this.pivotSet = new Set(pivotTables);
        this.selected = selected ?? this.selected;
        this.filter = filter ?? this.filter;
        this.showPivots = showPivots;

        const tableKeys = new Set(tables.map((table) => table.key));

        for (const key of [...this.positions.keys()]) {
            if (!tableKeys.has(key)) {
                this.positions.delete(key);
            }
        }

        if (revealingPivots) {
            for (const pivot of pivotTables) {
                this.positions.delete(pivot);
            }
        }

        this.computeSizes();
        this.ensurePositions();
        this.render();

        if (!this.initialized) {
            this.initialized = true;
            window.requestAnimationFrame(() => this.fit());
        } else if (autoFit) {
            this.fit();
        }

        this.applyFilter();
    }

    setSelected(name) {
        this.selected = name;

        for (const [key, element] of this.nodeEls) {
            element.classList.toggle('is-selected', key === name);
        }

        this.applyEdgeSelection();
    }

    setFilter(value) {
        this.filter = value;
        this.applyFilter();
    }

    visibleTables() {
        return this.tables.filter((table) => this.showPivots || !this.pivotSet.has(table.key));
    }

    computeSizes() {
        this.sizes.clear();

        for (const table of this.tables) {
            const model = shortModel(table.model);
            let longest = Math.max(table.key.length, model.length) + 6;

            for (const field of table.fields) {
                const type = typeText(field);
                longest = Math.max(longest, field.name.length + type.length + 8);
            }

            const width = clamp(longest * 6.35 + 72, NODE_MIN_WIDTH, NODE_MAX_WIDTH);
            const height = HEADER_HEIGHT + Math.max(1, table.fields.length) * FIELD_HEIGHT + NODE_BOTTOM_PADDING;

            this.sizes.set(table.key, {
                width: Math.round(width),
                height: Math.round(height),
            });
        }
    }

    ensurePositions() {
        const visible = this.visibleTables();
        const missing = visible.filter((table) => !this.positions.has(table.key));

        if (this.positions.size === 0 || visible.length > 0 && missing.length === visible.length) {
            this.autoLayout(true);
            return;
        }

        if (missing.length > 0) {
            this.placeMissing(missing);
            this.persistPositions();
        }
    }

    autoLayout(persist = true) {
        const visible = this.visibleTables();

        if (visible.length === 0) {
            this.positions.clear();
            return;
        }

        const keys = new Set(visible.map((table) => table.key));
        const parents = new Map();
        const children = new Map();

        for (const table of visible) {
            parents.set(table.key, new Set());
            children.set(table.key, new Set());
        }

        for (const relation of this.relations) {
            if (relation.kind === 'many-to-many') {
                continue;
            }

            if (!keys.has(relation.fromTable) || !keys.has(relation.toTable)) {
                continue;
            }

            if (relation.fromTable === relation.toTable) {
                continue;
            }

            parents.get(relation.fromTable).add(relation.toTable);
            children.get(relation.toTable).add(relation.fromTable);
        }

        const depth = new Map();
        const unresolved = new Map();
        const processed = new Set();
        const queue = [];

        for (const table of visible) {
            depth.set(table.key, 0);
            unresolved.set(table.key, new Set(parents.get(table.key)));

            if (parents.get(table.key).size === 0) {
                queue.push(table.key);
            }
        }

        while (processed.size < visible.length) {
            if (queue.length === 0) {
                const candidate = visible
                    .map((table) => table.key)
                    .filter((key) => !processed.has(key))
                    .sort((first, second) => unresolved.get(first).size - unresolved.get(second).size)[0];

                if (candidate === undefined) {
                    break;
                }

                let candidateDepth = 0;

                for (const parent of parents.get(candidate)) {
                    if (processed.has(parent)) {
                        candidateDepth = Math.max(candidateDepth, (depth.get(parent) ?? 0) + 1);
                    }
                }

                depth.set(candidate, Math.max(depth.get(candidate) ?? 0, candidateDepth));
                queue.push(candidate);
            }

            const key = queue.shift();

            if (key === undefined || processed.has(key)) {
                continue;
            }

            processed.add(key);

            for (const child of children.get(key) ?? []) {
                depth.set(child, Math.max(depth.get(child) ?? 0, (depth.get(key) ?? 0) + 1));
                unresolved.get(child)?.delete(key);

                if ((unresolved.get(child)?.size ?? 0) === 0 && !processed.has(child)) {
                    queue.push(child);
                }
            }
        }

        const columns = new Map();

        for (const table of visible) {
            const column = depth.get(table.key) ?? 0;

            if (!columns.has(column)) {
                columns.set(column, []);
            }

            columns.get(column).push(table);
        }

        const ordered = [...columns.keys()].sort((first, second) => first - second);

        for (const column of ordered) {
            columns.get(column).sort((first, second) => first.key.localeCompare(second.key));
        }

        const columnWidths = new Map();

        for (const column of ordered) {
            columnWidths.set(
                column,
                Math.max(...columns.get(column).map((table) => this.sizes.get(table.key).width)),
            );
        }

        const positions = new Map();
        const yPositions = new Map();

        for (const column of ordered) {
            assignColumnY(columns.get(column), this.sizes, yPositions);
        }

        for (let pass = 0; pass < 4; pass += 1) {
            const leftToRight = pass % 2 === 0;

            if (leftToRight) {
                for (const column of ordered) {
                    sortByNeighbors(columns.get(column), parents, yPositions, true);
                    assignColumnY(columns.get(column), this.sizes, yPositions);
                }
            } else {
                for (const column of [...ordered].reverse()) {
                    sortByNeighbors(columns.get(column), children, yPositions, false);
                    assignColumnY(columns.get(column), this.sizes, yPositions);
                }
            }
        }

        let x = 0;

        for (const column of ordered) {
            const columnWidth = columnWidths.get(column);

            for (const table of columns.get(column)) {
                const size = this.sizes.get(table.key);
                positions.set(table.key, {
                    x: x + (columnWidth - size.width) / 2,
                    y: yPositions.get(table.key) ?? 0,
                });
            }

            x += columnWidth + COLUMN_GAP;
        }

        for (const [key, position] of positions) {
            this.positions.set(key, position);
        }

        if (persist) {
            this.persistPositions();
        }
    }

    placeMissing(tables) {
        const gap = COLUMN_GAP / 2;

        for (const table of tables) {
            const size = this.sizes.get(table.key);
            const parentRelation = this.relations.find(
                (relation) => relation.kind !== 'many-to-many'
                    && relation.fromTable === table.key
                    && this.positions.has(relation.toTable),
            );
            const childRelation = this.relations.find(
                (relation) => relation.kind !== 'many-to-many'
                    && relation.toTable === table.key
                    && this.positions.has(relation.fromTable),
            );

            let anchor = null;
            let direction = 1;

            if (parentRelation) {
                const parent = this.byKey.get(parentRelation.toTable);
                const parentSize = this.sizes.get(parent.key);
                const parentPosition = this.positions.get(parent.key);

                anchor = {
                    x: parentPosition.x + parentSize.width + gap,
                    y: parentPosition.y,
                };
                direction = 1;
            } else if (childRelation) {
                const child = this.byKey.get(childRelation.fromTable);
                const childPosition = this.positions.get(child.key);

                anchor = {
                    x: childPosition.x - size.width - gap,
                    y: childPosition.y,
                };
                direction = -1;
            } else {
                anchor = {
                    x: this.maxX() + gap,
                    y: 0,
                };
            }

            const position = this.findFreeSpot(anchor, size, direction);
            this.positions.set(table.key, position);
        }
    }

    findFreeSpot(anchor, size, direction) {
        const existing = [];

        for (const [key, position] of this.positions) {
            const currentSize = this.sizes.get(key);

            if (currentSize) {
                existing.push({
                    x: position.x,
                    y: position.y,
                    width: currentSize.width,
                    height: currentSize.height,
                });
            }
        }

        for (let attempt = 0; attempt < 120; attempt += 1) {
            const candidate = {
                x: anchor.x + (direction < 0 && attempt > 0 ? -attempt * 24 : 0),
                y: anchor.y + attempt * (size.height + ROW_GAP),
            };
            const rect = {
                ...candidate,
                width: size.width,
                height: size.height,
            };
            const collision = existing.some((current) => overlaps(rect, current, 18));

            if (!collision) {
                return candidate;
            }
        }

        return anchor;
    }

    maxX() {
        let max = 0;

        for (const [key, position] of this.positions) {
            const size = this.sizes.get(key);

            if (size) {
                max = Math.max(max, position.x + size.width);
            }
        }

        return max;
    }

    render() {
        clear(this.edgeLayer);
        clear(this.nodeLayer);

        this.edgeEls = [];
        this.nodeEls = new Map();

        const visible = this.visibleTables();
        const visibleKeys = new Set(visible.map((table) => table.key));
        const relations = this.relations.filter((relation) => {
            if (relation.kind === 'many-to-many') {
                return !this.showPivots && visibleKeys.has(relation.fromTable) && visibleKeys.has(relation.toTable);
            }

            return visibleKeys.has(relation.fromTable) && visibleKeys.has(relation.toTable);
        });

        for (const relation of relations) {
            this.renderEdge(relation);
        }

        for (const table of visible) {
            this.renderNode(table);
        }

        this.positionEdges();
        this.applyFilter();
        this.applyEdgeSelection();
    }

    renderNode(table) {
        const size = this.sizes.get(table.key);
        const position = this.positions.get(table.key);
        const x = position.x;
        const y = position.y;
        const width = size.width;
        const hue = tableHueValue(table.key);

        const group = svg('g', {
            class: `dber-node${table.pivot ? ' is-pivot' : ''}${table.key === this.selected ? ' is-selected' : ''}`,
            transform: `translate(${x} ${y})`,
            'data-table': table.key,
        });

        const titleParts = [table.key];

        if (table.description) {
            titleParts.push(table.description);
        }

        if (table.primaryKey.length > 0) {
            titleParts.push(`Primary key: ${table.primaryKey.join(', ')}`);
        }

        group.appendChild(svg('title', { text: titleParts.join('\n') }));

        group.appendChild(svg('rect', {
            class: 'dber-node-bg',
            width,
            height: size.height,
            rx: 11,
            ry: 11,
        }));

        group.appendChild(svg('path', {
            class: 'dber-node-header',
            d: headerPath(width, HEADER_HEIGHT, 11),
        }));

        group.appendChild(svg('rect', {
            x: 1.5,
            y: 14,
            width: 3,
            height: HEADER_HEIGHT - 26,
            rx: 1.5,
            fill: `hsl(${hue} 62% 62%)`,
            opacity: 0.85,
        }));

        group.appendChild(svg('text', {
            class: 'dber-node-title',
            x: 14,
            y: 20,
            text: truncate(table.key, Math.floor((width - 90) / 7)),
        }));

        group.appendChild(svg('text', {
            class: 'dber-node-badge',
            x: width - 13,
            y: 19,
            text: `${table.fields.length} fields`,
            'text-anchor': 'end',
        }));

        const model = shortModel(table.model);
        group.appendChild(svg('text', {
            class: 'dber-node-model',
            x: 14,
            y: 35,
            text: truncate(model || 'no model', Math.floor((width - 100) / 5.6)),
        }));

        if (table.pivot) {
            group.appendChild(svg('text', {
                class: 'dber-node-badge is-pivot',
                x: width - 13,
                y: 35,
                text: 'PIVOT',
                'text-anchor': 'end',
            }));
        }

        group.appendChild(svg('line', {
            class: 'dber-node-divider',
            x1: 0,
            y1: HEADER_HEIGHT,
            x2: width,
            y2: HEADER_HEIGHT,
        }));

        if (table.fields.length === 0) {
            group.appendChild(svg('text', {
                class: 'dber-node-footer',
                x: width / 2,
                y: HEADER_HEIGHT + 24,
                text: 'No fields',
            }));
        }

        table.fields.forEach((field, index) => {
            const rowY = HEADER_HEIGHT + 9 + index * FIELD_HEIGHT;

            if (field.primary) {
                group.appendChild(svg('text', {
                    class: 'dber-field-key is-pk',
                    x: 11,
                    y: rowY + 10,
                    text: 'PK',
                }));
            }

            if (field.references) {
                group.appendChild(svg('text', {
                    class: 'dber-field-key is-fk',
                    x: field.primary ? 29 : 11,
                    y: rowY + 10,
                    text: 'FK',
                }));
            }

            group.appendChild(svg('text', {
                class: `dber-field-name${field.references ? ' is-fk' : ''}`,
                x: 46,
                y: rowY + 10,
                text: truncate(field.name, Math.floor((width - 160) / 6.4)),
            }));

            group.appendChild(svg('text', {
                class: 'dber-field-type',
                x: width - 12,
                y: rowY + 10,
                text: truncate(typeText(field), Math.floor((width - 120) / 5.6)),
            }));
        });

        group.addEventListener('pointerdown', (event) => this.beginNodeDrag(event, table.key));
        group.addEventListener('click', (event) => {
            event.stopPropagation();

            if (this.suppressClick) {
                this.suppressClick = false;
                return;
            }

            this.onSelect(table.key);
        });
        group.addEventListener('dblclick', (event) => {
            event.stopPropagation();
            this.onEdit(table.key);
        });

        this.nodeLayer.appendChild(group);
        this.nodeEls.set(table.key, group);
    }

    renderEdge(relation) {
        const group = svg('g', {
            class: `dber-edge${relation.kind === 'many-to-many' ? ' is-many-to-many' : ''}`,
            'data-relation': relation.id,
        });

        const hit = svg('path', {
            class: 'dber-edge-hit',
        });

        const line = svg('path', {
            class: 'dber-edge-line',
        });

        const marker = svg('path', {
            class: 'dber-edge-marker',
        });

        const labelGroup = svg('g', {
            class: 'dber-edge-label-group',
        });

        const labelBg = svg('rect', {
            class: 'dber-edge-label-bg',
            rx: 4,
            ry: 4,
        });

        const label = svg('text', {
            class: 'dber-edge-label',
            text: relation.kind === 'many-to-many' ? relation.pivotTable : relation.fromField,
        });

        labelGroup.appendChild(labelBg);
        labelGroup.appendChild(label);

        group.appendChild(hit);
        group.appendChild(line);
        group.appendChild(marker);
        group.appendChild(labelGroup);

        hit.addEventListener('click', (event) => {
            event.stopPropagation();
            this.onSelect(relation.fromTable);
        });

        group.addEventListener('mouseenter', () => {
            group.classList.add('is-active');
        });

        group.addEventListener('mouseleave', () => {
            if (!this.isEdgeSelected(relation)) {
                group.classList.remove('is-active');
            }
        });

        this.edgeLayer.appendChild(group);
        this.edgeEls.push({
            relation,
            group,
            hit,
            line,
            marker,
            labelGroup,
            labelBg,
            label,
        });
    }

    positionEdges() {
        for (const edge of this.edgeEls) {
            const fromPosition = this.positions.get(edge.relation.fromTable);
            const toPosition = this.positions.get(edge.relation.toTable);
            const fromSize = this.sizes.get(edge.relation.fromTable);
            const toSize = this.sizes.get(edge.relation.toTable);

            if (!fromPosition || !toPosition || !fromSize || !toSize) {
                continue;
            }

            const fromRect = {
                x: fromPosition.x,
                y: fromPosition.y,
                width: fromSize.width,
                height: fromSize.height,
            };
            const toRect = {
                x: toPosition.x,
                y: toPosition.y,
                width: toSize.width,
                height: toSize.height,
            };

            if (edge.relation.fromTable === edge.relation.toTable) {
                const right = fromRect.x + fromRect.width;
                const centerY = fromRect.y + fromRect.height / 2;
                const start = {
                    x: right,
                    y: centerY - 26,
                };
                const end = {
                    x: right,
                    y: centerY + 26,
                };
                const control = right + 90;
                const path = `M ${start.x} ${start.y} C ${control} ${start.y}, ${control} ${end.y}, ${end.x} ${end.y}`;
                const labelText = edge.relation.kind === 'many-to-many'
                    ? edge.relation.pivotTable
                    : edge.relation.fromField;
                const labelWidth = Math.max(30, labelText.length * 5.2 + 10);
                const labelX = right + 98;

                edge.line.setAttribute('d', path);
                edge.hit.setAttribute('d', path);
                edge.marker.setAttribute('d', this.markerPath(edge.relation, start, end));
                edge.label.setAttribute('x', labelX);
                edge.label.setAttribute('y', centerY + 0.5);
                edge.labelBg.setAttribute('x', labelX - labelWidth / 2);
                edge.labelBg.setAttribute('y', centerY - 7.5);
                edge.labelBg.setAttribute('width', labelWidth);
                edge.labelBg.setAttribute('height', 15);
                continue;
            }

            const fromCenter = {
                x: fromRect.x + fromRect.width / 2,
                y: fromRect.y + fromRect.height / 2,
            };
            const toCenter = {
                x: toRect.x + toRect.width / 2,
                y: toRect.y + toRect.height / 2,
            };

            const fromAnchor = rectAnchor(fromRect, toCenter);
            const toAnchor = rectAnchor(toRect, fromCenter);
            const path = edgePath(fromAnchor.point, toAnchor.point);
            const midpoint = bezierMidpoint(fromAnchor.point, toAnchor.point);

            edge.line.setAttribute('d', path);
            edge.hit.setAttribute('d', path);
            edge.marker.setAttribute('d', this.markerPath(edge.relation, fromAnchor.point, toAnchor.point));

            const labelText = edge.relation.kind === 'many-to-many'
                ? edge.relation.pivotTable
                : edge.relation.fromField;
            const labelWidth = Math.max(30, labelText.length * 5.2 + 10);

            edge.label.setAttribute('x', midpoint.x);
            edge.label.setAttribute('y', midpoint.y + 0.5);
            edge.labelBg.setAttribute('x', midpoint.x - labelWidth / 2);
            edge.labelBg.setAttribute('y', midpoint.y - 7.5);
            edge.labelBg.setAttribute('width', labelWidth);
            edge.labelBg.setAttribute('height', 15);
        }
    }

    markerPath(relation, fromPoint, toPoint) {
        const dx = toPoint.x - fromPoint.x;
        const dy = toPoint.y - fromPoint.y;
        const length = Math.hypot(dx, dy) || 1;
        const direction = { x: dx / length, y: dy / length };
        const perpendicular = { x: -direction.y, y: direction.x };
        const paths = [];

        const manyAtFrom = relation.kind !== 'one-to-one';
        const manyAtTo = relation.kind === 'many-to-many';
        const oneAtFrom = relation.kind === 'one-to-one';
        const oneAtTo = relation.kind !== 'many-to-many';

        if (manyAtFrom) {
            paths.push(crowsFoot(fromPoint, direction, perpendicular));
        }

        if (manyAtTo) {
            paths.push(crowsFoot(toPoint, { x: -direction.x, y: -direction.y }, perpendicular));
        }

        if (oneAtFrom) {
            paths.push(tick(fromPoint, direction, perpendicular, 10));
        }

        if (oneAtTo) {
            paths.push(tick(toPoint, { x: -direction.x, y: -direction.y }, perpendicular, 10));
        }

        return paths.join(' ');
    }

    applyFilter() {
        const query = this.filter.trim().toLowerCase();

        for (const [key, element] of this.nodeEls) {
            const table = this.byKey.get(key);
            const matches = query === '' || matchesTable(table, query);

            element.classList.toggle('is-match', query !== '' && matches);
            element.classList.toggle('is-dim', query !== '' && !matches);
        }

        for (const edge of this.edgeEls) {
            const from = this.byKey.get(edge.relation.fromTable);
            const to = this.byKey.get(edge.relation.toTable);
            const matches = query === ''
                || (from && matchesTable(from, query))
                || (to && matchesTable(to, query));

            edge.group.classList.toggle('is-dim', query !== '' && !matches);
        }
    }

    applyEdgeSelection() {
        for (const edge of this.edgeEls) {
            edge.group.classList.toggle('is-active', this.isEdgeSelected(edge.relation));
        }
    }

    isEdgeSelected(relation) {
        return this.selected !== null
            && (relation.fromTable === this.selected || relation.toTable === this.selected);
    }

    beginNodeDrag(event, key) {
        if (event.button !== 0 || this.pan !== null) {
            return;
        }

        event.stopPropagation();

        const position = this.positions.get(key);

        if (!position) {
            return;
        }

        this.drag = {
            pointerId: event.pointerId,
            key,
            startX: event.clientX,
            startY: event.clientY,
            originX: position.x,
            originY: position.y,
            moved: false,
        };

        this.svgElement.setPointerCapture(event.pointerId);
        this.svgElement.classList.add('is-dragging');
        this.nodeEls.get(key)?.classList.add('is-dragging');
    }

    beginPan(event) {
        if (event.button !== 0) {
            return;
        }

        this.pan = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            translateX: this.translateX,
            translateY: this.translateY,
            moved: false,
        };

        this.svgElement.setPointerCapture(event.pointerId);
        this.svgElement.classList.add('is-panning');
    }

    bindEvents() {
        this.svgElement.addEventListener('pointerdown', (event) => {
            if (event.target.closest('.dber-node')) {
                return;
            }

            if (event.target.closest('.dber-edge')) {
                return;
            }

            this.beginPan(event);
        });

        this.svgElement.addEventListener('pointermove', (event) => {
            if (this.drag !== null && event.pointerId === this.drag.pointerId) {
                const dx = (event.clientX - this.drag.startX) / this.scale;
                const dy = (event.clientY - this.drag.startY) / this.scale;

                if (Math.abs(dx) + Math.abs(dy) > 2) {
                    this.drag.moved = true;
                }

                if (!this.drag.moved) {
                    return;
                }

                const position = {
                    x: this.drag.originX + dx,
                    y: this.drag.originY + dy,
                };

                this.positions.set(this.drag.key, position);
                const element = this.nodeEls.get(this.drag.key);

                if (element) {
                    element.setAttribute('transform', `translate(${position.x} ${position.y})`);
                }

                this.positionEdges();
                return;
            }

            if (this.pan !== null && event.pointerId === this.pan.pointerId) {
                const dx = event.clientX - this.pan.startX;
                const dy = event.clientY - this.pan.startY;

                if (Math.abs(dx) + Math.abs(dy) > 2) {
                    this.pan.moved = true;
                }

                this.translateX = this.pan.translateX + dx;
                this.translateY = this.pan.translateY + dy;
                this.applyTransform();
            }
        });

        const finishPointer = (event) => {
            if (this.drag !== null && event.pointerId === this.drag.pointerId) {
                if (this.drag.moved) {
                    this.suppressClick = true;
                    window.setTimeout(() => {
                        this.suppressClick = false;
                    }, 60);
                    this.persistPositions();
                }

                this.nodeEls.get(this.drag.key)?.classList.remove('is-dragging');
                this.drag = null;
                this.svgElement.classList.remove('is-dragging');
                this.svgElement.releasePointerCapture?.(event.pointerId);
            }

            if (this.pan !== null && event.pointerId === this.pan.pointerId) {
                if (!this.pan.moved) {
                    this.onSelect(null);
                }

                this.pan = null;
                this.svgElement.classList.remove('is-panning');
                this.svgElement.releasePointerCapture?.(event.pointerId);
            }
        };

        this.svgElement.addEventListener('pointerup', finishPointer);
        this.svgElement.addEventListener('pointercancel', finishPointer);

        this.svgElement.addEventListener('wheel', (event) => {
            event.preventDefault();

            const rect = this.svgElement.getBoundingClientRect();
            const cursorX = event.clientX - rect.left;
            const cursorY = event.clientY - rect.top;
            const worldX = (cursorX - this.translateX) / this.scale;
            const worldY = (cursorY - this.translateY) / this.scale;
            const factor = Math.exp(-event.deltaY * 0.0015);
            const nextScale = clamp(this.scale * factor, MIN_SCALE, MAX_SCALE);

            this.translateX = cursorX - worldX * nextScale;
            this.translateY = cursorY - worldY * nextScale;
            this.scale = nextScale;
            this.applyTransform();
        }, { passive: false });

        this.svgElement.addEventListener('dblclick', (event) => {
            if (!event.target.closest('.dber-node') && !event.target.closest('.dber-edge')) {
                this.fit();
            }
        });
    }

    applyTransform() {
        this.viewport.setAttribute(
            'transform',
            `translate(${this.translateX} ${this.translateY}) scale(${this.scale})`,
        );
        this.onZoom(Math.round(this.scale * 100));
    }

    fit() {
        const visible = this.visibleTables().filter((table) => this.positions.has(table.key));

        if (visible.length === 0) {
            this.scale = 1;
            this.translateX = 0;
            this.translateY = 0;
            this.applyTransform();
            return;
        }

        let minX = Number.POSITIVE_INFINITY;
        let minY = Number.POSITIVE_INFINITY;
        let maxX = Number.NEGATIVE_INFINITY;
        let maxY = Number.NEGATIVE_INFINITY;

        for (const table of visible) {
            const position = this.positions.get(table.key);
            const size = this.sizes.get(table.key);

            if (!position || !size) {
                continue;
            }

            minX = Math.min(minX, position.x);
            minY = Math.min(minY, position.y);
            maxX = Math.max(maxX, position.x + size.width);
            maxY = Math.max(maxY, position.y + size.height);
        }

        const rect = this.svgElement.getBoundingClientRect();
        const width = rect.width || 1000;
        const height = rect.height || 700;
        const padding = 74;
        const boundsWidth = Math.max(1, maxX - minX);
        const boundsHeight = Math.max(1, maxY - minY);
        const scale = clamp(
            Math.min((width - padding * 2) / boundsWidth, (height - padding * 2) / boundsHeight),
            MIN_SCALE,
            1.25,
        );

        this.scale = scale;
        this.translateX = width / 2 - (minX + boundsWidth / 2) * scale;
        this.translateY = height / 2 - (minY + boundsHeight / 2) * scale;
        this.applyTransform();
    }

    zoomIn() {
        this.zoomAtCenter(1.18);
    }

    zoomOut() {
        this.zoomAtCenter(1 / 1.18);
    }

    zoomAtCenter(factor) {
        const rect = this.svgElement.getBoundingClientRect();
        const width = rect.width || 1000;
        const height = rect.height || 700;
        const worldX = (width / 2 - this.translateX) / this.scale;
        const worldY = (height / 2 - this.translateY) / this.scale;
        const nextScale = clamp(this.scale * factor, MIN_SCALE, MAX_SCALE);

        this.translateX = width / 2 - worldX * nextScale;
        this.translateY = height / 2 - worldY * nextScale;
        this.scale = nextScale;
        this.applyTransform();
    }

    revealTable(key) {
        const position = this.positions.get(key);
        const size = this.sizes.get(key);

        if (!position || !size) {
            return;
        }

        const rect = this.svgElement.getBoundingClientRect();
        const width = rect.width || 1000;
        const height = rect.height || 700;
        const screenX = this.translateX + (position.x + size.width / 2) * this.scale;
        const screenY = this.translateY + (position.y + size.height / 2) * this.scale;
        const margin = 110;

        if (screenX >= margin && screenX <= width - margin && screenY >= margin && screenY <= height - margin) {
            return;
        }

        this.translateX = width / 2 - (position.x + size.width / 2) * this.scale;
        this.translateY = height / 2 - (position.y + size.height / 2) * this.scale;
        this.applyTransform();
    }

    resetLayout() {
        this.positions.clear();
        this.removePositions();
        this.autoLayout();
        this.render();
        this.fit();
    }

    renamePosition(from, to) {
        const position = this.positions.get(from);

        if (!position) {
            return;
        }

        this.positions.set(to, { ...position });
        this.persistPositions();
    }

    persistPositions() {
        try {
            const data = {};

            for (const [key, position] of this.positions) {
                data[key] = position;
            }

            window.localStorage.setItem(this.storageKey, JSON.stringify(data));
        } catch {
            // localStorage puede estar deshabilitado; el diagrama sigue funcionando.
        }
    }

    loadPositions() {
        try {
            const raw = window.localStorage.getItem(this.storageKey);

            if (!raw) {
                return;
            }

            const data = JSON.parse(raw);

            if (!data || typeof data !== 'object') {
                return;
            }

            for (const [key, position] of Object.entries(data)) {
                if (
                    position
                    && typeof position === 'object'
                    && Number.isFinite(position.x)
                    && Number.isFinite(position.y)
                ) {
                    this.positions.set(key, {
                        x: Number(position.x),
                        y: Number(position.y),
                    });
                }
            }
        } catch {
            this.positions.clear();
        }
    }

    removePositions() {
        try {
            window.localStorage.removeItem(this.storageKey);
        } catch {
            // Ignorar.
        }
    }
}

function assignColumnY(tables, sizes, yPositions) {
    const totalHeight = tables.reduce((total, table) => total + sizes.get(table.key).height, 0)
        + Math.max(0, tables.length - 1) * ROW_GAP;
    let cursor = -totalHeight / 2;

    for (const table of tables) {
        yPositions.set(table.key, cursor);
        cursor += sizes.get(table.key).height + ROW_GAP;
    }
}

function sortByNeighbors(tables, neighborMap, yPositions, parents) {
    const scores = new Map();

    for (const table of tables) {
        const neighbors = [...(neighborMap.get(table.key) ?? [])];
        const positions = neighbors
            .map((neighbor) => yPositions.get(neighbor))
            .filter((value) => Number.isFinite(value));

        scores.set(
            table.key,
            positions.length === 0
                ? (yPositions.get(table.key) ?? 0)
                : positions.reduce((total, value) => total + value, 0) / positions.length,
        );
    }

    tables.sort((first, second) => {
        const difference = scores.get(first.key) - scores.get(second.key);

        if (difference === 0) {
            return parents
                ? first.key.localeCompare(second.key)
                : second.key.localeCompare(first.key);
        }

        return difference;
    });
}

function overlaps(first, second, gap) {
    return !(
        first.x + first.width + gap < second.x
        || second.x + second.width + gap < first.x
        || first.y + first.height + gap < second.y
        || second.y + second.height + gap < first.y
    );
}

function headerPath(width, height, radius) {
    return [
        `M 0 ${radius}`,
        `Q 0 0 ${radius} 0`,
        `H ${width - radius}`,
        `Q ${width} 0 ${width} ${radius}`,
        `V ${height}`,
        'H 0',
        'Z',
    ].join(' ');
}

function rectAnchor(rect, otherCenter) {
    const center = {
        x: rect.x + rect.width / 2,
        y: rect.y + rect.height / 2,
    };
    const dx = otherCenter.x - center.x;
    const dy = otherCenter.y - center.y;

    if (Math.abs(dx) >= Math.abs(dy)) {
        const x = dx >= 0 ? rect.x + rect.width : rect.x;
        const slope = dx === 0 ? 0 : dy / dx;
        const y = clamp(center.y + (x - center.x) * slope, rect.y + 12, rect.y + rect.height - 12);

        return { point: { x, y } };
    }

    const y = dy >= 0 ? rect.y + rect.height : rect.y;
    const slope = dy === 0 ? 0 : dx / dy;
    const x = clamp(center.x + (y - center.y) * slope, rect.x + 12, rect.x + rect.width - 12);

    return { point: { x, y } };
}

function edgePath(from, to) {
    const dx = to.x - from.x;
    const dy = to.y - from.y;

    if (Math.abs(dx) >= Math.abs(dy)) {
        const control = dx / 2;

        return `M ${from.x} ${from.y} C ${from.x + control} ${from.y}, ${to.x - control} ${to.y}, ${to.x} ${to.y}`;
    }

    const control = dy / 2;

    return `M ${from.x} ${from.y} C ${from.x} ${from.y + control}, ${to.x} ${to.y - control}, ${to.x} ${to.y}`;
}

function bezierMidpoint(from, to) {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const horizontal = Math.abs(dx) >= Math.abs(dy);
    const first = horizontal
        ? { x: from.x + dx / 2, y: from.y }
        : { x: from.x, y: from.y + dy / 2 };
    const second = horizontal
        ? { x: to.x - dx / 2, y: to.y }
        : { x: to.x, y: to.y - dy / 2 };

    return {
        x: 0.125 * from.x + 0.375 * first.x + 0.375 * second.x + 0.125 * to.x,
        y: 0.125 * from.y + 0.375 * first.y + 0.375 * second.y + 0.125 * to.y,
    };
}

function crowsFoot(point, direction, perpendicular) {
    const depth = 14;
    const spread = 8;
    const center = {
        x: point.x + direction.x * depth,
        y: point.y + direction.y * depth,
    };

    return [
        `M ${point.x} ${point.y} L ${center.x + perpendicular.x * spread} ${center.y + perpendicular.y * spread}`,
        `M ${point.x} ${point.y} L ${center.x} ${center.y}`,
        `M ${point.x} ${point.y} L ${center.x - perpendicular.x * spread} ${center.y - perpendicular.y * spread}`,
    ].join(' ');
}

function tick(point, direction, perpendicular, distance) {
    const x = point.x + direction.x * distance;
    const y = point.y + direction.y * distance;

    return `M ${x + perpendicular.x * 6} ${y + perpendicular.y * 6} L ${x - perpendicular.x * 6} ${y - perpendicular.y * 6}`;
}

function typeText(field) {
    const parts = [];

    if (field.nullable) {
        parts.push('?');
    }

    parts.push(field.typeLabel);

    if (field.hasDefault) {
        parts.push(`= ${formatDefault(field.default, true)}`);
    }

    return parts.join(' ');
}

function shortModel(model) {
    if (!model) {
        return '';
    }

    const parts = model.split('\\');

    return parts[parts.length - 1] ?? model;
}

function truncate(value, length) {
    if (length <= 1 || value.length <= length) {
        return value;
    }

    return `${value.slice(0, Math.max(1, length - 1))}…`;
}

function matchesTable(table, query) {
    if (table.key.toLowerCase().includes(query) || table.model.toLowerCase().includes(query)) {
        return true;
    }

    return table.fields.some((field) => {
        if (field.name.toLowerCase().includes(query) || field.type.toLowerCase().includes(query)) {
            return true;
        }

        if (field.typeLabel.toLowerCase().includes(query)) {
            return true;
        }

        return field.references !== null && field.references.table.toLowerCase().includes(query);
    });
}

function tableHueValue(name) {
    let hash = 0;

    for (let index = 0; index < name.length; index += 1) {
        hash = (hash * 31 + name.charCodeAt(index)) % 360;
    }

    return hash;
}
