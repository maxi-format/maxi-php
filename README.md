# maxi-php

PHP 8.0+ library for parsing and dumping **MAXI schema + records**.

Version: `0.1.0`

## Install

```bash
composer require maxi-format/maxi
```

## API overview

| Method                                          | Description                                                             |
|-------------------------------------------------|-------------------------------------------------------------------------|
| `Maxi::parse($input, $options)`                 | Parse MAXI text → `MaxiParseResult` (schema + raw records)              |
| `Maxi::stream($input, $options)`                | Parse schema eagerly, yield records lazily via PHP generator            |
| `Maxi::parseAs($input, $classMap, $options)`    | Parse + hydrate records into class instances                            |
| `Maxi::parseAutoAs($input, $classes, $options)` | Same, with alias inferred from `#[MaxiType]` attributes                 |
| `Maxi::dump($data, $options)`                   | Serialize objects / parse results → MAXI text                           |
| `Maxi::dumpAuto($objects, $options)`            | Same, with schema inferred from `#[MaxiType]`/`#[MaxiField]` attributes |

All methods are also available as standalone functions in the `Maxi\Api` namespace
(e.g. `Maxi\Api\parseMaxi()`).

## Documentation

- **[docs/parser.md](docs/parser.md)** — full parser guide: `Maxi::parse`, `Maxi::stream`, `Maxi::parseAs`, `Maxi::parseAutoAs`, hydration,
  reference resolution, options
- **[docs/dumper.md](docs/dumper.md)** — full dumper guide: `Maxi::dump`, `Maxi::dumpAuto`, schema-annotated classes, references,
  inheritance, options

## Quick start

### Parse

```php
use Maxi\Maxi;

$input = <<<MAXI
U:User(id:int|name|email)
###
U(1|Julie|julie@maxi.org)
U(2|Matt|matt@maxi.org)
MAXI;

$result = Maxi::parse($input);

echo $result->records[0]->values[1]; // 'Julie'
echo $result->schema->getType('U')->name; // 'User'
```

### Parse into class instances

```php
use Maxi\Maxi;
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

$result = Maxi::parseAutoAs($input, [User::class]);

$user = $result->data['U'][0];
echo $user->name;  // 'Julie'
echo $user->email; // 'julie@maxi.org'
```

Or with an explicit alias → class map:

```php
$result = Maxi::parseAs($input, ['U' => User::class]);
```

### Dump

Round-trip from a parse result:

```php
$maxi = Maxi::dump($result);
```

From PHP objects with auto-detected schema:

```php
$maxi = Maxi::dumpAuto([
    new User(id: 1, name: 'Julie', email: 'julie@maxi.org'),
]);
```

With explicit type definitions:

```php
use function Maxi\Api\dumpMaxi;

$maxi = dumpMaxi([['id' => 1, 'name' => 'Julie']], [
    'defaultAlias' => 'U',
    'types' => [[
        'alias'  => 'U',
        'name'   => 'User',
        'fields' => [
            ['name' => 'id', 'typeExpr' => 'int'],
            ['name' => 'name'],
        ],
    ]],
]);
```

### Stream (lazy record parsing)

For large files, use streaming to avoid loading all records into memory at once.
The schema is parsed eagerly; records are yielded one at a time via a PHP generator:

```php
$stream = Maxi::stream($input);

echo $stream->schema->getType('U')->name; // 'User'

foreach ($stream as $record) {
    echo $record->alias;     // 'U'
    echo $record->values[1]; // field values
}
```

`stream()` also accepts a file handle (`resource`):

```php
$fh     = fopen('data.maxi', 'r');
$stream = Maxi::stream($fh);
```

## PHP Attributes — Doctrine-style annotations

Map MAXI types to PHP classes using native PHP 8 Attributes:

```php
use Maxi\Attribute\MaxiType;
use Maxi\Attribute\MaxiField;

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
```

`MaxiField` supports the following options:

