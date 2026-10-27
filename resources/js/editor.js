/**
 * Inspector de tabla: edición de campos, claves y relaciones.
 */

import {
    clear,
    el,
    formatDefault,
    isLifecycleField,
    parseDefaultInput,
    pivotName,
} from './utils.js';
import {
    TYPES,
    suggestedForeignKeyName,
    supportsAutoIncrement,
    supportsLength,
    supportsPrecision,
    supportsUnsigned,
    supportsValues,
} from './derive.js';

const ACTIONS = [
    {
        value: '',
        label: 'No action',
    },
    {
        value: 'cascade',
        label: 'Cascade',
    },
    {
        value: 'restrict',
        label: 'Restrict',
    },
    {
        value: 'set_null',
        label: 'Set null',
    },
    {
        value: 'no_action',
        label: 'No action (SQL)',
    },
    {
        value: 'set_default',
        label: 'Set default',
    },
];

export class Inspector {
    constructor(options) {
        this.root = options.root;
        this.modalRoot = options.modalRoot;
        this.store = options.store;
        this.notify = options.notify ?? (() => {});
        this.onSave = options.onSave ?? (() => {});
        this.bodyEl = null;
        this.lastSelected = null;
    }

    render() {
        const key = this.store.selected;

        if (key === null) {
            clear(this.root);
            this.root.hidden = true;
            this.lastSelected = null;
            return;
        }

        const rawTable = this.store.table(key);
        const derived = this.store.derived();
        const table = derived.byKey.get(key);

        if (!rawTable || !table) {
            this.root.hidden = true;
            return;
        }

        const previousScroll = this.lastSelected === key ? (this.bodyEl?.scrollTop ?? 0) : 0;

        this.root.hidden = false;
        clear(this.root);

        const header = el('div', { className: 'dber-inspector-head' }, [
            el('div', { className: 'dber-inspector-title' }, [
                el('strong', { text: key }),
                el('span', {
                    text: `${table.fields.length} fields · ${this.relationCount(derived, key)} relations`
                        + (table.pivot ? ' · pivot table' : ''),
                }),
            ]),
            el('button', {
                type: 'button',
                className: 'dber-icon-btn',
                title: 'Close inspector',
                text: '×',
                onclick: () => this.store.setSelected(null),
            }),
        ]);

        const body = el('div', { className: 'dber-inspector-body' });

        body.appendChild(this.renderTableSection(key, rawTable, table));
        body.appendChild(this.renderFieldsSection(key, table));
        body.appendChild(this.renderRelationsSection(key, derived));
        body.appendChild(this.renderIndexesSection(table));
        body.appendChild(this.renderDangerSection(key, table));

        this.root.appendChild(header);
        this.root.appendChild(body);

        if (this.store.editable) {
            const footer = el('div', { className: 'dber-inspector-foot' }, [
                el('button', {
                    type: 'button',
                    className: 'dber-btn dber-btn-primary',
                    text: 'Save schema',
                    onclick: () => this.onSave(),
                }),
            ]);
            this.root.appendChild(footer);
        }

        this.bodyEl = body;
        body.scrollTop = previousScroll;
        this.lastSelected = key;

        if (!this.store.editable) {
            this.root.classList.add('is-readonly');
        } else {
            this.root.classList.remove('is-readonly');
        }
    }

