<?php

namespace Cofa\ApiDocs\Support;

use Cofa\ApiDocs\Data\Parameter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a Laravel validation rule set into documented parameters: type,
 * requiredness, constraints written as English, enum values and an example.
 */
class ValidationRuleParser
{
    protected const TYPE_RULES = [
        'integer' => 'integer',
        'int' => 'integer',
        'numeric' => 'number',
        'decimal' => 'number',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'accepted' => 'boolean',
        'declined' => 'boolean',
        'array' => 'array',
        'file' => 'file',
        'image' => 'file',
        'date' => 'date',
        'date_format' => 'date',
        'string' => 'string',
        'email' => 'string',
        'url' => 'string',
        'uuid' => 'string',
        'ulid' => 'string',
        'ip' => 'string',
        'json' => 'string',
        'enum' => 'string',
    ];

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, Parameter> flat parameters keyed by their dotted name
     */
    public function parse(array $rules): array
    {
        $parameters = [];

        foreach ($rules as $field => $definition) {
            if (! is_string($field)) {
                continue;
            }

            $list = $this->normalise($definition);
            $parameter = $this->toParameter($field, $list);
            $parameters[$parameter->name] = $parameter;

            if ($this->hasRule($list, 'confirmed')) {
                $confirmation = new Parameter(
                    name: $field . '_confirmation',
                    type: $parameter->type,
                    required: $parameter->required,
                    description: 'Must match `' . $field . '`.',
                    example: $parameter->example,
                );
                $parameters[$confirmation->name] = $confirmation;
            }
        }

        return array_values($parameters);
    }

    /**
     * Normalise a rule definition into a flat list of string rules.
     *
     * @return array<int, string>
     */
    public function normalise(mixed $definition): array
    {
        if (is_string($definition)) {
            $definition = explode('|', $definition);
        }

        if (! is_array($definition)) {
            $definition = [$definition];
        }

        $rules = [];

        foreach ($definition as $rule) {
            if (is_string($rule)) {
                $rules[] = trim($rule);

                continue;
            }

            if (is_object($rule)) {
                $rules = array_merge($rules, $this->stringifyObjectRule($rule));

                continue;
            }

            if (is_array($rule)) {
                $rules = array_merge($rules, $this->normalise($rule));
            }
        }

        return array_values(array_filter($rules, fn ($rule) => $rule !== ''));
    }

    /** @return array<int, string> */
    protected function stringifyObjectRule(object $rule): array
    {
        // Illuminate\Validation\Rules\Enum keeps the backing enum on a property.
        if (str_contains($rule::class, 'Validation\\Rules\\Enum')) {
            try {
                $reflection = new \ReflectionObject($rule);
                if ($reflection->hasProperty('type')) {
                    $property = $reflection->getProperty('type');
                    $property->setAccessible(true);
                    $enum = $property->getValue($rule);

                    if (is_string($enum) && enum_exists($enum)) {
                        $values = array_map(
                            fn ($case) => (string) ($case->value ?? $case->name),
                            $enum::cases()
                        );

                        return ['in:' . implode(',', $values)];
                    }
                }
            } catch (Throwable) {
                // fall through to the generic handling below
            }

            return ['enum'];
        }

        if (method_exists($rule, '__toString')) {
            try {
                return [(string) $rule];
            } catch (Throwable) {
                // ignore – some rules need a validator instance to stringify
            }
        }

        return [class_basename($rule)];
    }

    /** @param array<int, string> $rules */
    public function toParameter(string $field, array $rules): Parameter
    {
        $type = $this->resolveType($field, $rules);
        $enum = $this->resolveEnum($rules);

        $parameter = new Parameter(
            name: $field,
            type: $type,
            required: $this->isRequired($rules),
            description: $this->describe($field, $rules, $type),
            rules: $rules,
            enum: $enum,
            nullable: $this->hasRule($rules, 'nullable'),
        );

        $parameter->default = $this->ruleValue($rules, 'default');
        $parameter->example = ExampleFactory::forParameter($field, $type, $rules, $enum);

        return $parameter;
    }

    /** @param array<int, string> $rules */
    public function resolveType(string $field, array $rules): string
    {
        foreach ($rules as $rule) {
            $name = Str::lower(Str::before($rule, ':'));

            if (isset(self::TYPE_RULES[$name])) {
                $type = self::TYPE_RULES[$name];

                // "integer|array" style combinations: array wins for the shape.
                if ($type === 'array') {
                    return 'array';
                }

                if ($name === 'image' || $name === 'file') {
                    return 'file';
                }

                return $type;
            }
        }

        // Wildcards and *_id fields have very predictable types.
        if (Str::endsWith($field, '_id') || $field === 'id') {
            return 'integer';
        }

        if (Str::startsWith(Str::afterLast($field, '.'), ['is_', 'has_'])) {
            return 'boolean';
        }

        return 'string';
    }

    /** @param array<int, string> $rules */
    public function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            $name = Str::lower(Str::before($rule, ':'));

