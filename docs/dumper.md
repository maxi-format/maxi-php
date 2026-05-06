# MAXI Dumper

The `Maxi::dump()` method serializes PHP objects, arrays, or parse results back into MAXI text format. This document explains how it works,
what schema input is required, and how references and inline objects are handled.

---

## Table of Contents

1. [Overview](#overview)
2. [Input Modes](#input-modes)
3. [Schema Input](#schema-input)
4. [Schema-Annotated Classes (PHP Attributes)](#schema-annotated-classes-php-attributes)
5. [Auto-Dump: `Maxi::dumpAuto`](#auto-dump-maxidumpauto)
6. [Reference Collection](#reference-collection)
7. [Inline Objects vs. References](#inline-objects-vs-references)
8. [Inheritance](#inheritance)
9. [Options Reference](#options-reference)
10. [Examples](#examples)

---

## Overview

```php
use Maxi\Maxi;

$maxi = Maxi::dump($data, $options);
```

`Maxi::dump()` accepts data in several formats and optional configuration through `$options`. It emits a MAXI string that may contain:

- Directives (`@version`, `@schema`)
- Type definitions (schema section)
- A `###` separator
- Records (data section)

---

## Input Modes

`Maxi::dump()` detects the input shape and routes to the appropriate internal path:

| Input shape                 | Behavior                                                                |
|-----------------------------|-------------------------------------------------------------------------|
| `MaxiParseResult` object    | Round-trip path — re-emits schema and records exactly as parsed         |
| Array of associative arrays | Requires `$options['defaultAlias']`; type info from `$options['types']` |
| `[alias => rows[]]` map     | Each key is a record alias; type info from `$options['types']`          |

### Round-trip (parse result)

If you pass the result of `Maxi::parse()` directly, the dumper re-emits:

- The schema (types, directives, imports)
- All records in order, using the parsed values directly

```php
$result = Maxi::parse($input);
$roundTripped = Maxi::dump($result);
```

### Plain arrays / objects

For regular PHP arrays, the dumper needs a schema from `$options['types']` to:

- Determine field order
- Emit type definitions
- Handle typed references and inline objects

---

## Schema Input

The dumper does **not** infer schema from array shapes. You must supply it explicitly through `$options['types']`.

`$options['types']` is an array of type descriptors:

```php
[
    'alias'  => 'U',          // short alias used in records
    'name'   => 'User',       // optional long name for type definition header
    'parents'=> ['P'],        // optional parent aliases for inheritance
    'fields' => [
        ['name' => 'id', 'typeExpr' => 'int', 'constraints' => [['type' => 'id']]],
        ['name' => 'name'],
        ['name' => 'email', 'defaultValue' => 'unknown'],
    ],
]
```

Each field can have:

- `name` — field name (required)
- `typeExpr` — type string, e.g. `int`, `str`, `bool`, `decimal`, `bytes`, `OtherAlias`, `OtherAlias[]`
- `annotation` — e.g. `hex` for bytes fields
- `constraints` — e.g. `[['type' => 'required']]`, `[['type' => 'id']]`
- `elementConstraints` — constraints applied to individual array elements (for `type[]` fields)
- `defaultValue` — used in type definition and when trimming trailing empty fields

### External schema file

If you have an external `.mxs` schema file, you can reference it instead of embedding types:

```php
Maxi::dump($data, [
    'defaultAlias' => 'U',
    'schemaFile'   => 'schemas/users.mxs',
    'includeTypes' => false,
]);
// Output:
// @schema:schemas/users.mxs
// ###
// U(1|Julie)
```

---

## Schema-Annotated Classes (PHP Attributes)

Instead of passing `$options['types']` manually every time, you can attach schema metadata
directly to your classes using PHP 8 Attributes and let the dumper discover it automatically.

```php
use Maxi\Attribute\MaxiType;
use Maxi\Attribute\MaxiField;

#[MaxiType(alias: 'U', name: 'User')]
class User
{
    #[MaxiField(typeExpr: 'int', id: true)]
    public int $id;

    #[MaxiField]
    public string $name;

    #[MaxiField(defaultValue: 'unknown')]
    public ?string $email = null;
}
```

### `MaxiSchemaRegistry::define()` for external / third-party classes

When you can't modify the class (e.g. it's from a library):

```php
use Maxi\Registry\MaxiSchemaRegistry;

MaxiSchemaRegistry::define(SomeExternalClass::class, [
    'alias'  => 'E',
    'fields' => [
        ['name' => 'id', 'typeExpr' => 'int'],
        ['name' => 'label'],
    ],
]);
```

---

## Auto-Dump: `Maxi::dumpAuto`

When your classes have `#[MaxiType]`/`#[MaxiField]` Attributes (or are registered via `MaxiSchemaRegistry::define()`),
use `Maxi::dumpAuto()` instead of `Maxi::dump()` — no `$options['types']` or `$options['defaultAlias']` needed.

```php
use Maxi\Maxi;

// Array of instances — alias resolved from the class schema
$maxi = Maxi::dumpAuto([new User(id: 1, name: 'Julie')]);

// Multi-type map
$maxi = Maxi::dumpAuto([
    'U' => [new User(id: 1, name: 'Julie')],
    'O' => [new Order(id: 100, total: '49.99')],
]);
```

### How schema collection works

1. For each object in the input, `dumpAuto()` looks up the class's schema via `MaxiSchemaRegistry::get()`.
2. It then recurses into all typed nested fields to collect schemas for referenced types
   (e.g. an `Address` nested inside a `Customer` is picked up automatically).
3. All collected schemas are merged with any `$options['types']` you supply (caller wins on conflict).
4. The merged types are forwarded to the existing `dump()` pipeline.

### Mixing with manual `$options['types']`

You can override or extend the auto-collected types:

```php
Maxi::dumpAuto($users, [
    'types' => [
        ['alias' => 'U', 'name' => 'CustomUser', 'fields' => [...]],
    ],
]);
```

All `dump()` options (`multiline`, `includeTypes`, `collectReferences`, `schemaFile`, etc.)
are supported and forwarded unchanged.

---

## Reference Collection

When `$options['collectReferences']` is `true` (the default), the dumper automatically promotes nested objects into top-level records — if
the nested type has an `id` field in its schema.

**How it works:**

1. For each object to dump, the dumper walks all fields that have a typed `typeExpr` pointing to another type.
2. If that nested type has an `id` field and the nested object has a value for it, the object is promoted to its own top-level record.
3. In the parent record, the field value is replaced with just the `id`.

This happens iteratively — deeply nested objects are also promoted.

**When `collectReferences: false`** — nested typed objects are serialized inline as `(val1|val2|...)` regardless of whether they have an id.

---

## Inline Objects vs. References

Consider a `Customer` with a `shippingAddress` field of type `Address`:

| Case                                                             | Output                                                                       |
|------------------------------------------------------------------|------------------------------------------------------------------------------|
| `Address` has an `id` field, `collectReferences: true` (default) | Customer record stores the address id; a separate `A(...)` record is emitted |
| `Address` has an `id` field, `collectReferences: false`          | Customer record stores the address inline: `(A1\|123 Main\|NYC)`             |
| `Address` has **no** `id` field                                  | Always inlined as `(val1\|val2)`                                             |

---

## Inheritance

If a type has `parents`, the dumper resolves inherited fields before serializing. Parent fields are prepended to the type's own fields, in
order of declaration, with duplicates skipped.

This resolution happens once at the start of the dump pipeline via `resolveInheritanceForDump`.

---

## Options Reference

| Option              | Type              | Default | Description                                                      |
|---------------------|-------------------|---------|------------------------------------------------------------------|
| `defaultAlias`      | `string`          | —       | Required when input is a plain array of objects                  |
| `types`             | `array`           | —       | Type definitions used for field order, type defs, and references |
| `includeTypes`      | `bool`            | `true`  | Whether to emit type definitions above `###`                     |
| `schemaFile`        | `string`          | —       | Emit `@schema:<path>` import directive                           |
| `version`           | `string`          | —       | Emit `@version:<x>` if not `1.0.0`                               |
| `multiline`         | `bool`            | `false` | Pretty-print type defs and records across multiple lines         |
| `collectReferences` | `bool`            | `true`  | Promote nested typed objects with an `id` into top-level records |

---

## Examples

### 1. Array of objects with inline type definitions

```php
use Maxi\Maxi;

$users = [
    ['id' => 1, 'name' => 'Julie'],
    ['id' => 2, 'name' => 'Matt', 'email' => null],
];

$maxi = Maxi::dump($users, [
    'defaultAlias' => 'U',
    'types' => [[
        'alias'  => 'U',
        'name'   => 'User',
        'fields' => [
            ['name' => 'id', 'typeExpr' => 'int'],
            ['name' => 'name'],
            ['name' => 'email', 'defaultValue' => 'unknown'],
        ],
    ]],
]);
```

Output:

```
U:User(id:int|name|email=unknown)
###
U(1|Julie)
U(2|Matt|~)
```

Note: `email` is omitted from the first record because it's a trailing empty field. The second record has `~` (explicit null).

---

### 2. Alias map — multiple types

```php
$data = [
    'U' => [['id' => 1, 'name' => 'Julie']],
    'O' => [['id' => 100, 'userId' => 1, 'total' => '49.99']],
];

$maxi = Maxi::dump($data, [
    'types' => [
        ['alias' => 'U', 'name' => 'User', 'fields' => [
            ['name' => 'id', 'typeExpr' => 'int'],
            ['name' => 'name'],
        ]],
        ['alias' => 'O', 'name' => 'Order', 'fields' => [
            ['name' => 'id',     'typeExpr' => 'int'],
            ['name' => 'userId', 'typeExpr' => 'int'],
            ['name' => 'total',  'typeExpr' => 'decimal'],
        ]],
    ],
]);
```

Output:

```
U:User(id:int|name)
O:Order(id:int|userId:int|total:decimal)
###
U(1|Julie)
O(100|1|49.99)
```

---

### 3. Nested referenced objects (collectReferences: true)

```php
$address   = ['id' => 'A1', 'street' => '123 Main St', 'city' => 'NYC'];
$customers = [['id' => 'C1', 'name' => 'John', 'shippingAddress' => $address]];

$maxi = Maxi::dump($customers, [
    'defaultAlias' => 'C',
    'types' => [
        ['alias' => 'C', 'name' => 'Customer', 'fields' => [
            ['name' => 'id'],
            ['name' => 'name'],
            ['name' => 'shippingAddress', 'typeExpr' => 'A'],
        ]],
        ['alias' => 'A', 'name' => 'Address', 'fields' => [
            ['name' => 'id'],
            ['name' => 'street'],
            ['name' => 'city'],
        ]],
    ],
]);
```

Output:

```
C:Customer(id|name|shippingAddress:A)
A:Address(id|street|city)
###
C(C1|John|A1)
A(A1|"123 Main St"|NYC)
```

The `shippingAddress` field is replaced with just `A1` (the id), and a separate `A(...)` record is emitted.

---

### 4. Nested inline objects (collectReferences: false)

Same data as above but with `collectReferences: false`:

```php
$maxi = Maxi::dump($customers, [
    'defaultAlias'      => 'C',
    'types'             => [ /* same as above */ ],
    'collectReferences' => false,
]);
```

Output:

```
C:Customer(id|name|shippingAddress:A)
A:Address(id|street|city)
###
C(C1|John|(A1|"123 Main St"|NYC))
```

The address is now inlined inside the customer record.

---

### 5. Inheritance

```php
$data = ['E' => [['id' => 1, 'name' => 'Alice', 'department' => 'Engineering']]];

$maxi = Maxi::dump($data, [
    'types' => [
        ['alias' => 'P', 'name' => 'Person', 'fields' => [
            ['name' => 'id', 'typeExpr' => 'int'],
            ['name' => 'name'],
        ]],
        ['alias' => 'E', 'name' => 'Employee', 'parents' => ['P'], 'fields' => [
            ['name' => 'department'],
        ]],
    ],
]);
```

Output:

```
P:Person(id:int|name)
E:Employee<P>(department)
###
E(1|Alice|Engineering)
```

The `Employee` record emits all three fields (`id`, `name` from `Person`; `department` own) in the correct inherited order.

---

### 6. Round-trip a parse result

```php
use Maxi\Maxi;

$input = <<<MAXI
U:User(id:int|name|email=unknown)
###
U(1|Julie)
U(2|Matt|~)
MAXI;

$result = Maxi::parse($input);
$output = Maxi::dump($result);
// $output ≈ $input (equivalent modulo whitespace)
```

---

### 7. Multiline pretty-print

```php
$maxi = Maxi::dump($users, [
    'defaultAlias' => 'U',
    'types'        => $userTypes,
    'multiline'    => true,
]);
```

Output:

```
U:User(
  id:int|
  name|
  email=unknown
)
###
U(
  1|
  Julie
)
```

---

### 8. External schema reference (no inline types)

```php
$maxi = Maxi::dump(['id' => 1, 'name' => 'Julie'], [
    'defaultAlias' => 'U',
    'schemaFile'   => 'schemas/users.mxs',
    'includeTypes' => false,
]);
```

Output:

```
@schema:schemas/users.mxs
###
U(1|Julie)
```

---

### 9. `Maxi::dumpAuto` — zero-config dump from Attribute-annotated classes

```php
use Maxi\Maxi;

#[MaxiType(alias: 'U', name: 'User')]
class User
{
    #[MaxiField(typeExpr: 'int', id: true)]
    public int $id;

    #[MaxiField]
    public string $name;

    #[MaxiField(defaultValue: 'unknown')]
    public ?string $email = null;
}

$maxi = Maxi::dumpAuto([
    new User(id: 1, name: 'Julie'),
    new User(id: 2, name: 'Matt'),
]);
```

Output:

```
U:User(id:int|name|email=unknown)
###
U(1|Julie)
U(2|Matt)
```

---

### 10. `Maxi::dumpAuto` — nested referenced objects auto-collected

When a nested object's class also has `#[MaxiType]` Attributes, its schema is discovered and
its instances are promoted to top-level records automatically.

```php
#[MaxiType(alias: 'A', name: 'Address')]
class Address
{
    #[MaxiField(id: true)]
    public string $id;

    #[MaxiField]
    public string $street;

    #[MaxiField]
    public string $city;
}

#[MaxiType(alias: 'C', name: 'Customer')]
class Customer
{
    #[MaxiField(id: true)]
    public string $id;

    #[MaxiField]
    public string $name;

    #[MaxiField(typeExpr: 'A')]
    public string|Address $address;
}

$addr = new Address(id: 'A1', street: '123 Main St', city: 'NYC');
$maxi = Maxi::dumpAuto([new Customer(id: 'C1', name: 'John', address: $addr)]);
```

Output:

```
C:Customer(id|name|address:A)
A:Address(id|street|city)
###
C(C1|John|A1)
A(A1|"123 Main St"|NYC)
```