    renderTableSection(key, rawTable, table) {
        const nameInput = this.input({
            value: key,
            maxlength: 64,
            'data-table-name': '',
        });
        nameInput.addEventListener('change', () => {
            const next = nameInput.value.trim();

            try {
                this.store.renameTable(key, next);
            } catch (error) {
                this.notify(error.message, 'error');
                nameInput.value = key;
            }
        });

        const modelInput = this.input({
            value: table.model,
            placeholder: this.store.suggestedModel(key),
        });
        modelInput.addEventListener('input', () => {
            const value = modelInput.value.trim();
            this.store.edit(`model:${key}`, () => {
                if (value === '') {
                    delete rawTable.model;
                } else {
                    rawTable.model = value;
                }
            });
        });

        const description = el('textarea', {
            className: 'dber-textarea',
            rows: 2,
            placeholder: 'Optional human description',
            disabled: !this.store.editable,
            text: table.description,
        });
        description.addEventListener('input', () => {
            const value = description.value.trim();
            this.store.edit(`description:${key}`, () => {
                if (value === '') {
                    delete rawTable.description;
                } else {
                    rawTable.description = value;
                }
            });
        });

        const pivotCheck = el('input', {
            type: 'checkbox',
            checked: table.pivot,
            disabled: !this.store.editable,
        });
        pivotCheck.addEventListener('change', () => {
            this.store.setPivot(key, pivotCheck.checked);
        });

        const primary = table.primaryKey.length === 0
            ? 'No primary key declared.'
            : table.primaryKey.join(', ');

        return this.section(
            'Table',
            [
                el('div', { className: 'dber-form-grid' }, [
                    this.formRow('Name', nameInput),
                    this.formRow('Primary key', el('div', {
                        className: 'dber-note',
                        text: primary,
                    })),
                    this.formRow('Model', modelInput, true),
                    this.formRow('Description', description, true),
                    el('div', { className: 'dber-form-row is-full' }, [
                        el('span', { text: 'Role' }),
                        el('label', { className: 'dber-check' }, [
                            pivotCheck,
                            el('span', { text: 'Many-to-many pivot table' }),
                        ]),
                    ]),
                ]),
            ],
        );
    }

    renderFieldsSection(key, table) {
        const list = el('div');
        const timestampStart = table.fields.findIndex((field) => isLifecycleField(field.name));

        if (table.fields.length === 0) {
            list.appendChild(el('div', {
                className: 'dber-empty-inline',
                text: 'This table has no fields yet.',
            }));
        }

        table.fields.forEach((field, index) => {
            list.appendChild(this.renderFieldCard(key, table, field, index));

            const beforeTimestamps = timestampStart === -1
                ? index < table.fields.length - 1
                : index < timestampStart;

            if (beforeTimestamps) {
                list.appendChild(el('button', {
                    type: 'button',
                    className: 'dber-field-insert',
                    title: 'Insert field here',
                    disabled: !this.store.editable,
                    onclick: () => {
                        const name = this.store.addFieldAt(key, index + 1);

                        window.setTimeout(() => {
                            const input = this.root.querySelector(`[data-field="${cssEscape(name)}"] [data-field-name]`);
                            input?.focus();
                            input?.select();
                        }, 0);
                    },
                }, [
                    el('span', { className: 'dber-field-insert-line' }),
                    el('span', { className: 'dber-field-insert-label', text: '+ Insert field' }),
                    el('span', { className: 'dber-field-insert-line' }),
                ]));
            }
        });

        const addButton = el('button', {
            type: 'button',
            className: 'dber-btn dber-btn-small',
            text: '+ Add field',
            disabled: !this.store.editable,
            onclick: () => {
                const name = this.store.addField(key);

                window.setTimeout(() => {
                    const input = this.root.querySelector(`[data-field="${cssEscape(name)}"] [data-field-name]`);
                    input?.focus();
                    input?.select();
                }, 0);
            },
        });

        return this.section(
            'Fields',
            [
                el('p', {
                    className: 'dber-section-hint',
                    text: 'Foreign keys live on the referencing field. Relations are derived automatically. Lifecycle timestamps stay at the end.',
                }),
                list,
            ],
            addButton,
        );
    }

