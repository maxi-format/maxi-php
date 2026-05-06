# MAXI Parser

This document covers everything about parsing MAXI text into structured data —
from raw records, through streaming, all the way to typed class instances
(object hydration) using PHP 8 Attributes.

---

## Table of Contents

1. [Overview](#overview)
2. [MAXI File Structure (Quick Recap)](#maxi-file-structure-quick-recap)
3. [`Maxi::parse` — Full In-Memory Parse](#maxiparse--full-in-memory-parse)
4. [`Maxi::stream` — Streaming Parse](#maxistream--streaming-parse)
5. [Parse Result Shape](#parse-result-shape)
6. [Schema-Annotated Classes (PHP Attributes)](#schema-annotated-classes-php-attributes)
7. [`Maxi::parseAs` — Parse into Class Instances](#maxiparseas--parse-into-class-instances)
8. [`Maxi::parseAutoAs` — Auto-Resolve Classes](#maxiparseautoas--auto-resolve-classes)
9. [Reference Resolution during Hydration](#reference-resolution-during-hydration)
10. [Construction Strategies](#construction-strategies)
11. [Options Reference](#options-reference)
12. [Examples](#examples)

---

## Overview

The parser converts MAXI text into one of two output shapes:

| Method                | Output                                                                |
|-----------------------|-----------------------------------------------------------------------|
| `Maxi::parse()`       | `MaxiParseResult` — schema + raw records (positional values)          |
| `Maxi::stream()`      | `MaxiStreamResult` — schema immediately, then a lazy record generator |
| `Maxi::parseAs()`     | `MaxiHydrateResult` — records hydrated into class instances           |
| `Maxi::parseAutoAs()` | Same as `parseAs`, but class → alias map inferred from Attributes     |

All methods are also available as standalone functions in the `Maxi\Api` namespace:
`parseMaxi()`, `streamMaxi()`, `parseMaxiAs()`, `parseMaxiAutoAs()`.

---

## MAXI File Structure (Quick Recap)

```
U:User(id:int|name|email=unknown)    ← type definitions (schema section)
O:Order(id:int|user:U|total:decimal)
###                                   ← separator
U(1|Julie|julie@example.com)          ← records (data section)
O(100|1|49.99)
```

- Everything **above** `###` is the schema section (type defs, directives like `@version`, `@schema`).
- Everything **below** `###` is the records section.
- If no `###` is present, the parser auto-detects whether the input is schema-only or records-only.

---

## `Maxi::parse` — Full In-Memory Parse

```php
use Maxi\Maxi;

$result = Maxi::parse($input, $options);
```

Parses the full input at once. Returns a `MaxiParseResult` containing:

- `$result->schema` — types, directives, imports
- `$result->records` — array of `MaxiRecord` (alias + positional values, schema-typed)
- `$result->warnings` — recoverable issues found during parsing (type coercions, unknown types, constraint violations, etc.)

### What the parser does internally

1. **Split sections** at `###`
2. **Parse schema section** — type definitions, `@version`, `@schema` imports (loaded via `$options['loadSchema']`)
3. **Parse records section** — each record is matched to its type def; values are coerced to the declared type (`int`, `bool`, `decimal`,
   etc.)
4. **Build object registry** — if any field references another type, an internal registry (alias → id → object) is built for reference
   validation
5. **Validate references** — unresolved references emit a warning or throw (depending on `allowForwardReferences`)

---

## `Maxi::stream` — Streaming Parse

For large files where you don't want to hold all records in memory at once.

```php
use Maxi\Maxi;

$stream = Maxi::stream($input, $options);

// Schema is fully available before iterating
$fields = array_map(fn($f) => $f->name, $stream->schema->getType('U')->fields);

// Iterate over records one at a time (PHP generator)
foreach ($stream as $record) {
    echo $record->alias . ': ' . implode(', ', $record->values) . "\n";
}
```

- The schema section is parsed **eagerly** and available immediately on the returned `MaxiStreamResult`.
- Records are yielded **lazily** one at a time as you iterate.
- `$stream->getWarnings()` accumulates warnings for the full session.

### File handle input

`stream()` also accepts a file handle (`resource`):

```php
$fh     = fopen('data.maxi', 'r');
$stream = Maxi::stream($fh);

foreach ($stream as $record) {
    // process each record
}

fclose($fh);
```

---

## Parse Result Shape

### `MaxiParseResult`

```php
$result->schema;    // MaxiSchema — parsed type definitions and directives
$result->records;   // MaxiRecord[] — all records
$result->warnings;  // MaxiWarning[] — { message, code, line }
```

### `MaxiRecord`

```php
$record->alias;      // 'U'
$record->values;     // [1, 'Julie', null] — positional values, schema-coerced
$record->lineNumber; // source line number
```

### `MaxiSchema`

```php
$schema->getType('U');   // → MaxiTypeDef|null
$schema->hasType('U');   // → bool
$schema->types;          // → array<string, MaxiTypeDef>
$schema->version;        // → string
$schema->imports;        // → string[]
```

### `MaxiTypeDef`

```php
$typeDef->alias;        // 'U'
$typeDef->name;         // 'User'
$typeDef->parents;      // ['P']
$typeDef->fields;       // MaxiFieldDef[]
$typeDef->getIdField(); // → MaxiFieldDef|null
```

### `MaxiFieldDef`

```php
$field->name;          // 'email'
$field->typeExpr;      // 'str', 'int', 'U', 'O[]', etc.
$field->annotation;    // 'hex', 'base64', 'email', etc.
$field->constraints;   // ParsedConstraint[]|null
$field->defaultValue;  // 'unknown', 0, etc. — MaxiFieldDef::missing() sentinel if none
$field->isRequired();  // bool
$field->isId();        // bool
```

---

## Schema-Annotated Classes (PHP Attributes)

Before using `Maxi::parseAs()` / `Maxi::parseAutoAs()`, your classes need a schema attached.
PHP 8 Attributes provide a clean, Doctrine-style way to annotate classes.

### Option A: PHP Attributes (recommended)

```php
use Maxi\Attribute\MaxiType;
use Maxi\Attribute\MaxiField;

#[MaxiType(alias: 'U', name: 'User')]
class User
{
    #[MaxiField(typeExpr: 'int', id: true)]
    public int $id;

    #[MaxiField(required: true)]
    public string $name;

    #[MaxiField(annotation: 'email')]
    public ?string $email = null;
}
```

### Option B: `MaxiSchemaRegistry::define()` for external / third-party classes

When you can't modify the class (e.g. it's from a library):

```php
use Maxi\Registry\MaxiSchemaRegistry;

MaxiSchemaRegistry::define(ExternalProduct::class, [
    'alias'  => 'P',
    'fields' => [
        ['name' => 'id',    'typeExpr' => 'int', 'constraints' => [['type' => 'id']]],
        ['name' => 'title'],
        ['name' => 'price', 'typeExpr' => 'decimal'],
    ],
]);
```

### Schema descriptor fields

| Field     | Type       | Description                                                      |
|-----------|------------|------------------------------------------------------------------|
| `alias`   | `string`   | **Required.** Short alias used in records, e.g. `U(...)`         |
| `name`    | `string`   | Optional long name emitted in type definition header             |
| `parents` | `string[]` | Optional parent aliases for inheritance                          |
| `fields`  | `array`    | Field list — order defines serialization / deserialization order |

Each field descriptor:

| Field          | Type     | Description                                                                           |
|----------------|----------|---------------------------------------------------------------------------------------|
| `name`         | `string` | **Required.** Field name                                                              |
| `typeExpr`     | `string` | Type: `int`, `str`, `bool`, `decimal`, `float`, `bytes`, `OtherAlias`, `OtherAlias[]` |
| `annotation`   | `string` | e.g. `hex`, `base64`, `email`                                                         |
| `constraints`  | `array`  | e.g. `[['type' => 'required']]`, `[['type' => 'id']]`                                 |
| `defaultValue` | `mixed`  | Applied when field is omitted from record                                             |

---

## `Maxi::parseAs` — Parse into Class Instances

```php
use Maxi\Maxi;

$result = Maxi::parseAs($input, ['U' => User::class, 'O' => Order::class], $options);
```

### Parameters

| Parameter   | Type                          | Description                                |
|-------------|-------------------------------|--------------------------------------------|
| `$input`    | `string`                      | MAXI text to parse                         |
| `$classMap` | `array<string, class-string>` | Maps each alias to the FQCN to instantiate |
| `$options`  | `array`                       | Same options as `parse()`                  |

### Return value

```php
$result->data;      // array<string, object[]> — alias → hydrated instances
$result->schema;    // MaxiSchema
$result->warnings;  // MaxiWarning[]
```

Only aliases present in `$classMap` are hydrated. Records with other aliases are silently skipped.

---

## `Maxi::parseAutoAs` — Auto-Resolve Classes

Convenience variant — pass an array of classes instead of an alias map.
Each class must have `#[MaxiType]` attributes or be registered via `MaxiSchemaRegistry::define()`.

```php
$result = Maxi::parseAutoAs($input, [User::class, Order::class], $options);
```

Internally reads the alias from each class's `#[MaxiType]` attribute, builds the alias → class map,
then delegates to `parseAs`.

---

## Reference Resolution during Hydration

After all records are hydrated into instances, the hydrator performs a **second pass** to resolve cross-reference fields.

A field is a cross-reference when its `typeExpr` points to another alias in the schema (not a primitive like `int`, `str`, etc.).

**Example:**

```
U:User(id:int|name)
O:Order(id:int|user:U|total:decimal)
###
U(1|Julie)
O(100|1|49.99)
```

After hydration, `$order->user` will be the actual `User` instance for `id=1`, not the scalar `1`.

### What happens step by step

1. All `U` records are hydrated into `User` instances and indexed by their id.
2. All `O` records are hydrated into `Order` instances.
3. The hydrator walks each `Order`'s `user` field — its `typeExpr` is `U`, a known alias.
4. The scalar value `1` is looked up in the `User` instance registry → the `User` instance is found.
5. `$order->user` is replaced with the actual `User` instance.

### Forward references

Forward references work naturally because reference resolution is a **second pass** over all already-parsed records. An `Order` that appears
before the `User` it references will still resolve correctly.

### Unresolved references

If a referenced id is not found among the hydrated instances, the field **stays as the original scalar value**. A warning is also emitted by
the underlying `parse()` call.

---

## Construction Strategies

`parseAs` tries multiple strategies to construct each instance:

| Strategy                                           | When it applies                                                            |
|----------------------------------------------------|----------------------------------------------------------------------------|
| Named constructor args                             | Constructor accepts named parameters: `__construct(int $id, string $name)` |
| Zero-arg + property assignment                     | No-arg constructor; properties set via reflection                          |
| `ReflectionClass::newInstanceWithoutConstructor()` | Constructor throws or requires non-schema args                             |

The hydrator uses PHP Reflection to match field names to constructor parameters or public properties.

---

## Options Reference

| Option                      | Type             | Default     | Description                                                                           |
|-----------------------------|------------------|-------------|---------------------------------------------------------------------------------------|
| `allowAdditionalFields`     | `string`         | `'ignore'`  | Extra fields beyond schema definition: `'ignore'`, `'warning'`, `'error'`             |
| `allowMissingFields`        | `string`         | `'null'`    | Missing required fields — fill with null or reject: `'null'`, `'warning'`, `'error'`  |
| `allowTypeCoercion`         | `string`         | `'coerce'`  | Type mismatches — coerce or reject: `'coerce'`, `'warning'`, `'error'`                |
| `allowConstraintViolations` | `string`         | `'warning'` | Constraint violations: `'warning'`, `'error'`                                         |
| `allowForwardReferences`    | `bool`           | `true`      | Allow references to records not yet seen                                               |
| `allowUnknownTypes`         | `string`         | `'warning'` | Records with an unrecognised type alias: `'ignore'`, `'warning'`, `'error'`            |
| `filename`                  | `string\|null`   | `null`      | Used in error/warning messages for better diagnostics                                  |
| `loadSchema`                | `callable\|null` | `null`      | Resolver for `@schema:` import directives: `fn(string $path): string`                 |

---

## Examples

### 1. Basic `Maxi::parse` — raw records

```php
use Maxi\Maxi;

$input = <<<MAXI
U:User(id:int|name|email=unknown)
###
U(1|Julie|julie@example.com)
U(2|Matt)
MAXI;

$result = Maxi::parse($input);

echo $result->records[0]->alias;     // 'U'
echo $result->records[0]->values[1]; // 'Julie'
echo $result->records[1]->values[2]; // 'unknown' ← default filled in
```

---

### 2. `Maxi::stream` — large files

```php
use Maxi\Maxi;

$stream = Maxi::stream($input);

// Schema is available immediately
$fields = array_map(fn($f) => $f->name, $stream->schema->getType('U')->fields);
print_r($fields); // ['id', 'name', 'email']

// Stream records one at a time
foreach ($stream as $record) {
    print_r($record->values);
}
```

---

### 3. `Maxi::parseAs` — hydrate into class instances

```php
use Maxi\Maxi;
use Maxi\Attribute\MaxiType;
use Maxi\Attribute\MaxiField;

#[MaxiType(alias: 'U', name: 'User')]
class User
{
    #[MaxiField(typeExpr: 'int', id: true)]
    public int $id;

    #[MaxiField]
    public string $name;

    #[MaxiField]
    public ?string $email = null;
}

$input = <<<MAXI
U:User(id:int|name|email)
###
U(1|Julie|julie@example.com)
U(2|Matt|matt@example.com)
MAXI;

$result = Maxi::parseAs($input, ['U' => User::class]);

echo $result->data['U'][0] instanceof User; // true
echo $result->data['U'][0]->name;           // 'Julie'
```

---

### 4. `Maxi::parseAutoAs` — zero-config with Attributes

```php
use Maxi\Maxi;

// Alias is read from #[MaxiType] on each class automatically
$result = Maxi::parseAutoAs($input, [User::class, Order::class]);

echo $result->data['U'][0] instanceof User;  // true
echo $result->data['O'][0] instanceof Order; // true
```

---

### 5. Cross-reference fields resolved to instances

```php
#[MaxiType(alias: 'U', name: 'User')]
class User
{
    #[MaxiField(typeExpr: 'int', id: true)]
    public int $id;

    #[MaxiField]
    public string $name;
}

#[MaxiType(alias: 'O', name: 'Order')]
class Order
{
    #[MaxiField(typeExpr: 'int', id: true)]
    public int $id;

    #[MaxiField(typeExpr: 'U')]
    public int|User $user;

    #[MaxiField(typeExpr: 'decimal')]
    public string $total;
}

$input = <<<MAXI
U:User(id:int|name)
O:Order(id:int|user:U|total:decimal)
###
U(1|Julie)
O(100|1|49.99)
MAXI;

$result = Maxi::parseAutoAs($input, [User::class, Order::class]);

$order = $result->data['O'][0];
echo $order->user instanceof User; // true ← not just the scalar 1
echo $order->user->name;           // 'Julie'
```

---

### 6. Forward references

```php
$input = <<<MAXI
U:User(id:int|name)
O:Order(id:int|user:U|total:decimal)
###
O(100|1|49.99)
U(1|Julie)
MAXI;

$result = Maxi::parseAutoAs($input, [User::class, Order::class]);

// Forward reference resolves correctly because resolution is a second pass
echo $result->data['O'][0]->user instanceof User; // true
```

---

### 7. External schema via `@schema` import

```php
use Maxi\Maxi;

$input = <<<MAXI
@schema:schemas/users.mxs
###
U(1|Julie)
MAXI;

$result = Maxi::parse($input, [
    'loadSchema' => function (string $path): string {
        return file_get_contents(__DIR__ . '/' . $path);
    },
]);
```

---

### 8. Strict-style validation — throws on schema violations

Use `allowAdditionalFields: 'error'` to reject records with extra fields:

```php
use Maxi\Core\MaxiException;

$input = <<<MAXI
U:User(id:int|name)
###
U(1|Julie|extra-field-not-in-schema)
MAXI;

try {
    Maxi::parse($input, ['allowAdditionalFields' => 'error']);
} catch (MaxiException $e) {
    echo $e->errorCode; // 'E006' (SchemaMismatchError)
}
```

---

### 9. `MaxiSchemaRegistry::define` for classes you don't own

```php
use Maxi\Maxi;
use Maxi\Registry\MaxiSchemaRegistry;

// Third-party class — can't add Attributes
MaxiSchemaRegistry::define(ExternalProduct::class, [
    'alias'  => 'P',
    'fields' => [
        ['name' => 'id',    'typeExpr' => 'int', 'constraints' => [['type' => 'id']]],
        ['name' => 'title'],
        ['name' => 'price', 'typeExpr' => 'decimal'],
    ],
]);

$result = Maxi::parseAutoAs($maxi, [ExternalProduct::class]);
echo $result->data['P'][0] instanceof ExternalProduct; // true
```
