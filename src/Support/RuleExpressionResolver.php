<?php

namespace Cofa\ApiDocs\Support;

use Illuminate\Support\Str;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use Throwable;

/**
 * Rules are not always plain strings. This turns the fluent rule builders –
 * `Rule::in([...])`, `Rule::enum(Status::class)`, `new Enum(Status::class)`,
 * `Password::min(8)` – back into the string form the rule parser understands.
 */
class RuleExpressionResolver
{
    public function __construct(protected AstResolver $ast)
    {
    }

    public function __invoke(Expr $expr): mixed
    {
        return $this->resolve($expr) ?? $this->ast->print($expr);
    }

    public function resolve(Expr $expr): ?string
    {
        if ($expr instanceof Expr\StaticCall || $expr instanceof Expr\MethodCall) {
            return $this->fromCallChain($expr);
        }

        if ($expr instanceof Expr\New_) {
            $class = $this->ast->classNameOf($expr);

            if ($class !== null && Str::endsWith($class, ['Rules\\Enum', '\\Enum'])) {
                return $this->enumRule($expr->args);
            }

            if ($class !== null && Str::endsWith($class, 'Password')) {
                return 'string';
            }

            return null;
        }

        if ($expr instanceof Expr\ClassConstFetch) {
            $class = $this->ast->classNameOf($expr);
            $constant = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : null;

            if ($class !== null && $constant === 'class') {
                return null;
            }
        }

        return null;
    }

    /** Walk a fluent chain back to its root static call, keeping every step. */
    protected function fromCallChain(Expr $expr): ?string
    {
        $calls = [];
        $cursor = $expr;

        while ($cursor instanceof Expr\MethodCall) {
            $calls[] = $cursor;
            $cursor = $cursor->var;
        }

        if (! $cursor instanceof Expr\StaticCall) {
            return null;
        }

        $calls[] = $cursor;
        $calls = array_reverse($calls);

        /** @var Expr\StaticCall $root */
        $root = $calls[0];
        $class = $this->ast->classNameOf($root) ?? '';
        $method = $root->name instanceof \PhpParser\Node\Identifier ? $root->name->toString() : '';

        if (Str::endsWith($class, 'Validation\\Rule') || class_basename($class) === 'Rule') {
            return $this->fromRuleFacade($method, $root->args, array_slice($calls, 1));
        }

        if (class_basename($class) === 'Password') {
            return $this->fromPasswordRule($calls);
        }

        return null;
    }

    /**
     * @param  array<int, Arg|\PhpParser\Node\VariadicPlaceholder>  $args
     * @param  array<int, Expr\MethodCall>  $chain
     */
    protected function fromRuleFacade(string $method, array $args, array $chain): ?string
    {
        $values = $this->scalarArgs($args);

        return match ($method) {
            'in' => 'in:' . implode(',', $this->flattenArg($args[0] ?? null)),
            'notIn' => 'not_in:' . implode(',', $this->flattenArg($args[0] ?? null)),
            'exists' => 'exists:' . implode(',', $values),
            'unique' => 'unique:' . implode(',', $values),
            'enum' => $this->enumRule($args),
            'requiredIf' => 'required',
            'prohibitedIf' => 'prohibited',
            'dimensions' => 'image',
            'date' => 'date',
            'file' => 'file',
            'imageFile' => 'image',
            'array' => 'array',
            default => null,
        };
    }

    /** @param array<int, Expr\MethodCall|Expr\StaticCall> $calls */
    protected function fromPasswordRule(array $calls): string
    {
        foreach ($calls as $call) {
            $name = $call->name instanceof \PhpParser\Node\Identifier ? $call->name->toString() : '';

            if ($name === 'min' && isset($call->args[0]) && $call->args[0] instanceof Arg) {
                $value = $this->scalar($call->args[0]->value);

                if ($value !== null) {
                    return 'min:' . $value;
                }
            }
        }

        return 'string';
    }

    /** @param array<int, Arg|\PhpParser\Node\VariadicPlaceholder> $args */
    protected function enumRule(array $args): string
    {
        $enum = isset($args[0]) && $args[0] instanceof Arg
            ? $this->ast->classNameOf($args[0]->value)
            : null;

        if ($enum !== null && enum_exists($enum)) {
            try {
                $values = array_map(
                    fn ($case) => (string) ($case->value ?? $case->name),
                    $enum::cases()
                );

                if ($values !== []) {
                    return 'in:' . implode(',', $values);
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return 'string';
    }

    /**
     * @param  array<int, Arg|\PhpParser\Node\VariadicPlaceholder>  $args
     * @return array<int, string>
     */
    protected function scalarArgs(array $args): array
    {
        $values = [];

        foreach ($args as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            $value = $this->scalar($arg->value);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @return array<int, string> */
    protected function flattenArg(Arg|\PhpParser\Node\VariadicPlaceholder|null $arg): array
    {
        if (! $arg instanceof Arg) {
            return [];
        }

        if ($arg->value instanceof Expr\Array_) {
            $resolved = $this->ast->resolveArray($arg->value);

            return array_values(array_map(
                fn ($value) => is_scalar($value) ? (string) $value : '',
                $resolved
            ));
        }

        $scalar = $this->scalar($arg->value);

        return $scalar === null ? [] : [$scalar];
    }

    protected function scalar(Expr $expr): ?string
    {
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }

        if (property_exists($expr, 'value') && is_scalar($expr->value)) {
            return (string) $expr->value;
        }

        if ($expr instanceof Expr\ClassConstFetch) {
            $class = $this->ast->classNameOf($expr);
            $name = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '';

            if ($class !== null && $name === 'class') {
                // `User::class` inside exists()/unique() refers to a table.
                return Str::snake(Str::pluralStudly(class_basename($class)));
            }
        }

        return null;
    }
}