    renderFieldCard(tableKey, table, field, index) {
        const raw = this.store.table(tableKey)?.fields?.[field.name];

        if (!raw) {
            return el('div');
        }

        const timestampStart = table.fields.findIndex((item) => isLifecycleField(item.name));
        const lastDataIndex = timestampStart === -1 ? table.fields.length - 1 : timestampStart - 1;

        const card = el('div', {
            className: `dber-field-card${field.primary ? ' is-selected' : ''}`,
            'data-field': field.name,
        });

        const title = el('div', { className: 'dber-field-card-title' }, [
            el('span', { text: field.name }),
            field.primary ? pill('PK', 'is-pk') : null,
            field.references ? pill('FK', 'is-fk') : null,
            field.unique ? pill('UQ', 'is-unique') : null,
        ]);

        const actions = el('div', { className: 'dber-field-card-actions' }, [
            el('button', {
                type: 'button',
                className: 'dber-icon-btn',
                title: 'Move up',
                text: '↑',
                disabled: !this.store.editable || index === 0 || isLifecycleField(field.name),
                onclick: () => this.store.moveField(tableKey, field.name, -1),
            }),
            el('button', {
                type: 'button',
                className: 'dber-icon-btn',
                title: 'Move down',
                text: '↓',
                disabled: !this.store.editable || index >= lastDataIndex || isLifecycleField(field.name),
                onclick: () => this.store.moveField(tableKey, field.name, 1),
            }),
            el('button', {
                type: 'button',
                className: 'dber-icon-btn is-danger',
                title: 'Delete field',
                text: '×',
                disabled: !this.store.editable,
                onclick: () => this.deleteField(tableKey, field),
            }),
        ]);

        card.appendChild(el('div', { className: 'dber-field-card-head' }, [title, actions]));

        const nameInput = this.input({
            value: field.name,
            'data-field-name': '',
            maxlength: 64,
        });
        nameInput.addEventListener('change', () => {
            const next = nameInput.value.trim();

            try {
                this.store.renameField(tableKey, field.name, next);
            } catch (error) {
                this.notify(error.message, 'error');
                nameInput.value = field.name;
            }
        });

        const typeSelect = this.select(
            TYPES.map((type) => ({ value: type, label: type })),
            field.type,
            (value) => {
                this.store.edit(`type:${tableKey}:${field.name}`, () => {
                    raw.type = value;

                    if (!supportsLength(value)) {
                        delete raw.length;
                    }

                    if (!supportsPrecision(value)) {
                        delete raw.precision;
                        delete raw.scale;
                    }

                    if (!supportsUnsigned(value)) {
                        delete raw.unsigned;
                    }

                    if (!supportsAutoIncrement(value)) {
                        delete raw.auto_increment;
                    }

                    if (supportsValues(value)) {
                        if (!Array.isArray(raw.values)) {
                            raw.values = [];
                        }
                    } else {
                        delete raw.values;
                    }
                }, { structural: true });
            },
            !this.store.editable,
        );

        const grid = el('div', { className: 'dber-form-grid' }, [
            this.formRow('Name', nameInput),
            this.formRow('Type', typeSelect),
        ]);

        if (supportsLength(field.type)) {
            grid.appendChild(this.formRow('Length', this.numberInput(field.length, 1, (value) => {
                this.store.edit(`length:${tableKey}:${field.name}`, () => {
                    if (value === null) {
                        delete raw.length;
                    } else {
                        raw.length = value;
                    }
                });
            })));
        }

        if (supportsPrecision(field.type)) {
            grid.appendChild(this.formRow('Precision', this.numberInput(field.precision, 1, (value) => {
                this.store.edit(`precision:${tableKey}:${field.name}`, () => {
                    if (value === null) {
                        delete raw.precision;
                    } else {
                        raw.precision = value;
                    }
                });
            })));
            grid.appendChild(this.formRow('Scale', this.numberInput(field.scale, 0, (value) => {
                this.store.edit(`scale:${tableKey}:${field.name}`, () => {
                    if (value === null) {
                        delete raw.scale;
                    } else {
                        raw.scale = value;
                    }
                });
            })));
        }

        if (supportsValues(field.type)) {
            const valuesInput = this.input({
                value: field.values.join(', '),
                placeholder: 'draft, published, archived',
            });
            valuesInput.addEventListener('change', () => {
                const values = valuesInput.value
                    .split(',')
                    .map((value) => value.trim())
                    .filter(Boolean);

                this.store.edit(`values:${tableKey}:${field.name}`, () => {
                    if (values.length === 0) {
                        delete raw.values;
                    } else {
                        raw.values = values;
                    }
                });
            });
            grid.appendChild(this.formRow('Values', valuesInput, true));
        }

        const defaultInput = this.input({
            value: field.hasDefault ? formatDefault(field.default, true) : '',
            placeholder: 'No default',
        });
        defaultInput.addEventListener('change', () => {
            const parsed = parseDefaultInput(defaultInput.value);

            this.store.edit(`default:${tableKey}:${field.name}`, () => {
                if (parsed.present) {
                    raw.default = parsed.value;
                } else {
                    delete raw.default;
                }
            });
        });
        grid.appendChild(this.formRow('Default', defaultInput));

        card.appendChild(grid);

        const flags = el('div', { className: 'dber-field-flags' }, [
            this.flag('Nullable', field.nullable, (checked) => {
                this.store.edit(`nullable:${tableKey}:${field.name}`, () => {
                    if (checked) {
                        raw.nullable = true;
                    } else {
                        delete raw.nullable;
                    }
                }, { structural: true });
            }),
            this.flag('Unique', field.unique, (checked) => {
                this.store.edit(`unique:${tableKey}:${field.name}`, () => {
                    if (checked) {
                        raw.unique = true;
                    } else {
                        delete raw.unique;
                    }
                }, { structural: true });
            }),
            this.flag('Primary key', field.primary, (checked) => {
                this.store.setPrimaryKey(tableKey, field.name, checked);
            }),
        ]);

        if (supportsUnsigned(field.type)) {
            flags.appendChild(this.flag('Unsigned', field.unsigned, (checked) => {
                this.store.edit(`unsigned:${tableKey}:${field.name}`, () => {
                    if (checked) {
                        raw.unsigned = true;
                    } else {
                        delete raw.unsigned;
                    }
                }, { structural: true });
            }));
        }

        if (supportsAutoIncrement(field.type)) {
            flags.appendChild(this.flag('Auto increment', field.autoIncrement, (checked) => {
                this.store.edit(`auto_increment:${tableKey}:${field.name}`, () => {
                    if (checked) {
                        raw.auto_increment = true;
                    } else {
                        delete raw.auto_increment;
                    }
                }, { structural: true });
            }));
        }

        card.appendChild(flags);
        card.appendChild(this.renderReferenceBox(tableKey, field));

        return card;
    }

