<?php

declare(strict_types=1);

namespace Roave\BetterReflection\Reflection\StringCast;

use Roave\BetterReflection\Reflection\ReflectionProperty;

use function sprintf;

/**
 * @internal
 */
final class ReflectionPropertyStringCast
{
    public static function toString(ReflectionProperty $propertyReflection): string
    {
        $stateModifier = '';

        if (! $propertyReflection->isStatic()) {
            $stateModifier = $propertyReflection->isDefault() ? ' <default>' : ' <dynamic>';
        }

        $type = $propertyReflection->getType();

        return sprintf(
            'Property [%s %s%s%s%s $%s ]',
            $stateModifier,
            self::visibilityToString($propertyReflection),
            $propertyReflection->isStatic() ? ' static' : '',
            $propertyReflection->isReadOnly() ? ' readonly' : '',
            $type !== null ? sprintf(' %s', $type->__toString()) : '',
            $propertyReflection->getName(),
        );
    }

    private static function visibilityToString(ReflectionProperty $propertyReflection): string
    {
        if ($propertyReflection->isProtected()) {
            return 'protected';
        }

        if ($propertyReflection->isPrivate()) {
            return 'private';
        }

        return 'public';
    }
}
