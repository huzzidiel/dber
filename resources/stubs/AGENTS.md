# Dber — schema editing guide for AI agents

This directory contains the database schema diagram for the Laravel project.

`base.json` is the **single source of truth**. The browser viewer/editor and the
AI context export are generated from it. Do not create a second schema file and
do not store generated documentation inside the schema.

## Files

- `base.json` — editable schema. This is the only file you normally change.
- `schema.json` — JSON Schema for editor autocomplete and validation.
- `AGENTS.md` — this guide. Do not copy it into the schema.

## Validate changes

```bash
php artisan dber:validate
php artisan dber:export context
```

`dber:validate` must pass before you finish. Warnings do not block saving, but
they usually point to a mistake.

## Structure

```json
{
    "$schema": "./schema.json",
    "schema_version": 1,
    "name": "My application",
    "database": "mysql",
    "tables": {
        "orders": {
            "model": "App\\Models\\Order",
            "primary_key": ["id"],
            "fields": {
                "id": {
                    "type": "bigint",
                    "unsigned": true,
                    "auto_increment": true
                },
                "user_id": {
                    "type": "bigint",
                    "unsigned": true,
                    "references": {
                        "table": "users",
                        "field": "id",
                        "on_delete": "cascade"
                    }
                }
            }
        }
    }
}
```

## Global rules

1. `tables` is an object keyed by the physical table name. Use `snake_case`.
2. `fields` is an object keyed by the physical column name. Use `snake_case`.
3. Keep `schema_version` at `1`. Newer versions are rejected by the package.
4. `primary_key` is always an array. Use multiple entries for composite keys.
5. A foreign key is declared **once**, on the referencing field, inside
   `references`. Never add the inverse relation to the other table.
6. Relationships are derived automatically:
   - `field.references` → many-to-one (or one-to-one when the field is unique).
   - The inverse one-to-many is implied.
   - A pivot table with two foreign keys → many-to-many.
7. Mark join tables with `"pivot": true`. Their `primary_key` normally lists
   both foreign keys.
8. Timestamps (`created_at`, `updated_at`) and soft deletes (`deleted_at`) are
   explicit fields. There are no hidden conventions. Keep `created_at`,
   `updated_at` and `deleted_at` at the end of the field list, in that order.
9. `description` is for humans only. Do not add AI instructions, prompts or
   comments table by table.
10. Do not duplicate information that can be derived. If a relation is expressed
    by a foreign key, do not add a parallel `relations` list.
11. Preserve unrelated tables and formatting. Prefer small, atomic changes.
12. Tables with `model_type` and `model_id` are polymorphic. Keep both columns,
    but do not add a foreign key to `model_id`; it can point to any model. Ask if
    a concrete relation needs to be represented.

## Field properties

| Property | Meaning |
| --- | --- |
| `type` | Canonical type. Required. |
| `length` | Length for `string`, `char`, `binary`. |
| `precision`, `scale` | Numeric precision for `decimal`, `float`, `double`. |
| `nullable` | Column accepts `NULL`. Defaults to `false`. |
| `unique` | Column has a unique constraint. |
| `unsigned` | Unsigned numeric column. |
| `auto_increment` | Auto-increment integer, normally the primary key. |
| `default` | Scalar default. Omit the key when there is no default. |
| `values` | Allowed values for `enum` and `set`. |
| `references` | Foreign key target: `table`, `field`, optional `on_delete` and `on_update`. |

Referential actions: `cascade`, `restrict`, `set_null`, `no_action`,
`set_default`.

### Types

`bigint`, `integer`, `mediumint`, `smallint`, `tinyint`, `string`, `char`,
`text`, `mediumtext`, `longtext`, `boolean`, `decimal`, `float`, `double`,
`date`, `datetime`, `timestamp`, `time`, `year`, `json`, `uuid`, `ulid`,
`binary`, `enum`, `set`.

The generated AI context includes the equivalent Laravel migration method for every
type. Run `php artisan dber:export context` when you need that mapping.

## Common tasks

### Add a table

Add an entry under `tables` with a primary key and at least one field. Add
`model` when the project follows `App\Models\...` conventions.

### Add a column

Add an entry under the table's `fields`. Include `length`, `precision`/`scale`,
`values`, `nullable`, `unique`, `unsigned`, `auto_increment` or `default` only
when they apply.

### Add a one-to-many / many-to-one relation

Add the foreign key field to the child table and point it at the parent:

```json
"user_id": {
    "type": "bigint",
    "unsigned": true,
    "references": {
        "table": "users",
        "field": "id",
        "on_delete": "restrict"
    }
}
```

Do not add a `users` → `orders` field or a reverse relation entry.

### Add a one-to-one relation

Use a foreign key with `"unique": true`.

### Add a many-to-many relation

Create a pivot table, mark it with `"pivot": true`, add both foreign keys and
make the pair the primary key:

```json
"role_has_permissions": {
    "pivot": true,
    "primary_key": ["permission_id", "role_id"],
    "fields": {
        "permission_id": {
            "type": "bigint",
            "unsigned": true,
            "references": {
                "table": "permissions",
                "field": "id",
                "on_delete": "cascade"
            }
        },
        "role_id": {
            "type": "bigint",
            "unsigned": true,
            "references": {
                "table": "roles",
                "field": "id",
                "on_delete": "cascade"
            }
        }
    }
}
```

### Self reference

A table may reference itself. For example `categories.parent_id` references
`categories.id` with `on_delete: "set_null"` and `nullable: true`.

## Indexes

Composite indexes are optional and live beside the fields:

```json
"indexes": [
    {
        "columns": ["user_id", "created_at"]
    },
    {
        "columns": ["email"],
        "unique": true
    }
]
```

Every index column must exist in `fields`. The primary key is declared with
`primary_key`, not as an index. A single-column `unique` index is equivalent to
the field's `"unique": true` for relationship cardinality, but prefer the field
flag when the constraint belongs to the column.

## When to ask instead of guessing

If a requested relation cannot be represented with a foreign key (for example a
polymorphic relation), stop and ask the developer. Do not invent a structure
that the schema cannot validate.