    renderReferenceBox(tableKey, field) {
        const box = el('div', { className: 'dber-ref-box' });
        box.appendChild(el('div', {
            className: 'dber-section-head',
            style: 'margin-bottom: 7px;',
        }, [
            el('span', {
                className: 'dber-section-title',
                text: 'Foreign key',
            }),
        ]));

        const tables = this.store.derived().tables;
        const tableSelect = this.select(
            [
                {
                    value: '',
                    label: 'No reference',
                },
                ...tables.map((table) => ({
                    value: table.key,
                    label: table.key,
                })),
            ],
            field.references?.table ?? '',
            (value) => {
                if (value === '') {
                    this.store.edit(`reference-table:${tableKey}:${field.name}`, () => {
                        delete this.store.table(tableKey).fields[field.name].references;
                    }, { structural: true });
                    return;
                }

                const target = this.store.table(value);
                const targetKey = target?.primary_key?.[0] ?? Object.keys(target?.fields ?? {})[0];

                if (!targetKey) {
                    this.notify('The target table has no fields to reference.', 'error');
                    this.render();
                    return;
                }

                this.store.edit(`reference-table:${tableKey}:${field.name}`, () => {
                    this.store.table(tableKey).fields[field.name].references = {
                        table: value,
                        field: targetKey,
                    };
                }, { structural: true });
            },
            !this.store.editable,
        );

        const grid = el('div', { className: 'dber-form-grid' }, [
            this.formRow('Table', tableSelect),
        ]);

        if (field.references !== null && field.references.table !== '') {
            const target = this.store.table(field.references.table);
            const targetFields = target?.fields ?? {};

            const fieldSelect = this.select(
                Object.keys(targetFields).map((name) => ({
                    value: name,
                    label: name,
                })),
                field.references.field,
                (value) => {
                    this.store.edit(`reference-field:${tableKey}:${field.name}`, () => {
                        this.store.table(tableKey).fields[field.name].references.field = value;
                    });
                },
                !this.store.editable,
            );

            const onDelete = this.select(
                ACTIONS,
                field.references.on_delete ?? '',
                (value) => {
                    this.store.edit(`reference-delete:${tableKey}:${field.name}`, () => {
                        const reference = this.store.table(tableKey).fields[field.name].references;

                        if (value === '') {
                            delete reference.on_delete;
                        } else {
                            reference.on_delete = value;
                        }
                    });
                },
                !this.store.editable,
            );

            const onUpdate = this.select(
                ACTIONS,
                field.references.on_update ?? '',
                (value) => {
                    this.store.edit(`reference-update:${tableKey}:${field.name}`, () => {
                        const reference = this.store.table(tableKey).fields[field.name].references;

                        if (value === '') {
                            delete reference.on_update;
                        } else {
                            reference.on_update = value;
                        }
                    });
                },
                !this.store.editable,
            );

            grid.appendChild(this.formRow('Field', fieldSelect));
            grid.appendChild(this.formRow('On delete', onDelete));
            grid.appendChild(this.formRow('On update', onUpdate));
        }

        box.appendChild(grid);

        if (field.references === null) {
            box.appendChild(el('div', {
                className: 'dber-section-hint',
                style: 'margin: 8px 0 0;',
                text: 'Pick a table to declare the relationship once, on this column.',
            }));
        }

        return box;
    }

