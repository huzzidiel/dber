/**
 * Estado editable del documento, historial y operaciones sobre tablas.
 *
 * Las mutaciones estructurales re-renderizan el inspector; los cambios de
 * texto solo actualizan el grafo para no perder el foco del input.
 */

import { clone, fkName, isLifecycleField, isSafeName, isSnakeCase, LIFECYCLE_FIELDS, pivotName, singularize, studly } from './utils.js';
import { derive, lint } from './derive.js';

const EMPTY_DOCUMENT = {
    $schema: './schema.json',
    schema_version: 1,
    name: 'Laravel application',
    tables: {},
};

export class Store {
    constructor() {
        this.document = clone(EMPTY_DOCUMENT);
        this.hash = null;
        this.path = '';
        this.exists = false;
        this.editable = true;
        this.serverIssues = [];
        this.dirty = false;
        this.conflict = false;
        this.selected = null;
        this.showPivots = false;
        this.filter = '';
        this.history = [];
        this.future = [];
        this.listeners = new Set();
        this.lastEditKey = null;
        this.lastEditAt = 0;
        this.loadedAt = null;
    }

    subscribe(listener) {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }

    emit(type, detail = {}) {
        for (const listener of this.listeners) {
            listener(type, detail);
        }
    }

    derived() {
        return derive(this.document);
    }

    clientIssues() {
        return lint(this.document);
    }

    table(name) {
        return this.document.tables?.[name] ?? null;
    }

    selectedTable() {
        return this.selected === null ? null : this.table(this.selected);
    }

    setLoaded(data, source = 'load') {
        const document = data?.document;

        if (document && typeof document === 'object' && !Array.isArray(document)) {
            this.document = document;
        } else {
            this.document = clone(EMPTY_DOCUMENT);
        }

        if (!this.document.tables || typeof this.document.tables !== 'object' || Array.isArray(this.document.tables)) {
            this.document.tables = {};
        }

        for (const table of Object.values(this.document.tables)) {
            orderTableFields(table);
        }

        this.hash = typeof data?.hash === 'string' ? data.hash : null;
        this.path = typeof data?.path === 'string' ? data.path : this.path;
        this.exists = data?.exists === true;
        this.editable = data?.editable !== false;
        this.serverIssues = Array.isArray(data?.issues) ? data.issues : [];
        this.dirty = false;
        this.conflict = false;
        this.history = [];
        this.future = [];
        this.lastEditKey = null;
        this.lastEditAt = 0;
        this.loadedAt = data?.generated_at ?? new Date().toISOString();

        if (this.selected !== null && !this.table(this.selected)) {
            this.selected = null;
        }

        this.emit('load', { source });
    }

    applySaved(data) {
        this.setLoaded(data, 'save');
        this.emit('saved');
    }

    setSelected(name) {
        const next = name !== null && this.table(name) ? name : null;

        if (next === this.selected) {
            return;
        }

        this.selected = next;
        this.emit('selection');
    }

    setFilter(value) {
        this.filter = value;
        this.emit('filter');
    }

    setShowPivots(value) {
        this.showPivots = value;
        this.emit('filter');
    }

    markClean(hash = this.hash) {
        this.hash = hash;
        this.dirty = false;
        this.conflict = false;
        this.emit('clean');
    }

    markConflict() {
        this.conflict = true;
        this.emit('conflict');
    }

    pushHistory() {
        this.history.push(clone(this.document));

        if (this.history.length > 60) {
            this.history.shift();
        }

        this.future = [];
        this.lastEditKey = null;
        this.lastEditAt = 0;
    }

    edit(key, mutate, options = {}) {
        const now = Date.now();

        if (this.lastEditKey !== key || now - this.lastEditAt > 700) {
            this.pushHistory();
        }

        this.lastEditKey = key;
        this.lastEditAt = now;

        mutate();
        this.dirty = true;

        this.emit(options.structural ? 'structure' : 'edit', {
            key,
            source: 'editor',
        });
    }

    mutate(mutate, options = {}) {
        this.pushHistory();
        mutate();
        this.dirty = true;
        this.emit(options.structural === false ? 'edit' : 'structure', {
            source: 'editor',
            ...(options.detail ?? {}),
        });
    }

    undo() {
        if (this.history.length === 0) {
            return;
        }

        this.future.push(clone(this.document));
        this.document = this.history.pop();
        this.dirty = true;
        this.restoreSelection();
        this.emit('history');
    }

    redo() {
        if (this.future.length === 0) {
            return;
        }

        this.history.push(clone(this.document));
        this.document = this.future.pop();
        this.dirty = true;
        this.restoreSelection();
        this.emit('history');
    }

    restoreSelection() {
        if (this.selected !== null && !this.table(this.selected)) {
            this.selected = null;
        }
    }

