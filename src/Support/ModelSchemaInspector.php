<?php

namespace Cofa\ApiDocs\Support;

use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * When an endpoint returns a model (or a resource that just forwards one),
 * the model's own metadata – casts, fillable, hidden, appends – is the best
 * description of the payload we can get without touching the database.
 */
class ModelSchemaInspector
{
    /** @var array<string, array<string, mixed>|null> */
    protected array $cache = [];

    public function isModel(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            return (new ReflectionClass($class))->isSubclassOf(\Illuminate\Database\Eloquent\Model::class);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public function shapeFor(string $class): ?array
    {
        if (array_key_exists($class, $this->cache)) {
            return $this->cache[$class];
        }

        if (! $this->isModel($class)) {
            return $this->cache[$class] = null;
        }

        try {
            $reflection = new ReflectionClass($class);
            $defaults = $reflection->getDefaultProperties();
        } catch (Throwable) {
            return $this->cache[$class] = null;
        }

        $casts = is_array($defaults['casts'] ?? null) ? $defaults['casts'] : [];
        $fillable = is_array($defaults['fillable'] ?? null) ? $defaults['fillable'] : [];
        $hidden = is_array($defaults['hidden'] ?? null) ? $defaults['hidden'] : [];
        $appends = is_array($defaults['appends'] ?? null) ? $defaults['appends'] : [];
        $timestamps = $defaults['timestamps'] ?? true;
        $keyName = is_string($defaults['primaryKey'] ?? null) ? $defaults['primaryKey'] : 'id';
        $keyType = is_string($defaults['keyType'] ?? null) ? $defaults['keyType'] : 'int';

        $shape = [
            $keyName => $keyType === 'string' ? ExampleFactory::forName('uuid') : 1,
        ];

        foreach (array_merge($fillable, array_keys($casts), $appends) as $attribute) {
            if (! is_string($attribute) || in_array($attribute, $hidden, true) || isset($shape[$attribute])) {
                continue;
            }

            $shape[$attribute] = isset($casts[$attribute]) && is_string($casts[$attribute])
                ? $this->exampleForCast($attribute, $casts[$attribute])
                : ExampleFactory::forName($attribute);
        }

        if ($timestamps !== false) {
            $shape['created_at'] ??= '2026-01-15T09:30:00.000000Z';
            $shape['updated_at'] ??= '2026-01-15T09:30:00.000000Z';
        }

        return $this->cache[$class] = $shape;
    }

    /**
     * Resources are conventionally named after their model, so
     * `App\Http\Resources\UserResource` is matched to `App\Models\User`.
     *
     * @return array<string, mixed>|null
     */
    public function shapeForResource(string $resourceClass): ?array
    {
        $base = Str::replaceLast('Resource', '', class_basename($resourceClass));
        $base = Str::singular(Str::replaceLast('Collection', '', $base));

        if ($base === '') {
            return null;
        }

        foreach ($this->candidateModels($base, $resourceClass) as $candidate) {
            if ($this->isModel($candidate)) {
                return $this->shapeFor($candidate);
            }
        }

        return null;
    }

    /**
     * The namespaces a model called `$base` is likely to live in.
     *
     * @return array<int, string>
     */
    protected function candidateModels(string $base, string $resourceClass = ''): array
    {
        $namespaces = ['App\\Models\\', 'App\\', 'Domain\\Models\\'];

        // Modular projects keep the model next to the resource that wraps it:
        // Billing\Http\Resources\InvoiceResource -> Billing\Models\Invoice.
        $namespace = Str::beforeLast($resourceClass, '\\');

        foreach (['\\Http\\Resources', '\\Resources'] as $marker) {
            if ($namespace !== '' && str_ends_with($namespace, $marker)) {
                $root = Str::beforeLast($namespace, $marker);
                array_unshift($namespaces, $root . '\\Models\\', $root . '\\');

                break;
            }
        }

        return array_values(array_unique(array_map(
            fn (string $namespace) => $namespace . $base,
            $namespaces
        )));
    }

    public function exampleForCast(string $attribute, string $cast): mixed
    {
        $cast = Str::before(strtolower($cast), ':');

        return match ($cast) {
            'int', 'integer' => Str::endsWith($attribute, '_id') ? 1 : ExampleFactory::forName($attribute, 'integer'),
            'real', 'float', 'double', 'decimal' => 19.99,
            'bool', 'boolean' => true,
            'array', 'json', 'collection' => [],
            'object' => [],
            'date' => '2026-01-15',
            'datetime', 'immutable_datetime', 'timestamp' => '2026-01-15T09:30:00.000000Z',
            'hashed' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            default => ExampleFactory::forName($attribute),
        };
    }
}