    renderRelationsSection(key, derived) {
        const list = el('ul', { className: 'dber-relations' });
        const rows = relationRows(derived, key);

        if (rows.length === 0) {
            list.appendChild(el('li', {
                className: 'dber-empty-inline',
                text: 'No relations derived for this table.',
            }));
        }

        for (const row of rows) {
            const targetButton = el('button', {
                type: 'button',
                className: 'dber-icon-btn',
                title: 'Open table',
                text: '↗',
                onclick: () => {
                    this.store.setSelected(row.target);
                },
            });

            const item = el('li', {
                className: `dber-relation${row.kind === 'many-to-many' ? ' is-many-to-many' : ''}`,
            }, [
                el('span', {
                    className: 'dber-relation-kind',
                    text: row.kind === 'many-to-many'
                        ? 'M:N'
                        : row.direction === 'out'
                            ? row.kind
                            : row.kind,
                }),
                el('span', {
                    className: 'dber-relation-target',
                    text: row.label,
                }),
            ]);

            if (row.pivot) {
                item.appendChild(el('button', {
                    type: 'button',
                    className: 'dber-icon-btn',
                    title: 'Open pivot table',
                    text: '◇',
                    onclick: () => this.store.setSelected(row.pivot),
                }));
            }

            item.appendChild(targetButton);
            list.appendChild(item);
        }

        const addButton = el('button', {
            type: 'button',
            className: 'dber-btn dber-btn-small',
            text: '+ Add relation',
            disabled: !this.store.editable,
            onclick: () => this.openRelationModal(),
        });

        return this.section(
            'Relations',
            [
                el('p', {
                    className: 'dber-section-hint',
                    text: 'Relations are derived from foreign keys. Adding one here creates the field or pivot table.',
                }),
                list,
            ],
            addButton,
        );
    }

    renderIndexesSection(table) {
        if (table.indexes.length === 0) {
            return el('div');
        }

        const list = el('ul', { className: 'dber-relations' });

        for (const index of table.indexes) {
            list.appendChild(el('li', { className: 'dber-relation' }, [
                el('span', {
                    className: 'dber-relation-kind',
                    text: index.unique ? 'unique' : 'index',
                }),
                el('span', {
                    className: 'dber-relation-target',
                    text: index.columns.join(', '),
                }),
            ]));
        }

        return this.section(
            'Indexes',
            [
                el('p', {
                    className: 'dber-section-hint',
                    text: 'Composite indexes are stored in the schema file. Field-level unique flags are edited above.',
                }),
                list,
            ],
        );
    }

    renderDangerSection(key, table) {
        const references = this.store.referencesToTable(key);

        return el('section', { className: 'dber-section dber-danger-zone' }, [
            el('div', { className: 'dber-section-head' }, [
                el('h3', { className: 'dber-section-title', text: 'Danger zone' }),
            ]),
            el('p', {
                className: 'dber-section-hint',
                text: references.length === 0
                    ? 'Deleting this table removes it from the schema file.'
                    : `Deleting this table also removes ${references.length} foreign key reference(s).`,
            }),
            el('button', {
                type: 'button',
                className: 'dber-btn dber-btn-danger dber-btn-small',
                text: 'Delete table',
                disabled: !this.store.editable,
                onclick: () => this.deleteTable(key, table),
            }),
        ]);
    }

