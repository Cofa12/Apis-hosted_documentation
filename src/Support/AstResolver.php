<?php

namespace Cofa\ApiDocs\Support;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use Throwable;

/**
 * Reads the source of the application under documentation.
 *
 * Reflection alone cannot tell us what `$request->validate([...])` contains or
 * which keys a resource's `toArray()` returns, so the generator parses the
 * source instead. This makes the scanner work with any project layout: the
 * files are found through the classes the router already resolved.
 */
class AstResolver
{
    protected Parser $parser;

    protected Standard $printer;

    protected NodeFinder $finder;

    /** @var array<string, array<int, Node>|null> */
    protected array $files = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
        $this->finder = new NodeFinder();
    }

    /** @return array<int, Node>|null */
    public function parseFile(string $path): ?array
    {
        if (array_key_exists($path, $this->files)) {
            return $this->files[$path];
        }

        if (! is_file($path) || ! is_readable($path)) {
            return $this->files[$path] = null;
        }

        try {
            $statements = $this->parser->parse((string) file_get_contents($path));

            if ($statements === null) {
                return $this->files[$path] = null;
            }

            // Resolving names means `UserResource::collection()` arrives here as
            // a fully qualified class we can reflect on.
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor(new ParentConnectingVisitor());

            return $this->files[$path] = $traverser->traverse($statements);
        } catch (Throwable) {
            return $this->files[$path] = null;
        }
    }

    /** @return array<int, Node>|null */
    public function parseClass(string $class): ?array
    {
        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
            return null;
        }

        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        return $file === false ? null : $this->parseFile($file);
    }

    public function findMethod(string $class, string $method): ?ClassMethod
    {
        $statements = $this->parseClass($class);

        if ($statements === null) {
            return null;
        }

        $short = class_basename($class);

        /** @var ClassMethod|null $node */
        $node = $this->finder->findFirst($statements, function (Node $node) use ($method, $short) {
            if (! $node instanceof ClassMethod) {
                return false;
            }

            if (strcasecmp($node->name->toString(), $method) !== 0) {
                return false;
            }

            // Guard against several classes living in one file.
            $parent = $node->getAttribute('parent');

            return $parent === null
                || ! isset($parent->name)
                || strcasecmp((string) $parent->name, $short) === 0;
        });

        return $node;
    }

    /**
     * The array returned by a method, resolved into plain PHP values.
     *
     * @return array<mixed>|null
     */
    public function returnedArray(ClassMethod $method, ?callable $fallback = null): ?array
    {
        /** @var array<int, Node\Stmt\Return_> $returns */
        $returns = $this->finder->findInstanceOf([$method], Node\Stmt\Return_::class);

        foreach ($returns as $return) {
            if ($return->expr instanceof Array_) {
                return $this->resolveArray($return->expr, $fallback);
            }
        }

        return null;
    }

    /**
     * Turn an array literal into PHP values. Expressions that cannot be
     * evaluated (a method call, a constant on another class) are handed to the
     * fallback, which by default returns the printed source.
     *
     * @return array<mixed>
     */
    public function resolveArray(Array_ $node, ?callable $fallback = null): array
    {
        $fallback ??= fn (Expr $expr) => $this->printer->prettyPrintExpr($expr);
        $result = [];

        foreach ($node->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->unpack) {
                continue;
            }

            $key = $item->key === null ? null : $this->resolveValue($item->key, $fallback);
            $value = $item->value instanceof Array_
                ? $this->resolveArray($item->value, $fallback)
                : $this->resolveValue($item->value, $fallback);

            if ($key === null) {
                $result[] = $value;
            } elseif (is_string($key) || is_int($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function resolveValue(Node $node, ?callable $fallback = null): mixed
    {
        $fallback ??= fn (Expr $expr) => $this->printer->prettyPrintExpr($expr);

        if ($node instanceof Array_) {
            return $this->resolveArray($node, $fallback);
        }

        if (! $node instanceof Expr) {
            return $this->printer->prettyPrint([$node]);
        }

        $evaluator = new ConstExprEvaluator(function (Expr $expr) use ($fallback) {
            return $fallback($expr);
        });

        try {
            return $evaluator->evaluateSilently($node);
        } catch (ConstExprEvaluationException|Throwable) {
            return $fallback($node);
        }
    }

    /**
     * @param  array<int, Node>|Node  $nodes
     * @return array<int, Node>
     */
    public function find(array|Node $nodes, callable $filter): array
    {
        return $this->finder->find(is_array($nodes) ? $nodes : [$nodes], $filter);
    }

    /**
     * @param  array<int, Node>|Node  $nodes
     * @param  class-string  $class
     * @return array<int, Node>
     */
    public function findInstanceOf(array|Node $nodes, string $class): array
    {
        return $this->finder->findInstanceOf(is_array($nodes) ? $nodes : [$nodes], $class);
    }

    public function print(Node $node): string
    {
        return $node instanceof Expr
            ? $this->printer->prettyPrintExpr($node)
            : $this->printer->prettyPrint([$node]);
    }

    /** The class name a `new X`, `X::y()` or type hint node points at. */
    public function classNameOf(?Node $node): ?string
    {
        if ($node === null) {
            return null;
        }

        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            return $node->class->toString();
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name) {
            return $node->class->toString();
        }

        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            return $node->class->toString();
        }

        if ($node instanceof Node\Name) {
            return $node->toString();
        }

        return null;
    }
}