            if ($name === 'required' || $name === 'present') {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $rules */
    public function resolveEnum(array $rules): array
    {
        foreach ($rules as $rule) {
            if (Str::lower(Str::before($rule, ':')) === 'in') {
                return array_values(array_filter(array_map(
                    fn (string $value) => trim(trim($value), "'\""),
                    explode(',', Str::after($rule, ':'))
                ), fn ($value) => $value !== ''));
            }
        }

        return [];
    }

    /**
     * Render the rule set as a readable sentence list.
     *
     * @param  array<int, string>  $rules
     */
    public function describe(string $field, array $rules, string $type): string
    {
        $sentences = [];

        foreach ($rules as $rule) {
            $name = Str::lower(Str::before($rule, ':'));
            $value = Str::contains($rule, ':') ? Str::after($rule, ':') : null;
            $parts = $value === null ? [] : array_map('trim', explode(',', $value));

            $sentence = match ($name) {
                'email' => 'Must be a valid email address.',
                'url', 'active_url' => 'Must be a valid URL.',
                'uuid' => 'Must be a valid UUID.',
                'ulid' => 'Must be a valid ULID.',
                'ip' => 'Must be a valid IP address.',
                'ipv4' => 'Must be a valid IPv4 address.',
                'ipv6' => 'Must be a valid IPv6 address.',
                'json' => 'Must be a valid JSON string.',
                'alpha' => 'May only contain letters.',
                'alpha_num' => 'May only contain letters and numbers.',
                'alpha_dash' => 'May only contain letters, numbers, dashes and underscores.',
                'confirmed' => 'Must be confirmed with `' . $field . '_confirmation`.',
                'accepted' => 'Must be accepted.',
                'image' => 'Must be an image.',
                'nullable' => 'May be `null`.',
                'date' => 'Must be a valid date.',
                'in' => $parts === [] ? null : 'Must be one of: ' . $this->humanList($parts) . '.',
                'not_in' => $parts === [] ? null : 'Must not be one of: ' . $this->humanList($parts) . '.',
                'min' => $this->boundary('at least', $type, $value),
                'max' => $this->boundary('at most', $type, $value),
                'size' => $this->sizeSentence($type, $value),
                'between' => count($parts) === 2 ? 'Must be between ' . $parts[0] . ' and ' . $parts[1] . '.' : null,
                'digits' => 'Must be exactly ' . $value . ' digits.',
                'digits_between' => count($parts) === 2 ? 'Must be between ' . $parts[0] . ' and ' . $parts[1] . ' digits.' : null,
                'exists' => $parts === [] ? null : 'Must exist in `' . $parts[0] . '`.',
                'unique' => $parts === [] ? null : 'Must be unique in `' . $parts[0] . '`.',
                'same' => 'Must match `' . $value . '`.',
                'different' => 'Must be different from `' . $value . '`.',
                'gt' => 'Must be greater than `' . $value . '`.',
                'gte' => 'Must be greater than or equal to `' . $value . '`.',
                'lt' => 'Must be less than `' . $value . '`.',
                'lte' => 'Must be less than or equal to `' . $value . '`.',
                'after' => 'Must be a date after ' . $value . '.',
                'after_or_equal' => 'Must be a date after or equal to ' . $value . '.',
                'before' => 'Must be a date before ' . $value . '.',
                'before_or_equal' => 'Must be a date before or equal to ' . $value . '.',
                'date_format' => 'Must match the format `' . $value . '`.',
                'mimes', 'mimetypes' => $parts === [] ? null : 'Allowed types: ' . $this->humanList($parts) . '.',
                'regex' => 'Must match the pattern `' . $value . '`.',
                'starts_with' => 'Must start with: ' . $this->humanList($parts) . '.',
                'ends_with' => 'Must end with: ' . $this->humanList($parts) . '.',
                'required_if' => count($parts) >= 2
                    ? 'Required when `' . $parts[0] . '` is ' . $this->humanList(array_slice($parts, 1)) . '.'
                    : null,
                'required_unless' => count($parts) >= 2
                    ? 'Required unless `' . $parts[0] . '` is ' . $this->humanList(array_slice($parts, 1)) . '.'
                    : null,
                'required_with' => 'Required when `' . $this->humanList($parts) . '` is present.',
                'required_without' => 'Required when `' . $this->humanList($parts) . '` is absent.',
                'prohibited' => 'Must not be present.',
                'sometimes' => null,
                default => null,
            };

            if ($sentence !== null && ! in_array($sentence, $sentences, true)) {
                $sentences[] = $sentence;
            }
        }

        return implode(' ', $sentences);
    }

    protected function boundary(string $word, string $type, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'string' => 'Must be ' . $word . ' ' . $value . ' characters.',
            'array' => 'Must have ' . $word . ' ' . $value . ' items.',
            'file' => 'Must be ' . $word . ' ' . $value . ' kilobytes.',
            default => ($word === 'at least' ? 'Minimum: ' : 'Maximum: ') . $value . '.',
        };
    }

    protected function sizeSentence(string $type, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'string' => 'Must be exactly ' . $value . ' characters.',
            'array' => 'Must contain exactly ' . $value . ' items.',
            default => 'Must be exactly ' . $value . '.',
        };
    }

    /** @param array<int, string> $values */
    protected function humanList(array $values): string
    {
        $values = array_map(fn ($value) => '`' . trim($value, "'\" ") . '`', $values);

        if (count($values) <= 1) {
            return $values[0] ?? '';
        }

        $last = array_pop($values);

        return implode(', ', $values) . ' or ' . $last;
    }

    /** @param array<int, string> $rules */
    public function hasRule(array $rules, string $needle): bool
    {
        foreach ($rules as $rule) {
            if (Str::lower(Str::before($rule, ':')) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $rules */
    protected function ruleValue(array $rules, string $needle): ?string
    {
        foreach ($rules as $rule) {
            if (Str::lower(Str::before($rule, ':')) === $needle) {
                return Str::after($rule, ':');
            }
        }

        return null;
    }
}