    openRelationModal() {
        const key = this.store.selected;
        const derived = this.store.derived();
        const source = derived.byKey.get(key);

        if (!source) {
            return;
        }

        const state = {
            kind: 'many-to-one',
            target: derived.tables.find((table) => table.key !== key)?.key ?? key,
            fieldName: '',
            fieldNameTouched: false,
            targetKey: '',
            sourceKey: source.primaryKey.length === 1 ? source.primaryKey[0] : '',
            nullable: false,
            onDelete: 'restrict',
            pivotTable: '',
        };

        const body = el('div');
        const error = el('p', {
            className: 'dber-note',
            style: 'display:none; color:var(--dber-red); border-color:rgba(251,113,133,.3);',
        });

        let close = () => {};

        const setDefaults = () => {
            const target = derived.byKey.get(state.target);

            if (!target) {
                return;
            }

            if (!state.fieldNameTouched) {
                state.fieldName = suggestedForeignKeyName(key, target.key);
            }

            state.targetKey = target.primaryKey.length === 1
                ? target.primaryKey[0]
                : (Object.keys(this.store.table(target.key)?.fields ?? {})[0] ?? '');

            state.pivotTable = pivotName(key, target.key);
        };

        const refresh = () => {
            const target = derived.byKey.get(state.target);

            setDefaults();
            clear(body);

            const kindSelect = this.select(
                [
                    {
                        value: 'many-to-one',
                        label: 'Belongs to (many-to-one)',
                    },
                    {
                        value: 'one-to-one',
                        label: 'One-to-one (unique foreign key)',
                    },
                    {
                        value: 'many-to-many',
                        label: 'Many-to-many (creates pivot)',
                    },
                ],
                state.kind,
                (value) => {
                    state.kind = value;
                    state.onDelete = value === 'many-to-many' ? 'cascade' : 'restrict';
                    refresh();
                },
            );

            const targetSelect = this.select(
                derived.tables.map((table) => ({
                    value: table.key,
                    label: table.key,
                })),
                state.target,
                (value) => {
                    state.target = value;
                    state.fieldNameTouched = false;
                    state.fieldName = '';
                    refresh();
                },
            );

            const grid = el('div', { className: 'dber-form-grid' }, [
                this.formRow('Relation', kindSelect, true),
                this.formRow('Target table', targetSelect, true),
            ]);

            if (target) {
                if (state.kind === 'many-to-many') {
                    const sourceKeySelect = this.select(
                        Object.keys(this.store.table(key)?.fields ?? {}).map((name) => ({
                            value: name,
                            label: name,
                        })),
                        state.sourceKey,
                        (value) => {
                            state.sourceKey = value;
                        },
                    );
                    const targetKeySelect = this.select(
                        Object.keys(this.store.table(target.key)?.fields ?? {}).map((name) => ({
                            value: name,
                            label: name,
                        })),
                        state.targetKey,
                        (value) => {
                            state.targetKey = value;
                        },
                    );

                    grid.appendChild(this.formRow(`${key} key`, sourceKeySelect));
                    grid.appendChild(this.formRow(`${target.key} key`, targetKeySelect));

                    const pivotInput = this.input({
                        value: state.pivotTable,
                        placeholder: 'role_user',
                    });
                    pivotInput.addEventListener('input', () => {
                        state.pivotTable = pivotInput.value;
                    });
                    grid.appendChild(this.formRow('Pivot table', pivotInput, true));
                } else {
                    const fieldInput = this.input({
                        value: state.fieldName,
                        placeholder: suggestedForeignKeyName(key, target.key),
                    });
                    fieldInput.addEventListener('input', () => {
                        state.fieldName = fieldInput.value;
                        state.fieldNameTouched = true;
                    });

                    const targetKeySelect = this.select(
                        Object.keys(this.store.table(target.key)?.fields ?? {}).map((name) => ({
                            value: name,
                            label: name,
                        })),
                        state.targetKey,
                        (value) => {
                            state.targetKey = value;
                        },
                    );

                    const nullable = this.flag('Nullable', state.nullable, (checked) => {
                        state.nullable = checked;
                    });

                    const onDelete = this.select(
                        ACTIONS,
                        state.onDelete,
                        (value) => {
                            state.onDelete = value;
                        },
                    );

                    grid.appendChild(this.formRow('Foreign key field', fieldInput));
                    grid.appendChild(this.formRow('Target key', targetKeySelect));
                    grid.appendChild(this.formRow('On delete', onDelete, true));
                    grid.appendChild(el('div', {
                        className: 'dber-form-row is-full',
                    }, [
                        el('span', { text: 'Options' }),
                        nullable,
                    ]));
                }
            }

            body.appendChild(grid);
            body.appendChild(error);

            if (!target) {
                error.style.display = 'block';
                error.textContent = 'Select a target table.';
            }
        };

        refresh();

        const createButton = el('button', {
            type: 'button',
            className: 'dber-btn dber-btn-primary',
            text: 'Create relation',
            onclick: () => {
                try {
                    const result = this.store.addRelation(key, {
                        kind: state.kind,
                        target: state.target,
                        fieldName: state.fieldName,
                        nullable: state.nullable,
                        onDelete: state.onDelete,
                        targetKey: state.targetKey,
                        sourceKey: state.sourceKey,
                        pivotTable: state.pivotTable,
                    });

                    close();
                    this.notify(
                        result.pivot
                            ? `Pivot table ${result.table} created.`
                            : `Relation created with ${result.table}.${result.field}.`,
                        'success',
                    );
                } catch (exception) {
                    error.style.display = 'block';
                    error.textContent = exception.message;
                }
            },
        });

        const modal = this.showModal({
            title: 'Add relation',
            body,
            footer: [
                el('button', {
                    type: 'button',
                    className: 'dber-btn',
                    text: 'Cancel',
                    onclick: () => close(),
                }),
                createButton,
            ],
        });

        close = modal.close;
    }

