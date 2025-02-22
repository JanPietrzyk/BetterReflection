<?php

declare(strict_types=1);

namespace Roave\BetterReflection\SourceLocator\Type;

use InvalidArgumentException;
use ReflectionClass;
use Roave\BetterReflection\Identifier\Identifier;
use Roave\BetterReflection\SourceLocator\Ast\Locator;
use Roave\BetterReflection\SourceLocator\Exception\InvalidFileLocation;
use Roave\BetterReflection\SourceLocator\Located\EvaledLocatedSource;
use Roave\BetterReflection\SourceLocator\Located\LocatedSource;
use Roave\BetterReflection\SourceLocator\SourceStubber\SourceStubber;

use function class_exists;
use function interface_exists;
use function is_file;
use function trait_exists;

final class EvaledCodeSourceLocator extends AbstractSourceLocator
{
    public function __construct(Locator $astLocator, private SourceStubber $stubber)
    {
        parent::__construct($astLocator);
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException
     * @throws InvalidFileLocation
     */
    protected function createLocatedSource(Identifier $identifier): ?LocatedSource
    {
        $classReflection = $this->getInternalReflectionClass($identifier);

        if ($classReflection === null) {
            return null;
        }

        $stubData = $this->stubber->generateClassStub($classReflection->getName());

        if ($stubData === null) {
            return null;
        }

        return new EvaledLocatedSource($stubData->getStub(), $classReflection->getName());
    }

    private function getInternalReflectionClass(Identifier $identifier): ?ReflectionClass
    {
        if (! $identifier->isClass()) {
            return null;
        }

        /** @psalm-var class-string|trait-string $name */
        $name = $identifier->getName();

        if (! (class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false))) {
            return null; // not an available internal class
        }

        $reflection = new ReflectionClass($name);
        $sourceFile = $reflection->getFileName();

        return $sourceFile && is_file($sourceFile)
            ? null : $reflection;
    }
}
