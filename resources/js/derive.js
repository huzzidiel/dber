/**
 * Derivación del diagrama en el navegador.
 *
 * El backend aplica las mismas reglas al cargar y al guardar. Esta versión
 * permite que el preview y el editor respondan sin ida y vuelta al servidor.
 */

import { singularize } from './utils.js';

export const TYPES = [
    'bigint',
    'integer',
    'mediumint',
    'smallint',
    'tinyint',
    'string',
    'char',
    'text',
    'mediumtext',
    'longtext',
    'boolean',
    'decimal',
    'float',
    'double',
    'date',
    'datetime',
    'timestamp',
    'time',
    'year',
    'json',
    'uuid',
    'ulid',
    'binary',
    'enum',
    'set',
];

const INTEGER_TYPES = new Set(['bigint', 'integer', 'mediumint', 'smallint', 'tinyint']);
const LENGTH_TYPES = new Set(['string', 'char', 'binary']);
const PRECISION_TYPES = new Set(['decimal', 'float', 'double']);
const UNSIGNED_TYPES = new Set([...INTEGER_TYPES, 'decimal', 'float', 'double']);
const VALUE_TYPES = new Set(['enum', 'set']);

export function supportsLength(type) {
    return LENGTH_TYPES.has(type);
}

export function supportsPrecision(type) {
    return PRECISION_TYPES.has(type);
}

export function supportsValues(type) {
    return VALUE_TYPES.has(type);
}

export function supportsUnsigned(type) {
    return UNSIGNED_TYPES.has(type);
}

export function supportsAutoIncrement(type) {
    return INTEGER_TYPES.has(type);
}

function normalizeField(name, data) {
    const raw = data && typeof data === 'object' ? data : {};
    const type = typeof raw.type === 'string' ? raw.type.toLowerCase() : '';
    const references = raw.references && typeof raw.references === 'object'
        ? {
            table: String(raw.references.table ?? ''),
            field: String(raw.references.field ?? ''),
            on_delete: raw.references.on_delete ?? null,
            on_update: raw.references.on_update ?? null,
        }
        : null;

    const field = {
        name,
        type,
        length: Number.isInteger(raw.length) ? raw.length : null,
        precision: Number.isInteger(raw.precision) ? raw.precision : null,
        scale: Number.isInteger(raw.scale) ? raw.scale : null,
        nullable: raw.nullable === true,
        unique: raw.unique === true,
        unsigned: raw.unsigned === true,
        autoIncrement: raw.auto_increment === true,
        hasDefault: Object.prototype.hasOwnProperty.call(raw, 'default'),
        default: raw.default ?? null,
        references,
        values: Array.isArray(raw.values) ? raw.values.map(String) : [],
        primary: false,
    };

    field.typeLabel = formatTypeLabel(field);

    return field;
}

export function formatTypeLabel(field) {
    if (supportsValues(field.type) && field.values.length > 0) {
        return `${field.type}(${field.values.join(',')})`;
    }

    if (field.precision !== null) {
        return `${field.type}(${field.precision}${field.scale !== null ? `,${field.scale}` : ''})`;
    }

    if (field.length !== null) {
        return `${field.type}(${field.length})`;
    }

    return field.type;
}

function normalizeTable(name, data) {
    const raw = data && typeof data === 'object' ? data : {};
    const rawFields = raw.fields && typeof raw.fields === 'object' && !Array.isArray(raw.fields)
        ? raw.fields
        : {};

    const fields = Object.entries(rawFields).map(([fieldName, field]) => normalizeField(fieldName, field));

    const primaryKey = Array.isArray(raw.primary_key)
        ? raw.primary_key.map(String)
        : typeof raw.primary_key === 'string'
            ? [raw.primary_key]
            : [];

    const fieldMap = new Map(fields.map((field) => [field.name, field]));

    for (const field of fields) {
        field.primary = primaryKey.includes(field.name);
    }

    const indexes = Array.isArray(raw.indexes)
        ? raw.indexes
            .filter((index) => index && typeof index === 'object')
            .map((index) => ({
                name: typeof index.name === 'string' ? index.name : null,
                columns: Array.isArray(index.columns) ? index.columns.map(String) : [],
                unique: index.unique === true,
            }))
        : [];

    const table = {
        key: name,
        model: typeof raw.model === 'string' ? raw.model : '',
        description: typeof raw.description === 'string' ? raw.description : '',
        primaryKey,
        explicitPivot: raw.pivot === true,
        fields,
        fieldMap,
        indexes,
        pivot: false,
    };

    table.pivot = isPivotTable(table);

    return table;
}