    async deleteField(tableKey, field) {
        const references = this.store.referencesToField(tableKey, field.name);
        const message = references.length === 0
            ? `Delete the field "${field.name}"?`
            : `Delete the field "${field.name}"? ${references.length} foreign key reference(s) will be removed.`;

        const confirmed = await this.confirm({
            title: 'Delete field',
            message,
            confirmLabel: 'Delete field',
        });

        if (!confirmed) {
            return;
        }

        this.store.deleteField(tableKey, field.name);
        this.notify(`Field ${field.name} deleted.`, 'info');
    }

    async deleteTable(key, table) {
        const references = this.store.referencesToTable(key);
        const message = references.length === 0
            ? `Delete the table "${key}" and all of its fields?`
            : `Delete the table "${key}"? ${references.length} foreign key reference(s) will be removed.`;

        const confirmed = await this.confirm({
            title: 'Delete table',
            message,
            confirmLabel: 'Delete table',
        });

        if (!confirmed) {
            return;
        }

        this.store.deleteTable(key);
        this.store.setSelected(null);
        this.notify(`Table ${table.key} deleted.`, 'info');
    }

    confirm({ title, message, confirmLabel = 'Confirm' }) {
        return new Promise((resolve) => {
            let settled = false;

            const finish = (value) => {
                if (settled) {
                    return;
                }

                settled = true;
                modal.close();
                resolve(value);
            };

            const modal = this.showModal({
                title,
                body: el('p', { text: message }),
                footer: [
                    el('button', {
                        type: 'button',
                        className: 'dber-btn',
                        text: 'Cancel',
                        onclick: () => finish(false),
                    }),
                    el('button', {
                        type: 'button',
                        className: 'dber-btn dber-btn-danger',
                        text: confirmLabel,
                        onclick: () => finish(true),
                    }),
                ],
                onClose: () => {
                    if (!settled) {
                        settled = true;
                        resolve(false);
                    }
                },
            });

            window.setTimeout(() => {
                modal.modal.querySelector('.dber-btn-danger')?.focus();
            }, 0);
        });
    }

    showModal({ title, body, footer = [], onClose = () => {} }) {
        const backdrop = el('div', { className: 'dber-modal-backdrop' });
        const modal = el('div', { className: 'dber-modal' });
        let closed = false;

        const close = () => {
            if (closed) {
                return;
            }

            closed = true;
            backdrop.remove();
            document.removeEventListener('keydown', handleKey);
            onClose();
        };

        const handleKey = (event) => {
            if (event.key === 'Escape') {
                close();
            }
        };

        const header = el('div', { className: 'dber-modal-head' }, [
            el('h2', { text: title }),
            el('button', {
                type: 'button',
                className: 'dber-icon-btn',
                title: 'Close',
                text: '×',
                onclick: () => close(),
            }),
        ]);

        const modalBody = el('div', { className: 'dber-modal-body' }, [body]);
        const modalFooter = el('div', { className: 'dber-modal-foot' }, footer);

        modal.appendChild(header);
        modal.appendChild(modalBody);

        if (footer.length > 0) {
            modal.appendChild(modalFooter);
        }

        backdrop.appendChild(modal);
        backdrop.addEventListener('pointerdown', (event) => {
            if (event.target === backdrop) {
                close();
            }
        });
        document.addEventListener('keydown', handleKey);

        this.modalRoot.appendChild(backdrop);
        window.setTimeout(() => {
            modal.querySelector('input, select, textarea, .dber-btn-primary')?.focus();
        }, 0);

        return { close, modal };
    }

