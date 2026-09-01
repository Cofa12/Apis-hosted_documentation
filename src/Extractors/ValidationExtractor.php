<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Cofa\ApiDocs\Support\AstResolver;
use Cofa\ApiDocs\Support\ParameterTree;
use Cofa\ApiDocs\Support\RuleExpressionResolver;
use Cofa\ApiDocs\Support\ValidationRuleParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use ReflectionNamedType;
use Throwable;

/**
 * Where the request body actually comes from.
 *
 * Two sources are read, in this order: a form request type hinted on the
 * action, and any validation call inside the action itself. Both end up as
 * documented parameters, which is what makes the generator work on projects
 * that never wrote a line of API documentation.
 */
class ValidationExtractor implements Extractor
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected AstResolver $ast,
        protected ValidationRuleParser $parser = new ValidationRuleParser(),
        protected array $config = [],
    ) {
    }

    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        $rules = [];
        $overrides = [];

        foreach ($this->formRequests($context) as $formRequest) {
            $rules = array_merge($rules, $this->rulesFromFormRequest($formRequest));
            $overrides = array_merge($overrides, $this->overridesFromFormRequest($formRequest));
            $endpoint->meta['form_request'] = $formRequest;
        }

        $rules = array_merge($rules, $this->rulesFromAction($context));

        if ($rules === []) {
            return;
        }

        $endpoint->meta['has_validation'] = true;

        $parameters = $this->parser->parse($rules);

        foreach ($parameters as $parameter) {
            $this->applyOverrides($parameter, $overrides);
        }

        $bucket = $this->bucketFor($endpoint);

        $endpoint->mergeParameters($bucket, ParameterTree::nest($parameters));
    }

    protected function bucketFor(Endpoint $endpoint): string
    {
        return $endpoint->hasBody() && ! in_array($endpoint->method(), ['DELETE'], true)
            ? 'bodyParameters'
            : 'queryParameters';
    }

    /**
     * Form request classes type hinted on the action.
     *
     * @return array<int, class-string<FormRequest>>
     */
    protected function formRequests(RouteContext $context): array
    {
        $found = [];
        $reflection = $context->methodReflection;

        if ($reflection === null) {
            return $found;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (class_exists($class) && is_subclass_of($class, FormRequest::class)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @return array<string, mixed>
     */
    public function rulesFromFormRequest(string $class): array
    {
        // Instantiating directly (rather than resolving from the container)
        // keeps Laravel from running the validation lifecycle for us.
        try {
            $instance = new $class();

            if (method_exists($instance, 'rules')) {
                $rules = $instance->rules();

                if (is_array($rules) && $rules !== []) {
                    return $rules;
                }
            }
        } catch (Throwable) {
            // The rules depend on runtime state – read the source instead.
        }

        return $this->rulesFromSource($class, 'rules');
    }

    /** @return array<string, mixed> */
    protected function rulesFromSource(string $class, string $method): array
    {
        $node = $this->ast->findMethod($class, $method);

        if ($node === null) {
            return [];
        }

        $resolved = $this->ast->returnedArray($node, new RuleExpressionResolver($this->ast));

        return is_array($resolved) ? $resolved : [];
    }

    /**
     * Descriptions and examples declared on the form request itself.
     *
     * @param  class-string<FormRequest>  $class
     * @return array<string, array{description?: string, example?: mixed, name?: string}>
     */
    public function overridesFromFormRequest(string $class): array
    {
        $overrides = [];

        foreach (['bodyParameters', 'queryParameters'] as $method) {
            $documented = $this->callArrayMethod($class, $method);

            foreach ($documented as $field => $definition) {
                if (! is_string($field) || ! is_array($definition)) {
                    continue;
                }

                $overrides[$field] = array_merge($overrides[$field] ?? [], [
                    'description' => isset($definition['description']) && is_string($definition['description'])
                        ? $definition['description']
                        : ($overrides[$field]['description'] ?? null),
                    'example' => array_key_exists('example', $definition)
                        ? $definition['example']
                        : ($overrides[$field]['example'] ?? null),
                ]);
            }
        }

        foreach ($this->callArrayMethod($class, 'attributes') as $field => $label) {
            if (is_string($field) && is_string($label)) {
                $overrides[$field]['name'] = $label;
            }
        }

        return $overrides;
    }

    /** @return array<mixed> */
    protected function callArrayMethod(string $class, string $method): array
    {
        if (! method_exists($class, $method)) {
            return $this->rulesFromSource($class, $method);
        }

        try {
            $value = (new $class())->{$method}();

            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return $this->rulesFromSource($class, $method);
        }
    }

    /** @param array<string, array<string, mixed>> $overrides */
    protected function applyOverrides(Parameter $parameter, array $overrides): void
    {
        $override = $overrides[$parameter->name] ?? null;

        if ($override === null) {
            return;
        }

        if (! empty($override['description']) && is_string($override['description'])) {
            $parameter->description = trim($override['description'] . ' ' . $parameter->description);
        }

        if (array_key_exists('example', $override) && $override['example'] !== null) {
            $parameter->example = $override['example'];
        }

        if (! empty($override['name']) && is_string($override['name']) && $parameter->description === '') {
            $parameter->description = Str::ucfirst($override['name']) . '.';
        }
    }

    /**
     * Validation performed inside the action body.
     *
     * @return array<string, mixed>
     */
    public function rulesFromAction(RouteContext $context): array
    {
        if ($context->controller === null || $context->action === null) {
            return [];
        }

        $node = $this->ast->findMethod($context->controller, $context->action);

        if ($node === null) {
            return [];
        }

        $fallback = new RuleExpressionResolver($this->ast);
        $rules = [];

        foreach ($this->validationArrays($node) as $array) {
            $rules = array_merge($rules, $this->ast->resolveArray($array, $fallback));
        }

        return $rules;
    }

    /**
     * Find every array literal passed to a validation call.
     *
     * @return array<int, Expr\Array_>
     */
    protected function validationArrays(Node $node): array
    {
        $arrays = [];

        // $request->validate([...]) / $this->validate($request, [...]) / $request->validateWithBag('bag', [...])
        foreach ($this->ast->findInstanceOf([$node], Expr\MethodCall::class) as $call) {
            /** @var Expr\MethodCall $call */
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

            if (! in_array($name, ['validate', 'validateWithBag'], true)) {
                continue;
            }

            foreach ($call->args as $arg) {
                if ($arg instanceof Arg && $arg->value instanceof Expr\Array_) {
                    $arrays[] = $arg->value;

                    break;
                }
            }
        }

        // Validator::make($data, [...]) and validator($data, [...])
        foreach ($this->ast->findInstanceOf([$node], Expr\StaticCall::class) as $call) {
            /** @var Expr\StaticCall $call */
            $class = $this->ast->classNameOf($call) ?? '';
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

            if ($name !== 'make' || ! Str::contains($class, 'Validator')) {
                continue;
            }

            if (isset($call->args[1]) && $call->args[1] instanceof Arg && $call->args[1]->value instanceof Expr\Array_) {
                $arrays[] = $call->args[1]->value;
            }
        }

        foreach ($this->ast->findInstanceOf([$node], Expr\FuncCall::class) as $call) {
            /** @var Expr\FuncCall $call */
            $name = $call->name instanceof Node\Name ? $call->name->toString() : '';

            if ($name !== 'validator' && $name !== 'Validator') {
                continue;
            }

            if (isset($call->args[1]) && $call->args[1] instanceof Arg && $call->args[1]->value instanceof Expr\Array_) {
                $arrays[] = $call->args[1]->value;
            }
        }

        return $arrays;
    }
}