export function isUnique(table, field) {
    if (field.unique) {
        return true;
    }

    if (table.primaryKey.length === 1 && table.primaryKey[0] === field.name) {
        return true;
    }

    return table.indexes.some(
        (index) => index.unique && index.columns.length === 1 && index.columns[0] === field.name,
    );
}

export function isPivotTable(table) {
    return table.explicitPivot === true;
}

export function derive(document) {
    const rawTables = document?.tables && typeof document.tables === 'object' && !Array.isArray(document.tables)
        ? document.tables
        : {};

    const tables = Object.entries(rawTables).map(([name, data]) => normalizeTable(name, data));
    const byKey = new Map(tables.map((table) => [table.key, table]));
    const pivotTables = tables.filter((table) => table.pivot).map((table) => table.key);
    const relations = [];

    for (const table of tables) {
        for (const field of table.fields) {
            if (field.references === null) {
                continue;
            }

            const target = byKey.get(field.references.table);

            if (!target || !target.fieldMap.has(field.references.field)) {
                continue;
            }

            relations.push({
                id: `fk:${table.key}.${field.name}`,
                kind: isUnique(table, field) ? 'one-to-one' : 'many-to-one',
                fromTable: table.key,
                fromField: field.name,
                toTable: target.key,
                toField: field.references.field,
                nullable: field.nullable,
                onDelete: field.references.on_delete ?? null,
                onUpdate: field.references.on_update ?? null,
                pivotTable: null,
                label: field.name,
            });
        }
    }

    for (const pivot of tables.filter((table) => table.pivot)) {
        const foreignKeys = pivot.fields.filter((field) => field.references !== null);

        if (foreignKeys.length !== 2) {
            continue;
        }

        const first = foreignKeys[0];
        const second = foreignKeys[1];
        const firstTarget = byKey.get(first.references.table);
        const secondTarget = byKey.get(second.references.table);

        if (!firstTarget || !secondTarget) {
            continue;
        }

        relations.push({
            id: `many-to-many:${pivot.key}`,
            kind: 'many-to-many',
            fromTable: firstTarget.key,
            fromField: first.name,
            toTable: secondTarget.key,
            toField: second.name,
            nullable: false,
            onDelete: null,
            onUpdate: null,
            pivotTable: pivot.key,
            label: `via ${pivot.key}`,
        });
    }

    return {
        tables,
        byKey,
        pivotTables,
        relations,
        stats: {
            tables: tables.length,
            fields: tables.reduce((total, table) => total + table.fields.length, 0),
            relations: relations.length,
            pivots: pivotTables.length,
        },
    };
}

/**
 * Comprobaciones rápidas para bloquear guardados evidentemente inválidos.
 */
