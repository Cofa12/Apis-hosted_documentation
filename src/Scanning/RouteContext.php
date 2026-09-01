<?php

namespace Cofa\ApiDocs\Scanning;

use Cofa\ApiDocs\Support\DocBlock;
use Cofa\ApiDocs\Support\DocBlockParser;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Everything the extractors need about one route: the route itself plus the
 * reflection of whatever handles it.
 */
class RouteContext
{
    protected ?DocBlock $methodDocBlock = null;

    protected ?DocBlock $classDocBlock = null;

    public function __construct(
        public Route $route,
        public ?string $controller = null,
        public ?string $action = null,
        public ?ReflectionClass $classReflection = null,
        public ?ReflectionMethod $methodReflection = null,
        protected ?DocBlockParser $parser = null,
    ) {
        $this->parser ??= new DocBlockParser();
    }

    public static function forRoute(Route $route, ?DocBlockParser $parser = null): self
    {
        $controller = null;
        $action = null;
        $classReflection = null;
        $methodReflection = null;

        $uses = $route->getAction('uses');

        if (is_string($uses) && str_contains($uses, '@')) {
            [$controller, $action] = explode('@', $uses, 2);
        } elseif (is_string($uses) && class_exists($uses)) {
            // Single action controller registered without @__invoke.
            $controller = $uses;
            $action = '__invoke';
        } elseif (is_array($uses) && isset($uses[0], $uses[1]) && is_string($uses[0])) {
            [$controller, $action] = $uses;
        }

        if ($controller !== null && class_exists($controller)) {
            try {
                $classReflection = new ReflectionClass($controller);

                if ($action !== null && $classReflection->hasMethod($action)) {
                    $methodReflection = $classReflection->getMethod($action);
                }
            } catch (Throwable) {
                $classReflection = null;
            }
        }

        return new self($route, $controller, $action, $classReflection, $methodReflection, $parser);
    }

    public function isClosure(): bool
    {
        return $this->controller === null;
    }

    public function methodDocBlock(): DocBlock
    {
        if ($this->methodDocBlock !== null) {
            return $this->methodDocBlock;
        }

        return $this->methodDocBlock = $this->parser->parse(
            $this->methodReflection?->getDocComment() ?? null
        );
    }

    public function classDocBlock(): DocBlock
    {
        if ($this->classDocBlock !== null) {
            return $this->classDocBlock;
        }

        return $this->classDocBlock = $this->parser->parse(
            $this->classReflection?->getDocComment() ?? null
        );
    }

    /**
     * Attribute instances declared on the method, falling back to the class.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return array<int, T>
     */
    public function attributes(string $attribute, bool $includeClass = true): array
    {
        return array_map(
            fn (array $declaration) => $declaration['instance'],
            $this->attributeDeclarations($attribute, $includeClass)
        );
    }

    /**
     * The same attributes, paired with the names of the arguments the author
     * actually passed.
     *
     * An attribute's constructor defaults are indistinguishable from written
     * values on the instance, so they are read from the declaration itself:
     * that is what lets an attribute override only what it really says.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return array<int, array{instance: T, declared: array<int, string>}>
     */
    public function attributeDeclarations(string $attribute, bool $includeClass = true): array
    {
        $declarations = [];

        foreach ([$this->methodReflection, $includeClass ? $this->classReflection : null] as $reflection) {
            if ($reflection === null) {
                continue;
            }

            try {
                foreach ($reflection->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF) as $found) {
                    $declarations[] = [
                        'instance' => $found->newInstance(),
                        'declared' => $this->namedArguments($found),
                    ];
                }
            } catch (Throwable) {
                // A broken attribute must never break the whole scan.
            }
        }

        return $declarations;
    }

    /**
     * Argument names as written, resolving positional arguments against the
     * attribute's constructor signature.
     *
     * @return array<int, string>
     */
    protected function namedArguments(\ReflectionAttribute $attribute): array
    {
        $arguments = $attribute->getArguments();
        $names = [];
        $positional = null;

        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $names[] = $key;

                continue;
            }

            if ($positional === null) {
                $positional = [];

                try {
                    $constructor = (new ReflectionClass($attribute->getName()))->getConstructor();

                    foreach ($constructor?->getParameters() ?? [] as $parameter) {
                        $positional[] = $parameter->getName();
                    }
                } catch (Throwable) {
                    $positional = [];
                }
            }

            if (isset($positional[$key])) {
                $names[] = $positional[$key];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    public function attribute(string $attribute, bool $includeClass = true): ?object
    {
        return $this->attributes($attribute, $includeClass)[0] ?? null;
    }

    /** @return array<int, string> */
    public function middleware(): array
    {
        try {
            $middleware = $this->route->gatherMiddleware();
        } catch (Throwable) {
            $middleware = (array) ($this->route->getAction('middleware') ?? []);
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => is_string($item) ? $item : null,
            $middleware
        ))));
    }

    /** @return array<int, string> */
    public function methods(): array
    {
        return array_values(array_diff($this->route->methods(), ['HEAD']));
    }

    public function uri(): string
    {
        return trim($this->route->uri(), '/');
    }

    public function parser(): DocBlockParser
    {
        return $this->parser;
    }
}