    addTable() {
        const name = this.uniqueTableName('new_table');

        this.mutate(() => {
            this.document.tables[name] = {
                primary_key: [
                    'id',
                ],
                fields: {
                    id: {
                        type: 'bigint',
                        unsigned: true,
                        auto_increment: true,
                    },
                    created_at: {
                        type: 'timestamp',
                        nullable: true,
                    },
                    updated_at: {
                        type: 'timestamp',
                        nullable: true,
                    },
                    deleted_at: {
                        type: 'timestamp',
                        nullable: true,
                    },
                },
            };
            this.selected = name;
        });

        return name;
    }

    deleteTable(name) {
        if (!this.table(name)) {
            return;
        }

        this.mutate(() => {
            delete this.document.tables[name];

            for (const table of Object.values(this.document.tables)) {
                if (!table?.fields || typeof table.fields !== 'object') {
                    continue;
                }

                for (const field of Object.values(table.fields)) {
                    if (field?.references?.table === name) {
                        delete field.references;
                    }
                }
            }

            if (this.selected === name) {
                this.selected = null;
            }
        });
    }

    renameTable(oldName, newName) {
        if (oldName === newName) {
            return oldName;
        }

        if (!isSafeName(newName) || !isSnakeCase(newName)) {
            throw new Error('Table names must use snake_case and start with a letter.');
        }

        if (this.table(newName)) {
            throw new Error(`The table "${newName}" already exists.`);
        }

        const table = this.table(oldName);

        if (!table) {
            throw new Error('The table no longer exists.');
        }

        this.mutate(() => {
            const tables = {};

            for (const [name, value] of Object.entries(this.document.tables)) {
                tables[name === oldName ? newName : name] = value;
            }

            this.document.tables = tables;

            for (const otherTable of Object.values(this.document.tables)) {
                if (!otherTable?.fields || typeof otherTable.fields !== 'object') {
                    continue;
                }

                for (const field of Object.values(otherTable.fields)) {
                    if (field?.references?.table === oldName) {
                        field.references.table = newName;
                    }
                }
            }

            if (this.selected === oldName) {
                this.selected = newName;
            }
        }, {
            detail: {
                renamed: {
                    from: oldName,
                    to: newName,
                },
            },
        });

        return newName;
    }

    addField(tableName) {
        return this.addFieldAt(tableName, this.timestampStartIndex(tableName));
    }

    addFieldAt(tableName, index) {
        const table = this.table(tableName);

        if (!table) {
            throw new Error('Select a table first.');
        }

        const name = this.uniqueFieldName(tableName, 'new_field');

        this.mutate(() => {
            if (!table.fields || typeof table.fields !== 'object' || Array.isArray(table.fields)) {
                table.fields = {};
            }

            const entries = Object.entries(table.fields);
            const timestampStart = entries.findIndex(([fieldName]) => isLifecycleField(fieldName));
            const position = timestampStart === -1 ? entries.length : timestampStart;
            const target = Math.max(0, Math.min(index, position));

            entries.splice(target, 0, [
                name,
                {
                    type: 'string',
                    length: 255,
                },
            ]);

            table.fields = Object.fromEntries(entries);
            orderTableFields(table);
            this.selected = tableName;
        });

        return name;
    }

    timestampStartIndex(tableName) {
        const table = this.table(tableName);
        const entries = Object.entries(table?.fields ?? {});
        const index = entries.findIndex(([fieldName]) => isLifecycleField(fieldName));

        return index === -1 ? entries.length : index;
    }

    insertFieldEntry(table, name, field) {
        const entries = Object.entries(table.fields ?? {});
        const timestampStart = entries.findIndex(([fieldName]) => isLifecycleField(fieldName));
        const position = timestampStart === -1 ? entries.length : timestampStart;

        entries.splice(position, 0, [name, field]);
        table.fields = Object.fromEntries(entries);
        orderTableFields(table);
    }

    deleteField(tableName, fieldName) {
        const table = this.table(tableName);

        if (!table?.fields?.[fieldName]) {
            return;
        }

        this.mutate(() => {
            delete table.fields[fieldName];
            table.primary_key = Array.isArray(table.primary_key)
                ? table.primary_key.filter((column) => column !== fieldName)
                : [];

            if (Array.isArray(table.indexes)) {
                table.indexes = table.indexes
                    .map((index) => ({
                        ...index,
                        columns: Array.isArray(index.columns)
                            ? index.columns.filter((column) => column !== fieldName)
                            : [],
                    }))
                    .filter((index) => index.columns.length > 0);
            }

            for (const otherTable of Object.values(this.document.tables)) {
                if (!otherTable?.fields || typeof otherTable.fields !== 'object') {
                    continue;
                }

                for (const field of Object.values(otherTable.fields)) {
                    if (field?.references?.table === tableName && field.references.field === fieldName) {
                        delete field.references;
                    }
                }
            }
        });
    }

