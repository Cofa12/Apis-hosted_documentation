<?php

namespace Cofa\ApiDocs\Writers;

use Cofa\ApiDocs\OpenApi\Spec;
use Cofa\ApiDocs\Support\ProjectPath;
use Cofa\ApiDocs\Tenancy\Tenancy;
use Illuminate\Filesystem\Filesystem;

/**
 * Writes the documentation into the host project: the Blade templates that
 * render it, the OpenAPI document they read, and optionally a standalone HTML
 * build for static hosting.
 */
class BladeWriter
{
    protected Tenancy $tenancy;

    /** @param array<string, mixed> $config */
    public function __construct(
        protected Filesystem $files,
        protected array $config = [],
        protected string $basePath = '',
        protected string $packageViewsPath = '',
        ?Tenancy $tenancy = null,
    ) {
        $this->tenancy = $tenancy ?? new Tenancy($config);

        if ($this->packageViewsPath === '') {
            $this->packageViewsPath = realpath(__DIR__ . '/../../resources/views') ?: '';
        }
    }

    /**
     * Copy the Blade templates into the project so they can be customised.
     *
     * @return array<int, string> the files that were written
     */
    public function writeViews(bool $overwrite = false): array
    {
        $destination = $this->path((string) data_get($this->config, 'output.views_path', 'resources/views/vendor/api-docs'));

        if ($this->packageViewsPath === '' || ! $this->files->isDirectory($this->packageViewsPath)) {
            return [];
        }

        $this->files->ensureDirectoryExists($destination);

        $written = [];

        foreach ($this->files->allFiles($this->packageViewsPath) as $file) {
            $relative = ltrim(str_replace($this->packageViewsPath, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;

            if ($this->files->exists($target) && ! $overwrite) {
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->put($target, $this->files->get($file->getPathname()));
            $written[] = $target;
        }

        return $written;
    }

    /** Write the OpenAPI document the views read. */
    public function writeSpec(Spec $spec, ?string $path = null): string
    {
        $target = $this->path($path ?? (string) data_get($this->config, 'output.spec_file', 'resources/views/vendor/api-docs/openapi.json'));

        $this->files->ensureDirectoryExists(dirname($target));

        $contents = str_ends_with(strtolower($target), '.yaml') || str_ends_with(strtolower($target), '.yml')
            ? $spec->toYaml()
            : $spec->toJson();

        $this->files->put($target, $contents . "\n");

        return $target;
    }

    /** Write a fully rendered, dependency free HTML page. */
    public function writeStatic(string $html, ?string $path = null): string
    {
        $target = $this->path($path ?? (string) data_get($this->config, 'output.static_html', 'public/docs/index.html'));

        $this->files->ensureDirectoryExists(dirname($target));
        $this->files->put($target, $html);

        return $target;
    }

    /** Resolve a project relative path against the application root. */
    public function path(string $path): string
    {
        return ProjectPath::resolve($this->tenancy->apply($path), $this->basePath);
    }

    /** Relative to the project root, for friendlier console output. */
    public function relative(string $path): string
    {
        return ProjectPath::relative($path, $this->basePath);
    }
}
