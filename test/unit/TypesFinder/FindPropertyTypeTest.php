<?php

declare(strict_types=1);

namespace Roave\BetterReflectionTest\TypesFinder;

use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types;
use PhpParser\Builder\Use_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_ as UseStatement;
use PHPUnit\Framework\TestCase;
use Roave\BetterReflection\Reflection\ReflectionProperty;
use Roave\BetterReflection\Reflector\DefaultReflector;
use Roave\BetterReflection\SourceLocator\Type\StringSourceLocator;
use Roave\BetterReflection\TypesFinder\FindPropertyType;
use Roave\BetterReflectionTest\BetterReflectionSingleton;

use function count;
use function sprintf;

/**
 * @covers \Roave\BetterReflection\TypesFinder\FindPropertyType
 */
class FindPropertyTypeTest extends TestCase
{
    /**
     * @return array
     */
    public function propertyTypeProvider(): array
    {
        return [
            ['@var int|string $foo', [Types\Integer::class, Types\String_::class]],
            ['@var array $foo', [Types\Array_::class]],
            ['@var array{foo: string|int} $foo', []],
            ['@var \stdClass $foo', [Types\Object_::class]],
            ['@var int|int[]|int[][] $foo', [Types\Integer::class, Types\Array_::class, Types\Array_::class]],
            ['', []],
        ];
    }

    /**
     * @param list<string> $expectedInstances
     *
     * @dataProvider propertyTypeProvider
     */
    public function testFindPropertyType(string $docBlock, array $expectedInstances): void
    {
        $property = $this->createMock(ReflectionProperty::class);

        $property->expects($this->any())->method('getDocComment')
            ->will($this->returnValue(sprintf("/**\n * %s\n */", $docBlock)));

        $foundTypes = (new FindPropertyType())->__invoke($property, null);

        self::assertCount(count($expectedInstances), $foundTypes);

        foreach ($expectedInstances as $i => $expectedInstance) {
            self::assertInstanceOf($expectedInstance, $foundTypes[$i]);
        }
    }

    public function testNamespaceResolutionForProperty(): void
    {
        $php = '<?php
            namespace MyNamespace;

            use Psr\Log\LoggerInterface;

            class ThingThatLogs
            {
                /**
                 * @var LoggerInterface
                 */
                private $logger;
            }
        ';

        $prop = (new DefaultReflector(new StringSourceLocator($php, BetterReflectionSingleton::instance()->astLocator())))
            ->reflectClass('MyNamespace\ThingThatLogs')
            ->getProperty('logger');

        self::assertSame(['\Psr\Log\LoggerInterface'], $prop->getDocBlockTypeStrings());
    }

    public function testFindPropertyTypeReturnsEmptyArrayWhenNoCommentsNodesFound(): void
    {
        $property = $this->createMock(ReflectionProperty::class);

        $property->expects($this->any())->method('getDocComment')
            ->will($this->returnValue('Nothing here...'));

        $foundTypes = (new FindPropertyType())->__invoke($property, null);

        self::assertSame([], $foundTypes);
    }

    public function testFindPropertyTypeReturnsEmptyArrayWhenNoDocBlockIsPresent(): void
    {
        $property = $this->createMock(ReflectionProperty::class);

        $property->expects(self::once())->method('getDocComment')
            ->will(self::returnValue(''));

        $foundTypes = (new FindPropertyType())->__invoke($property, null);

        self::assertEmpty($foundTypes);
    }

    /**
     * @param list<string> $aliasesToFQCNs indexed by alias
     * @param list<Type>   $expectedTypes
     *
     * @dataProvider aliasedVarTypesProvider
     */
    public function testWillResolveAliasedTypes(
        ?string $namespaceName,
        array $aliasesToFQCNs,
        string $docBlockType,
        array $expectedTypes,
    ): void {
        $docBlock = sprintf("/**\n * @var %s\n */", $docBlockType);

        $property = $this->createMock(ReflectionProperty::class);

        $property
            ->expects($this->once())
            ->method('getDocComment')
            ->will($this->returnValue($docBlock));

        $uses = [];

        foreach ($aliasesToFQCNs as $alias => $fqcn) {
            $uses[] = (new Use_($fqcn, UseStatement::TYPE_NORMAL))
                ->as($alias)
                ->getNode();
        }

        $namespace = new Namespace_($namespaceName ? new Name($namespaceName) : null, $uses);

        self::assertEquals($expectedTypes, (new FindPropertyType())->__invoke($property, $namespace));
    }

    public function aliasedVarTypesProvider(): array
    {
        return [
            'No namespace' => [
                null,
                [
                    'Bar' => 'Bar',
                    'Baz' => 'Taw\\Taz',
                ],
                'Foo|Bar|Baz|Tab',
                [
                    new Types\Object_(new Fqsen('\\Foo')),
                    new Types\Object_(new Fqsen('\\Bar')),
                    new Types\Object_(new Fqsen('\\Taw\\Taz')),
                    new Types\Object_(new Fqsen('\\Tab')),
                ],
            ],
            'Foo' => [
                'Foo',
                [
                    'Bar' => 'Bar',
                    'Baz' => 'Taw\\Taz',
                ],
                'Foo|Bar|Baz|Tab',
                [
                    new Types\Object_(new Fqsen('\\Foo\\Foo')),
                    new Types\Object_(new Fqsen('\\Bar')),
                    new Types\Object_(new Fqsen('\\Taw\\Taz')),
                    new Types\Object_(new Fqsen('\\Foo\\Tab')),
                ],
            ],
        ];
    }
}
