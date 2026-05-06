<?php

declare(strict_types=1);

namespace Maxi\Attribute;

/**
 * Marks a PHP property as a MAXI field.
 * Place this attribute on a property of a class that also carries #[MaxiType].
 *
 * @example
 * #[MaxiField(typeExpr: 'int', id: true, required: true)]
 * public int $id;
 *
 * #[MaxiField(required: true, constraints: '>=3,<=50')]
 * public string $name;
 *
 * #[MaxiField(annotation: 'email')]
 * public ?string $email = null;
 *
 * #[MaxiField(typeExpr: 'enum[admin,user,guest]', defaultValue: 'guest')]
 * public string $role = 'guest';
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class MaxiField
{
    /** Sentinel: "no default value specified". */
    public static mixed $MISSING = null;
    private static bool $init = false;

    public static function missing(): mixed
    {
        if (!self::$init) {
            self::$MISSING = new \stdClass();
            self::$init = true;
        }
        return self::$MISSING;
    }

    /**
     * @param string|null $typeExpr MAXI type expression (e.g. 'int', 'str[]', 'enum[a,b]')
     * @param string|null $annotation Type annotation (e.g. 'email', 'base64')
     * @param bool $required Adds the `!` (required) constraint
     * @param bool $id Marks this field as the record identifier (`id` constraint)
     * @param mixed $defaultValue Default value; use MaxiField::missing() sentinel for "none"
     * @param string|null $name Override the serialised field name (defaults to property name)
     * @param string|null $constraints Raw constraint string (e.g. '>=3,<=50')
     */
    public function __construct(
        public readonly ?string $typeExpr = null,
        public readonly ?string $annotation = null,
        public readonly bool    $required = false,
        public readonly bool    $id = false,
        public readonly mixed   $defaultValue = null,
        public readonly ?string $name = null,
        public readonly ?string $constraints = null,
    ) {
    }
}

MaxiField::missing();