export function lint(document) {
    const issues = [];
    const rawTables = document?.tables;
    const version = Number(document?.schema_version ?? 1);

    if (version > 1) {
        issues.push({
            severity: 'error',
            path: 'schema_version',
            message: `Schema version ${version} is not supported.`,
        });
    }

    if (!rawTables || typeof rawTables !== 'object' || Array.isArray(rawTables)) {
        issues.push({
            severity: 'error',
            path: 'tables',
            message: 'Tables must be an object keyed by table name.',
        });

        return issues;
    }

    for (const [tableName, table] of Object.entries(rawTables)) {
        if (!table || typeof table !== 'object' || Array.isArray(table)) {
            issues.push({
                severity: 'error',
                path: `tables.${tableName}`,
                message: 'The table definition must be an object.',
            });
            continue;
        }

        if (!/^[a-z][a-z0-9_]*$/.test(tableName)) {
            issues.push({
                severity: 'warning',
                path: `tables.${tableName}`,
                message: 'Table names should use snake_case.',
            });
        }

        const rawFields = table.fields;

        if (!rawFields || typeof rawFields !== 'object' || Array.isArray(rawFields) || Object.keys(rawFields).length === 0) {
            issues.push({
                severity: 'error',
                path: `tables.${tableName}.fields`,
                message: 'The table needs at least one field.',
            });
            continue;
        }

        const primaryKey = Array.isArray(table.primary_key) ? table.primary_key.map(String) : [];

        if (primaryKey.length === 0) {
            issues.push({
                severity: 'warning',
                path: `tables.${tableName}.primary_key`,
                message: 'The table does not declare a primary key.',
            });
        }

        for (const primaryField of primaryKey) {
            if (!Object.prototype.hasOwnProperty.call(rawFields, primaryField)) {
                issues.push({
                    severity: 'error',
                    path: `tables.${tableName}.primary_key`,
                    message: `Primary key column "${primaryField}" does not exist.`,
                });
            }
        }

        let foreignKeyCount = 0;

        for (const [fieldName, field] of Object.entries(rawFields)) {
            const path = `tables.${tableName}.fields.${fieldName}`;

            if (!field || typeof field !== 'object' || Array.isArray(field)) {
                issues.push({
                    severity: 'error',
                    path,
                    message: 'The field definition must be an object.',
                });
                continue;
            }

            if (!/^[a-z][a-z0-9_]*$/.test(fieldName)) {
                issues.push({
                    severity: 'warning',
                    path,
                    message: 'Field names should use snake_case.',
                });
            }

            const type = typeof field.type === 'string' ? field.type : '';

            if (!TYPES.includes(type)) {
                issues.push({
                    severity: 'error',
                    path: `${path}.type`,
                    message: `Unknown field type "${type}".`,
                });
                continue;
            }

            if (field.references) {
                foreignKeyCount += 1;
                validateReference(issues, path, field.references, rawTables);

                if (field.references.on_delete === 'set_null' && field.nullable !== true) {
                    issues.push({
                        severity: 'warning',
                        path: `${path}.references.on_delete`,
                        message: 'on_delete "set_null" requires a nullable column.',
                    });
                }
            }

            if (supportsValues(type) && (!Array.isArray(field.values) || field.values.length === 0)) {
                issues.push({
                    severity: 'error',
                    path: `${path}.values`,
                    message: `The ${type} type needs at least one value.`,
                });
            }

            if (field.auto_increment === true) {
                if (!supportsAutoIncrement(type)) {
                    issues.push({
                        severity: 'error',
                        path: `${path}.auto_increment`,
                        message: 'Auto increment requires an integer type.',
                    });
                }

                if (!primaryKey.includes(fieldName)) {
                    issues.push({
                        severity: 'warning',
                        path: `${path}.auto_increment`,
                        message: 'Auto increment columns are normally the primary key.',
                    });
                }
            }

            if (field.unsigned === true && !supportsUnsigned(type)) {
                issues.push({
                    severity: 'warning',
                    path: `${path}.unsigned`,
                    message: `The ${type} type does not support unsigned.`,
                });
            }

            if (field.length !== undefined && field.length !== null && !supportsLength(type)) {
                issues.push({
                    severity: 'warning',
                    path: `${path}.length`,
                    message: `The ${type} type does not use a length.`,
                });
            }

            if (field.precision !== undefined && field.precision !== null && !supportsPrecision(type)) {
                issues.push({
                    severity: 'warning',
                    path: `${path}.precision`,
                    message: `The ${type} type does not use precision.`,
                });
            }
        }

        if (table.pivot === true && foreignKeyCount < 2) {
            issues.push({
                severity: 'warning',
                path: `tables.${tableName}.pivot`,
                message: 'A pivot table needs at least two foreign keys.',
            });
        }

        if (Array.isArray(table.indexes)) {
            table.indexes.forEach((index, position) => {
                const columns = Array.isArray(index?.columns) ? index.columns : [];

                for (const column of columns) {
                    if (!Object.prototype.hasOwnProperty.call(rawFields, column)) {
                        issues.push({
                            severity: 'error',
                            path: `tables.${tableName}.indexes.${position}.columns`,
                            message: `Index column "${column}" does not exist.`,
                        });
                    }
                }
            });
        }
    }

    return issues;
}

function validateReference(issues, path, reference, rawTables) {
    const tableName = typeof reference.table === 'string' ? reference.table : '';
    const fieldName = typeof reference.field === 'string' ? reference.field : '';

    if (tableName === '' || fieldName === '') {
        issues.push({
            severity: 'error',
            path: `${path}.references`,
            message: 'A reference needs a target table and field.',
        });
        return;
    }

    const target = rawTables[tableName];

    if (!target || typeof target !== 'object') {
        issues.push({
            severity: 'error',
            path: `${path}.references.table`,
            message: `Referenced table "${tableName}" does not exist.`,
        });
        return;
    }

    const targetFields = target.fields && typeof target.fields === 'object' ? target.fields : {};

    if (!Object.prototype.hasOwnProperty.call(targetFields, fieldName)) {
        issues.push({
            severity: 'error',
            path: `${path}.references.field`,
            message: `Referenced field "${tableName}.${fieldName}" does not exist.`,
        });
    }
}

export function relationLabel(relation) {
    if (relation.kind === 'many-to-many') {
        return `${relation.fromTable} ↔ ${relation.toTable} via ${relation.pivotTable}`;
    }

    return `${relation.fromTable}.${relation.fromField} → ${relation.toTable}.${relation.toField}`;
}

export function inverseKind(relation) {
    if (relation.kind === 'one-to-one') {
        return 'one-to-one';
    }

    return 'one-to-many';
}

export function suggestedForeignKeyName(sourceTable, targetTable) {
    if (sourceTable === targetTable) {
        return 'parent_id';
    }

    return `${singularize(targetTable)}_id`;
}