    relationCount(derived, key) {
        return relationRows(derived, key).length;
    }

    section(title, children, action = null) {
        const head = el('div', { className: 'dber-section-head' }, [
            el('h3', { className: 'dber-section-title', text: title }),
            action,
        ]);

        return el('section', { className: 'dber-section' }, [head, ...children]);
    }

    formRow(label, control, full = false) {
        return el('label', {
            className: `dber-form-row${full ? ' is-full' : ''}`,
        }, [
            el('span', { text: label }),
            control,
        ]);
    }

    flag(label, checked, onChange) {
        const input = el('input', {
            type: 'checkbox',
            checked,
            disabled: !this.store.editable,
        });
        input.addEventListener('change', () => onChange(input.checked));

        return el('label', { className: 'dber-check' }, [
            input,
            el('span', { text: label }),
        ]);
    }

    input(attributes = {}) {
        return el('input', {
            className: 'dber-input',
            disabled: !this.store.editable,
            ...attributes,
        });
    }

    numberInput(value, minimum, onChange) {
        const input = el('input', {
            className: 'dber-input',
            type: 'number',
            min: minimum,
            value: value ?? '',
            disabled: !this.store.editable,
        });

        input.addEventListener('change', () => {
            const parsed = input.value === '' ? null : Number(input.value);

            if (parsed !== null && (!Number.isInteger(parsed) || parsed < minimum)) {
                this.notify(`Enter an integer greater than or equal to ${minimum}.`, 'error');
                input.value = value ?? '';
                return;
            }

            onChange(parsed);
        });

        return input;
    }

    select(options, value, onChange, disabled = false) {
        const select = el('select', {
            className: 'dber-select',
            disabled,
        });

        const seen = new Set();

        for (const option of options) {
            if (seen.has(option.value)) {
                continue;
            }

            seen.add(option.value);
            select.appendChild(el('option', {
                value: option.value,
                text: option.label,
                selected: option.value === value,
            }));
        }

        if (!seen.has(value)) {
            select.appendChild(el('option', {
                value,
                text: value,
                selected: true,
            }));
        }

        select.value = value;
        select.addEventListener('change', () => onChange(select.value));

        return select;
    }
}

function relationRows(derived, key) {
    const rows = [];

    for (const relation of derived.relations) {
        if (relation.kind === 'many-to-many') {
            if (relation.fromTable === key) {
                rows.push({
                    direction: 'out',
                    kind: 'many-to-many',
                    target: relation.toTable,
                    pivot: relation.pivotTable,
                    label: `↔ ${relation.toTable} via ${relation.pivotTable}`,
                });
            } else if (relation.toTable === key) {
                rows.push({
                    direction: 'in',
                    kind: 'many-to-many',
                    target: relation.fromTable,
                    pivot: relation.pivotTable,
                    label: `↔ ${relation.fromTable} via ${relation.pivotTable}`,
                });
            }

            continue;
        }

        if (relation.fromTable === key) {
            rows.push({
                direction: 'out',
                kind: relation.kind,
                target: relation.toTable,
                label: `→ ${relation.toTable}.${relation.toField}`,
            });
        } else if (relation.toTable === key) {
            rows.push({
                direction: 'in',
                kind: relation.kind === 'one-to-one' ? 'one-to-one' : 'one-to-many',
                target: relation.fromTable,
                label: `← ${relation.fromTable}.${relation.fromField}`,
            });
        }
    }

    return rows;
}

function pill(label, className) {
    return el('span', {
        className: `dber-pill ${className}`,
        text: label,
    });
}

function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(value);
    }

    return value.replace(/["\\]/g, '\\$&');
}