    renameField(tableName, oldName, newName) {
        if (oldName === newName) {
            return oldName;
        }

        if (!isSafeName(newName) || !isSnakeCase(newName)) {
            throw new Error('Field names must use snake_case and start with a letter.');
        }

        const table = this.table(tableName);

        if (!table?.fields?.[oldName]) {
            throw new Error('The field no longer exists.');
        }

        if (table.fields[newName]) {
            throw new Error(`The field "${newName}" already exists.`);
        }

        this.mutate(() => {
            const fields = {};

            for (const [name, value] of Object.entries(table.fields)) {
                fields[name === oldName ? newName : name] = value;
            }

            table.fields = fields;
            orderTableFields(table);
            table.primary_key = Array.isArray(table.primary_key)
                ? table.primary_key.map((column) => (column === oldName ? newName : column))
                : [];

            if (Array.isArray(table.indexes)) {
                table.indexes = table.indexes.map((index) => ({
                    ...index,
                    columns: Array.isArray(index.columns)
                        ? index.columns.map((column) => (column === oldName ? newName : column))
                        : [],
                }));
            }

            for (const otherTable of Object.values(this.document.tables)) {
                if (!otherTable?.fields || typeof otherTable.fields !== 'object') {
                    continue;
                }

                for (const field of Object.values(otherTable.fields)) {
                    if (field?.references?.table === tableName && field.references.field === oldName) {
                        field.references.field = newName;
                    }
                }
            }
        });

        return newName;
    }

    setPrimaryKey(tableName, fieldName, isPrimary) {
        const table = this.table(tableName);

        if (!table?.fields?.[fieldName]) {
            return;
        }

        this.mutate(() => {
            const primaryKey = Array.isArray(table.primary_key) ? [...table.primary_key] : [];
            const position = primaryKey.indexOf(fieldName);

            if (isPrimary && position === -1) {
                primaryKey.push(fieldName);
            }

            if (!isPrimary && position !== -1) {
                primaryKey.splice(position, 1);
            }

            table.primary_key = primaryKey;
        });
    }

    setPivot(tableName, pivot) {
        const table = this.table(tableName);

        if (!table) {
            return;
        }

        this.mutate(() => {
            if (pivot) {
                table.pivot = true;
            } else {
                delete table.pivot;
            }
        });
    }

    moveField(tableName, fieldName, offset) {
        const table = this.table(tableName);

        if (!table?.fields || typeof table.fields !== 'object' || isLifecycleField(fieldName)) {
            return;
        }

        const entries = Object.entries(table.fields);
        const index = entries.findIndex(([name]) => name === fieldName);
        const timestampStart = entries.findIndex(([name]) => isLifecycleField(name));
        const limit = timestampStart === -1 ? entries.length : timestampStart;

        if (index === -1) {
            return;
        }

        const target = index + offset;

        if (target < 0 || target >= limit) {
            return;
        }

        this.mutate(() => {
            const [entry] = entries.splice(index, 1);
            entries.splice(target, 0, entry);
            table.fields = Object.fromEntries(entries);
            orderTableFields(table);
        });
    }

    addRelation(sourceTableName, options) {
        const source = this.table(sourceTableName);
        const target = this.table(options.target);

        if (!source) {
            throw new Error('Select a table first.');
        }

        if (!target) {
            throw new Error('Select a target table.');
        }

        if (options.kind === 'many-to-many') {
            return this.addManyToMany(sourceTableName, source, target, options);
        }

        return this.addForeignKey(sourceTableName, source, target, options);
    }

    addForeignKey(sourceName, source, target, options) {
        const targetName = options.target;
        const targetKey = options.targetKey || (target.primary_key?.length === 1 ? target.primary_key[0] : null);

        if (!targetKey || !target.fields?.[targetKey]) {
            throw new Error('The target table needs a primary key.');
        }

        const suggested = options.fieldName?.trim() || fkName(targetName);
        const fieldName = this.uniqueFieldName(sourceName, suggested);
        const targetField = target.fields[targetKey];
        const field = {
            type: targetField.type ?? 'bigint',
            references: {
                table: targetName,
                field: targetKey,
            },
        };

        if (targetField.unsigned === true) {
            field.unsigned = true;
        }

        if (Number.isInteger(targetField.length)) {
            field.length = targetField.length;
        }

        if (Number.isInteger(targetField.precision)) {
            field.precision = targetField.precision;
        }

        if (Number.isInteger(targetField.scale)) {
            field.scale = targetField.scale;
        }

        if (Array.isArray(targetField.values)) {
            field.values = [...targetField.values];
        }

        if (options.nullable === true) {
            field.nullable = true;
        }

        if (options.kind === 'one-to-one') {
            field.unique = true;
        }

        if (options.onDelete && options.onDelete !== '') {
            field.references.on_delete = options.onDelete;
        }

        this.mutate(() => {
            if (!source.fields || typeof source.fields !== 'object') {
                source.fields = {};
            }

            this.insertFieldEntry(source, fieldName, field);
            this.selected = sourceName;
        });

        return { table: sourceName, field: fieldName };
    }

