<?php

declare(strict_types=1);

namespace Roave\BetterReflection\Reflection;

use Closure;
use phpDocumentor\Reflection\Type;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\Yield_ as YieldNode;
use PhpParser\Node\Expr\YieldFrom as YieldFromNode;
use PhpParser\Node\Param as ParamNode;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard as StandardPrettyPrinter;
use PhpParser\PrettyPrinterAbstract;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Identifier\Exception\InvalidIdentifierName;
use Roave\BetterReflection\Identifier\Identifier;
use Roave\BetterReflection\Identifier\IdentifierType;
use Roave\BetterReflection\Reflection\Annotation\AnnotationHelper;
use Roave\BetterReflection\Reflection\Attribute\ReflectionAttributeHelper;
use Roave\BetterReflection\Reflection\Exception\InvalidArrowFunctionBodyNode;
use Roave\BetterReflection\Reflection\Exception\Uncloneable;
use Roave\BetterReflection\SourceLocator\Ast\Exception\ParseToAstFailure;
use Roave\BetterReflection\SourceLocator\Located\LocatedSource;
use Roave\BetterReflection\SourceLocator\Type\ClosureSourceLocator;
use Roave\BetterReflection\TypesFinder\FindReturnType;
use Roave\BetterReflection\Util\CalculateReflectionColumn;
use Roave\BetterReflection\Util\GetLastDocComment;
use Roave\BetterReflection\Util\Visitor\ReturnNodeVisitor;

use function array_filter;
use function assert;
use function count;
use function is_array;
use function is_string;
use function strtolower;

trait ReflectionFunctionAbstract
{
    private static ?Parser $parser;

    abstract public function __toString(): string;

    abstract public function getShortName(): string;

    /**
     * Get the "full" name of the function (e.g. for A\B\foo, this will return
     * "A\B\foo").
     */
    public function getName(): string
    {
        if (! $this->inNamespace()) {
            return $this->getShortName();
        }

        return $this->getNamespaceName() . '\\' . $this->getShortName();
    }

    /**
     * Get the "namespace" name of the function (e.g. for A\B\foo, this will
     * return "A\B").
     */
    public function getNamespaceName(): string
    {
        return $this->declaringNamespace?->name?->toString() ?? '';
    }

    /**
     * Decide if this function is part of a namespace. Returns false if the class
     * is in the global namespace or does not have a specified namespace.
     */
    public function inNamespace(): bool
    {
        return $this->declaringNamespace !== null
            && $this->declaringNamespace->name !== null;
    }

    /**
     * Get the number of parameters for this class.
     */
    public function getNumberOfParameters(): int
    {
        return count($this->getParameters());
    }

    /**
     * Get the number of required parameters for this method.
     */
    public function getNumberOfRequiredParameters(): int
    {
        return count(array_filter(
            $this->getParameters(),
            static fn (ReflectionParameter $p): bool => ! $p->isOptional(),
        ));
    }

    /**
     * Get an array list of the parameters for this method signature, as an
     * array of ReflectionParameter instances.
     *
     * @return list<ReflectionParameter>
     */
    public function getParameters(): array
    {
        $parameters = [];

        /** @var list<Node\Param> $nodeParams */
        $nodeParams = $this->node->params;
        foreach ($nodeParams as $paramIndex => $paramNode) {
            $parameters[] = ReflectionParameter::createFromNode(
                $this->reflector,
                $paramNode,
                $this->declaringNamespace,
                $this,
                $paramIndex,
            );
        }

        return $parameters;
    }

    /**
     * Get a single parameter by name. Returns null if parameter not found for
     * the function.
     */
    public function getParameter(string $parameterName): ?ReflectionParameter
    {
        foreach ($this->getParameters() as $parameter) {
            if ($parameter->getName() === $parameterName) {
                return $parameter;
            }
        }

        return null;
    }

    public function getDocComment(): string
    {
        return GetLastDocComment::forNode($this->node);
    }

    public function setDocCommentFromString(string $string): void
    {
        $this->node->setDocComment(new Doc($string));
    }

    public function getFileName(): ?string
    {
        return $this->locatedSource->getFileName();
    }

    public function getLocatedSource(): LocatedSource
    {
        return $this->locatedSource;
    }

    /**
     * Is this function a closure?
     */
    public function isClosure(): bool
    {
        return $this->node instanceof Node\Expr\Closure || $this->node instanceof Node\Expr\ArrowFunction;
    }

    public function isDeprecated(): bool
    {
        return AnnotationHelper::isDeprecated($this->getDocComment());
    }

    public function isInternal(): bool
    {
        return $this->locatedSource->isInternal();
    }

    /**
     * Is this a user-defined function (will always return the opposite of
     * whatever isInternal returns).
     */
    public function isUserDefined(): bool
    {
        return ! $this->isInternal();
    }