| Option         | Type      | Description                                                     |
|----------------|-----------|-----------------------------------------------------------------|
| `typeExpr`     | `?string` | MAXI type expression (`'int'`, `'str[]'`, `'enum[a,b]'`, `'U'`) |
| `annotation`   | `?string` | Type annotation (`'email'`, `'base64'`, `'hex'`)                |
| `required`     | `bool`    | Adds the `!` (required) constraint                              |
| `id`           | `bool`    | Marks this field as the record identifier                       |
| `defaultValue` | `mixed`   | Default value when field is omitted                             |
| `name`         | `?string` | Override the serialized field name (defaults to property name)  |
| `constraints`  | `?string` | Raw constraint string (e.g. `'>=3,<=50'`)                       |

### Schema registry for third-party classes

For classes you don't own (no Attributes), register a schema descriptor manually:

```php
use Maxi\Registry\MaxiSchemaRegistry;

MaxiSchemaRegistry::define(ThirdPartyUser::class, [
    'alias'  => 'U',
    'name'   => 'User',
    'fields' => [
        ['name' => 'id',    'typeExpr' => 'int', 'constraints' => [['type' => 'id']]],
        ['name' => 'name'],
        ['name' => 'email', 'annotation' => 'email'],
    ],
]);
```

## Error handling

All parse/dump errors throw `Maxi\Core\MaxiException` which carries structured context:

```php
use Maxi\Core\MaxiException;

try {
    Maxi::parse($input, ['mode' => 'strict']);
} catch (MaxiException $e) {
    echo $e->errorCode;     // e.g. 'E007'
    echo $e->maxi_line;     // line number where the error occurred
    echo $e->maxi_filename; // filename (if provided via options)
    echo $e->getMessage();  // human-readable description
}
```

### Error codes

| Code | Name                         | Description                      |
|------|------------------------------|----------------------------------|
| E001 | UnsupportedVersionError      | `@version` value not supported   |
| E002 | DuplicateTypeError           | Duplicate type alias in schema   |
| E003 | UnknownTypeError             | Record uses undefined type alias |
| E005 | InvalidSyntaxError           | General syntax error             |
| E006 | SchemaMismatchError          | Too many values for type fields  |
| E007 | TypeMismatchError            | Value doesn't match field type   |
| E008 | ConstraintViolationError     | Value violates a constraint      |
| E009 | UnresolvedReferenceError     | Reference ID not found           |
| E010 | CircularInheritanceError     | Circular type inheritance        |
| E011 | MissingRequiredFieldError    | Required field is null/missing   |
| E012 | InvalidConstraintValueError  | Invalid constraint value         |
| E013 | UndefinedParentError         | Parent type not defined          |
| E014 | ConstraintSyntaxError        | Malformed constraint             |
| E015 | ArraySyntaxError             | Malformed array literal          |
| E016 | DuplicateIdentifierError     | Duplicate id in records          |
| E017 | UnsupportedBinaryFormatError | Invalid bytes annotation         |
| E018 | InvalidDefaultValueError     | Default value type mismatch      |

### Lax vs Strict mode

By default the parser runs in **lax** mode — type mismatches, missing fields, and
unresolved references generate warnings instead of exceptions:

```php
$result = Maxi::parse($input); // lax (default)

foreach ($result->warnings as $w) {
    echo "[{$w->code}] {$w->message} (line {$w->line})\n";
}
```

In **strict** mode all issues become fatal:

```php
$result = Maxi::parse($input, ['mode' => 'strict']);
```

## External schema imports

MAXI files can import type definitions from external `.mxs` schema files.
Provide a `loadSchema` callback to resolve them:

```php
$result = Maxi::parse($input, [
    'loadSchema' => function (string $path): string {
        return file_get_contents(__DIR__ . '/schemas/' . $path);
    },
]);
```

## MAXI format (quick reference)

```
U:User(id:int|name|email=unknown)   ← type definition
###                                  ← section separator
U(1|Julie|~)                         ← record  (~ = explicit null)
```

- Omitted trailing fields use their declared default value.
- `~` sets a field to explicit `null`, even if it has a default.
- Arrays: `[1,2,3]` — Maps: `{key:value,key2:value2}`
- Inline objects: `O(100|(1|Julie|julie@maxi.org)|99.99)`
- See the [MAXI spec](../maxi/SPEC.md) for the full format definition.

## Test

```bash
composer test
```

## License

Released under the [MIT License](./LICENSE).