    addManyToMany(sourceName, source, target, options) {
        const targetName = options.target;
        const sourceKey = options.sourceKey || (source.primary_key?.length === 1 ? source.primary_key[0] : null);
        const targetKey = options.targetKey || (target.primary_key?.length === 1 ? target.primary_key[0] : null);

        if (!sourceKey || !source.fields?.[sourceKey]) {
            throw new Error('The current table needs a single primary key to build a pivot table.');
        }

        if (!targetKey || !target.fields?.[targetKey]) {
            throw new Error('The target table needs a single primary key to build a pivot table.');
        }

        const name = options.pivotTable?.trim() || pivotName(sourceName, targetName);

        if (!isSafeName(name) || !isSnakeCase(name)) {
            throw new Error('Pivot table names must use snake_case.');
        }

        if (this.table(name)) {
            throw new Error(`The table "${name}" already exists.`);
        }

        const sameTable = sourceName === targetName;
        const firstFieldName = sameTable ? 'source_id' : `${singularize(sourceName)}_id`;
        const secondFieldName = sameTable ? 'target_id' : `${singularize(targetName)}_id`;

        if (firstFieldName === secondFieldName) {
            throw new Error('The two pivot foreign keys need different names.');
        }

        const first = this.foreignKeyDefinition(sourceName, source, sourceKey, options.onDelete);
        const second = this.foreignKeyDefinition(targetName, target, targetKey, options.onDelete);

        this.mutate(() => {
            this.document.tables[name] = {
                primary_key: [
                    firstFieldName,
                    secondFieldName,
                ],
                pivot: true,
                fields: {
                    [firstFieldName]: first,
                    [secondFieldName]: second,
                },
            };
            this.selected = sourceName;
        });

        return { table: name, pivot: true };
    }

    foreignKeyDefinition(tableName, table, key, onDelete) {
        const field = table.fields?.[key] ?? {};
        const definition = {
            type: field.type ?? 'bigint',
            references: {
                table: tableName,
                field: key,
            },
        };

        if (field.unsigned === true) {
            definition.unsigned = true;
        }

        if (Number.isInteger(field.length)) {
            definition.length = field.length;
        }

        if (Number.isInteger(field.precision)) {
            definition.precision = field.precision;
        }

        if (Number.isInteger(field.scale)) {
            definition.scale = field.scale;
        }

        if (Array.isArray(field.values)) {
            definition.values = [...field.values];
        }

        definition.references.on_delete = onDelete || 'cascade';

        return definition;
    }

    referencesToTable(tableName) {
        const references = [];

        for (const [sourceName, table] of Object.entries(this.document.tables)) {
            if (!table?.fields || typeof table.fields !== 'object') {
                continue;
            }

            for (const [fieldName, field] of Object.entries(table.fields)) {
                if (field?.references?.table === tableName) {
                    references.push({
                        table: sourceName,
                        field: fieldName,
                    });
                }
            }
        }

        return references;
    }

    referencesToField(tableName, fieldName) {
        return this.referencesToTable(tableName).filter(
            (reference) => this.table(reference.table)?.fields?.[reference.field]?.references?.field === fieldName,
        );
    }

    uniqueTableName(base) {
        if (!this.table(base)) {
            return base;
        }

        let index = 2;

        while (this.table(`${base}_${index}`)) {
            index += 1;
        }

        return `${base}_${index}`;
    }

    uniqueFieldName(tableName, base) {
        const fields = this.table(tableName)?.fields ?? {};

        if (!fields[base]) {
            return base;
        }

        let index = 2;

        while (fields[`${base}_${index}`]) {
            index += 1;
        }

        return `${base}_${index}`;
    }

    suggestedModel(tableName) {
        return `App\\Models\\${studly(singularize(tableName))}`;
    }

    serialize() {
        return JSON.stringify(this.document, null, 2);
    }
}

function orderTableFields(table) {
    if (!table?.fields || typeof table.fields !== 'object' || Array.isArray(table.fields)) {
        return;
    }

    const entries = Object.entries(table.fields);
    const regular = entries.filter(([name]) => !isLifecycleField(name));
    const lifecycle = LIFECYCLE_FIELDS
        .map((name) => [name, table.fields[name]])
        .filter(([, field]) => field !== undefined);

    table.fields = Object.fromEntries([
        ...regular,
        ...lifecycle,
    ]);
}
