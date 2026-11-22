<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\TaskInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/** Guards the stable PHP task interface against accidental signature changes. */
final class PublicApiCompatibilityTest extends TestCase
{
    /** Preserves the TaskInterface method names, parameters, and return types. */
    public function testTaskInterfaceSignaturesRemainBackwardCompatible(): void
    {
        // Arrange
        $interface = new ReflectionClass(TaskInterface::class);

        // Act
        $methodNames = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $interface->getMethods()
        );

        // Assert
        self::assertTrue($interface->isInterface());
        self::assertEqualsCanonicalizing(['execute', 'getType'], $methodNames);

        $execute = $interface->getMethod('execute');
        self::assertSame(['debug', 'silent'], array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $execute->getParameters()
        ));
        self::assertSame(['bool', 'bool'], array_map(
            static fn(ReflectionParameter $parameter): string => self::typeName($parameter->getType()),
            $execute->getParameters()
        ));
        self::assertSame('bool', self::typeName($execute->getReturnType()));

        $getType = $interface->getMethod('getType');
        self::assertSame([], $getType->getParameters());
        self::assertSame('string', self::typeName($getType->getReturnType()));
    }

    /** Converts a reflected named type to its declared name for stable assertions. */
    private static function typeName(?\ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
