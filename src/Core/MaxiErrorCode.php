<?php

declare(strict_types=1);

namespace Maxi\Core;

/** Appendix B error codes from the MAXI spec. */
final class MaxiErrorCode
{
    public const UnsupportedVersionError = 'E001';
    public const DuplicateTypeError = 'E002';
    public const UnknownTypeError = 'E003';
    public const UnknownDirectiveError = 'E004';
    public const InvalidSyntaxError = 'E005';
    public const SchemaMismatchError = 'E006';
    public const TypeMismatchError = 'E007';
    public const ConstraintViolationError = 'E008';
    public const UnresolvedReferenceError = 'E009';
    public const CircularInheritanceError = 'E010';
    public const MissingRequiredFieldError = 'E011';
    public const InvalidConstraintValueError = 'E012';
    public const UndefinedParentError = 'E013';
    public const ConstraintSyntaxError = 'E014';
    public const ArraySyntaxError = 'E015';
    public const DuplicateIdentifierError = 'E016';
    public const UnsupportedBinaryFormatError = 'E017';
    public const InvalidDefaultValueError = 'E018';
    public const StreamError = 'E019';
    public const SchemaLoadError = 'E020';
}
