<?php

declare(strict_types=1);

namespace Maxi\Core;

/** Appendix B error codes from the MAXI spec. */
final class MaxiErrorCode
{
    // E1xx — Schema definition errors
    public const InvalidSyntaxError = 'E101';
    public const DuplicateTypeError = 'E102';
    public const UnknownDirectiveError = 'E103';
    // E2xx — Type system errors
    public const UnknownTypeError = 'E201';
    public const UndefinedParentError = 'E202';
    public const CircularInheritanceError = 'E203';
    public const UnresolvedReferenceError = 'E204';
    public const DuplicateIdentifierError = 'E205';
    // E3xx — Constraint errors
    public const ConstraintSyntaxError = 'E301';
    public const InvalidConstraintValueError = 'E302';
    public const ConstraintViolationError = 'E303';
    public const ArraySyntaxError = 'E304';
    // E4xx — Data record errors
    public const SchemaMismatchError = 'E401';
    public const TypeMismatchError = 'E402';
    public const MissingRequiredFieldError = 'E403';
    public const InvalidDefaultValueError = 'E404';
    public const UnsupportedBinaryFormatError = 'E405';
    // E5xx — Data type errors
    public const EnumAliasError = 'E501';
    // E6xx — IO / runtime errors
    public const UnsupportedVersionError = 'E601';
    public const SchemaLoadError = 'E602';
    public const StreamError = 'E603';
}