    public function getExtensionName(): ?string
    {
        return $this->locatedSource->getExtensionName();
    }

    /**
     * Check if the function has a variadic parameter.
     */
    public function isVariadic(): bool
    {
        $parameters = $this->getParameters();

        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively search an array of statements (PhpParser nodes) to find if a
     * yield expression exists anywhere (thus indicating this is a generator).
     */
    private function nodeIsOrContainsYield(Node $node): bool
    {
        if ($node instanceof YieldNode) {
            return true;
        }

        if ($node instanceof YieldFromNode) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $nodeName) {
            $nodeProperty = $node->$nodeName;

            if ($nodeProperty instanceof Node && $this->nodeIsOrContainsYield($nodeProperty)) {
                return true;
            }

            if (! is_array($nodeProperty)) {
                continue;
            }

            foreach ($nodeProperty as $nodePropertyArrayItem) {
                if ($nodePropertyArrayItem instanceof Node && $this->nodeIsOrContainsYield($nodePropertyArrayItem)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if this function can be used as a generator (i.e. contains the
     * "yield" keyword).
     */
    public function isGenerator(): bool
    {
        return $this->nodeIsOrContainsYield($this->node);
    }

    /**
     * Get the line number that this function starts on.
     */
    public function getStartLine(): int
    {
        return $this->node->getStartLine();
    }

    /**
     * Get the line number that this function ends on.
     */
    public function getEndLine(): int
    {
        return $this->node->getEndLine();
    }

    public function getStartColumn(): int
    {
        return CalculateReflectionColumn::getStartColumn($this->locatedSource->getSource(), $this->node);
    }

    public function getEndColumn(): int
    {
        return CalculateReflectionColumn::getEndColumn($this->locatedSource->getSource(), $this->node);
    }

    /**
     * Is this function declared as a reference.
     */
    public function returnsReference(): bool
    {
        return $this->node->byRef;
    }

    /**
     * Get the return types defined in the DocBlocks. This returns an array because
     * the parameter may have multiple (compound) types specified (for example
     * when you type hint pipe-separated "string|null", in which case this
     * would return an array of Type objects, one for string, one for null.
     *
     * @return list<Type>
     */
    public function getDocBlockReturnTypes(): array
    {
        return (new FindReturnType())->__invoke($this, $this->declaringNamespace);
    }

    /**
     * Get the return type declaration
     */
    public function getReturnType(): ReflectionNamedType|ReflectionUnionType|ReflectionIntersectionType|null
    {
        if ($this->hasTentativeReturnType()) {
            return null;
        }

        return $this->createReturnType();
    }

    /**
     * Do we have a return type declaration
     */
    public function hasReturnType(): bool
    {
        if ($this->hasTentativeReturnType()) {
            return false;
        }

        return $this->node->getReturnType() !== null;
    }

    public function hasTentativeReturnType(): bool
    {
        if ($this->isUserDefined()) {
            return false;
        }

        return AnnotationHelper::hasTentativeReturnType($this->getDocComment());
    }

    public function getTentativeReturnType(): ReflectionNamedType|ReflectionUnionType|ReflectionIntersectionType|null
    {
        if (! $this->hasTentativeReturnType()) {
            return null;
        }

        return $this->createReturnType();
    }

    private function createReturnType(): ReflectionNamedType|ReflectionUnionType|ReflectionIntersectionType|null
    {
        $returnType = $this->node->getReturnType();
        assert($returnType instanceof Node\Identifier || $returnType instanceof Node\Name || $returnType instanceof Node\NullableType || $returnType instanceof Node\UnionType || $returnType instanceof Node\IntersectionType || $returnType === null);

        if ($returnType === null) {
            return null;
        }

        return ReflectionType::createFromNode($this->reflector, $this, $returnType);
    }

    /**
     * Set the return type declaration.
     */
    public function setReturnType(string $newReturnType): void
    {
        $this->node->returnType = new Node\Name($newReturnType);
    }

    /**
     * Remove the return type declaration completely.
     */
    public function removeReturnType(): void
    {
        $this->node->returnType = null;
    }

    /**
     * @throws Uncloneable
     */
    public function __clone()
    {
        throw Uncloneable::fromClass(self::class);
    }

    /**
     * Retrieves the body of this function as AST nodes
     *
     * @return Node[]
     */
    public function getBodyAst(): array
    {
        return $this->node->getStmts() ?? [];
    }

    /**
     * Retrieves the body of this function as code.
     *
     * If a PrettyPrinter is provided as a parameter, it will be used, otherwise
     * a default will be used.
     *
     * Note that the formatting of the code may not be the same as the original
     * function. If specific formatting is required, you should provide your
     * own implementation of a PrettyPrinter to unparse the AST.
     */
    public function getBodyCode(?PrettyPrinterAbstract $printer = null): string
    {
        if ($printer === null) {
            $printer = new StandardPrettyPrinter();
        }

        if ($this->node instanceof Node\Expr\ArrowFunction) {
            /** @var non-empty-list<Node\Stmt\Return_> $ast */
            $ast  = $this->getBodyAst();
            $expr = $ast[0]->expr;
            assert($expr instanceof Node\Expr);

            return $printer->prettyPrintExpr($expr);
        }

        return $printer->prettyPrint($this->getBodyAst());
    }

    /**
     * Fetch the AST for this method or function.
     */
    abstract public function getAst(): Node\Stmt\ClassMethod|Node\Stmt\Function_|Node\Expr\Closure|Node\Expr\ArrowFunction;

    /**
     * @return list<ReflectionAttribute>
     */
    public function getAttributes(): array
    {
        /**
         * @psalm-var ReflectionMethod|ReflectionFunction $this
         * @phpstan-ignore-next-line
         */
        return ReflectionAttributeHelper::createAttributes($this->reflector, $this);
    }

    /**
     * @return list<ReflectionAttribute>
     */
    public function getAttributesByName(string $name): array
    {
        return ReflectionAttributeHelper::filterAttributesByName($this->getAttributes(), $name);
    }

    /**
     * @param class-string $className
     *
     * @return list<ReflectionAttribute>
     */
    public function getAttributesByInstance(string $className): array
    {
        return ReflectionAttributeHelper::filterAttributesByInstance($this->getAttributes(), $className);
    }

    /**
     * Override the method or function's body of statements with an entirely new
     * body of statements within the reflection.
     *
     * @throws ParseToAstFailure
     * @throws InvalidIdentifierName
     *
     * @example
     * $reflectionFunction->setBodyFromClosure(function () { return true; });
     */
    public function setBodyFromClosure(Closure $newBody): void
    {
        $closureReflection = (new ClosureSourceLocator($newBody, $this->loadStaticParser()))->locateIdentifier(
            $this->reflector,
            new Identifier(ReflectionFunction::CLOSURE_NAME, new IdentifierType(IdentifierType::IDENTIFIER_FUNCTION)),
        );
        assert($closureReflection instanceof ReflectionFunction);

        $this->setBodyFromAst($closureReflection->getAst()->getStmts());
    }

    /**
     * Override the method or function's body of statements with an entirely new
     * body of statements within the reflection.
     *
     * @example
     * $reflectionFunction->setBodyFromString('return true;');
     */
    public function setBodyFromString(string $newBody): void
    {
        /** @var list<Node\Stmt> $stmts */
        $stmts = $this->loadStaticParser()->parse('<?php ' . $newBody);

        $this->setBodyFromAst($stmts);
    }

    /**
     * Override the method or function's body of statements with an entirely new
     * body of statements within the reflection.
     *
     * @param list<Node\Stmt> $nodes
     *
     * @example
     * // $ast should be an array of Nodes
     * $reflectionFunction->setBodyFromAst($ast);
     */
    public function setBodyFromAst(array $nodes): void
    {
        // This slightly confusing code simply type-checks the $nodes
        // array by unpacking them and splatting them in the closure.
        $validator = static fn (Node\Stmt ...$node): array => $node;
        $stmts     = $validator(...$nodes);

        if ($this->node instanceof Node\Expr\ArrowFunction) {
            if (! isset($nodes[0]->expr)) {
                throw InvalidArrowFunctionBodyNode::create($nodes[0]);
            }

            $this->node->expr = $nodes[0]->expr;

            return;
        }

        $this->node->stmts = $stmts;
    }

    /**
     * Add a new parameter to the method/function.
     */
    public function addParameter(string $parameterName): void
    {
        $this->node->params[] = new ParamNode(new Node\Expr\Variable($parameterName));
    }

    /**
     * Remove a parameter from the method/function.
     */
    public function removeParameter(string $parameterName): void
    {
        $lowerName = strtolower($parameterName);

        foreach ($this->node->params as $key => $paramNode) {
            $varNode = $paramNode->var;
            assert($varNode instanceof Node\Expr\Variable);

            if (! is_string($varNode->name) || strtolower($varNode->name) !== $lowerName) {
                continue;
            }

            unset($this->node->params[$key]);
        }
    }

    /**
     * Fetch an array of all return statements found within this function.
     *
     * Note that return statements within smaller scopes contained (e.g. anonymous classes, closures) are not returned
     * here as they are not within the immediate scope.
     *
     * @return Node\Stmt\Return_[]
     */
    public function getReturnStatementsAst(): array
    {
        $visitor = new ReturnNodeVisitor();

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        $stmts = $this->node->getStmts();

        if ($stmts === null) {
            return [];
        }

        $traverser->traverse($stmts);

        return $visitor->getReturnNodes();
    }

    private function loadStaticParser(): Parser
    {
        return self::$parser ?? self::$parser = (new BetterReflection())->phpParser();
    }
}
