<?php

namespace Cofa\ApiDocs\Support;

use Illuminate\Support\Str;

/**
 * Produces realistic looking example values. The heuristics look at the field
 * name first (an "email" field should never be documented as "string") and
 * fall back to the declared type.
 */
class ExampleFactory
{
    /** @var array<string, mixed> exact field name => example */
    protected const NAMES = [
        'id' => 1,
        'uuid' => '9d5f2f9c-8f4e-4a51-b6d3-6c6c6d3f0a11',
        'email' => 'john@example.com',
        'email_address' => 'john@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'name' => 'John Doe',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'username' => 'johndoe',
        'title' => 'A short descriptive title',
        'slug' => 'a-short-descriptive-title',
        'body' => 'The full body of the resource.',
        'content' => 'The full content of the resource.',
        'description' => 'A human friendly description.',
        'phone' => '+1 555 010 9999',
        'phone_number' => '+1 555 010 9999',
        'avatar' => 'https://example.com/avatars/1.png',
        'image' => 'https://example.com/images/1.png',
        'url' => 'https://example.com',
        'website' => 'https://example.com',
        'address' => '221B Baker Street',
        'city' => 'London',
        'country' => 'United Kingdom',
        'country_code' => 'GB',
        'zip' => '10001',
        'postal_code' => '10001',
        'latitude' => 51.523767,
        'longitude' => -0.158555,
        'price' => 19.99,
        'amount' => 19.99,
        'total' => 49.95,
        'quantity' => 2,
        'stock' => 25,
        'status' => 'active',
        'role' => 'admin',
        'type' => 'default',
        'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.example-signature',
        'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.example-signature',
        'refresh_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.example-signature',
        'expires_in' => 3600,
        'refresh_expires_in' => 3600,
        'locale' => 'en',
        'timezone' => 'UTC',
        'currency' => 'USD',
        'page' => 1,
        'per_page' => 15,
        'limit' => 15,
        'offset' => 0,
        'search' => 'john',
        'q' => 'john',
        'sort' => 'created_at',
        'order' => 'desc',
    ];

    /** Build an example for a documented input field. */
    public static function forParameter(string $name, string $type, array $rules = [], array $enum = []): mixed
    {
        if ($enum !== []) {
            return self::castScalar($enum[0], $type);
        }

        if (($fromRules = self::fromRules($name, $type, $rules)) !== null) {
            return $fromRules;
        }

        return self::forName($name, $type);
    }

    /** Build an example purely from the field name (used for response shapes). */
    public static function forName(string $name, ?string $type = null): mixed
    {
        $key = Str::snake(Str::afterLast($name, '.'));
        $key = str_replace('*', '', $key);

        if ($type !== null && in_array($type, ['object', 'object[]', 'array'], true)) {
            return self::forType($type);
        }

        if (array_key_exists($key, self::NAMES)) {
            return self::NAMES[$key];
        }

        if (Str::endsWith($key, '_id') || $key === 'id') {
            return 1;
        }

        if (Str::endsWith($key, ['_at', '_date']) || in_array($key, ['date', 'datetime', 'timestamp'], true)) {
            return '2026-01-15T09:30:00.000000Z';
        }

        if (Str::startsWith($key, ['is_', 'has_', 'can_', 'should_']) || Str::endsWith($key, '_enabled')) {
            return true;
        }

        if (Str::endsWith($key, ['_count', '_total']) || in_array($key, ['count', 'total'], true)) {
            return 3;
        }

        if (Str::endsWith($key, ['_url', '_link'])) {
            return 'https://example.com/resource';
        }

        if (Str::endsWith($key, '_email')) {
            return 'john@example.com';
        }

        if (Str::endsWith($key, ['_name', '_title'])) {
            return Str::headline(Str::beforeLast($key, '_'));
        }

        if (Str::endsWith($key, ['_price', '_amount', '_rate'])) {
            return 19.99;
        }

        if ($type !== null) {
            return self::forType($type, $key);
        }

        return Str::headline($key) !== '' ? Str::lower(Str::headline($key)) : 'string';
    }

    public static function forType(string $type, string $name = 'value'): mixed
    {
        return match ($type) {
            'integer', 'int' => 1,
            'number', 'float', 'double' => 1.5,
            'boolean', 'bool' => true,
            'array', 'string[]' => ['first', 'second'],
            'integer[]' => [1, 2],
            'object' => [],
            'object[]' => [],
            'file' => '(binary)',
            'date' => '2026-01-15',
            'datetime' => '2026-01-15T09:30:00.000000Z',
            default => Str::lower(Str::headline($name)) ?: 'string',
        };
    }

    /** Derive an example from validation rules such as in:, min:, max: or date_format:. */
    protected static function fromRules(string $name, string $type, array $rules): mixed
    {
        $flat = array_map(fn ($rule) => is_string($rule) ? $rule : '', $rules);

        foreach ($flat as $rule) {
            $lower = Str::lower($rule);

            if (Str::startsWith($lower, 'in:')) {
                $values = array_map('trim', explode(',', Str::after($rule, ':')));

                return self::castScalar(trim($values[0], "'\""), $type);
            }

            if (Str::startsWith($lower, 'date_format:')) {
                $format = Str::after($rule, ':');

                return date($format, mktime(9, 30, 0, 1, 15, 2026));
            }

            if ($lower === 'uuid') {
                return self::NAMES['uuid'];
            }

            if ($lower === 'email') {
                return self::NAMES['email'];
            }

            if ($lower === 'url' || $lower === 'active_url') {
                return 'https://example.com';
            }

            if ($lower === 'ip' || $lower === 'ipv4') {
                return '192.168.1.1';
            }

            if ($lower === 'date') {
                return '2026-01-15';
            }

            if (Str::startsWith($lower, 'digits:')) {
                return (int) str_repeat('1', max(1, (int) Str::after($rule, ':')));
            }
        }

        // A numeric min/max pair should produce a value inside the allowed range.
        if (in_array($type, ['integer', 'number'], true)) {
            foreach ($flat as $rule) {
                if (Str::startsWith(Str::lower($rule), 'min:')) {
                    $min = (float) Str::after($rule, ':');

                    return $type === 'integer' ? (int) $min : $min;
                }
            }
        }

        if ($type === 'string') {
            foreach ($flat as $rule) {
                if (Str::startsWith(Str::lower($rule), 'min:')) {
                    $min = (int) Str::after($rule, ':');
                    $example = self::forName($name, $type);

                    if (is_string($example) && mb_strlen($example) < $min) {
                        return str_pad($example, $min, 'x');
                    }
                }
            }
        }

        return null;
    }

    protected static function castScalar(mixed $value, string $type): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($type) {
            'integer' => (int) $value,
            'number' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            default => $value,
        };
    }
}
