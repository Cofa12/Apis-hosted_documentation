<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Documentation identity
    |--------------------------------------------------------------------------
    */

    // Config files load alphabetically, so app.php is not available here yet:
    // read the environment directly instead of config('app.name').
    'title' => env('API_DOCS_TITLE', env('APP_NAME', 'Laravel') . ' API'),

    'version' => env('API_DOCS_VERSION', '1.0.0'),

    'description' => 'Auto generated reference for every endpoint exposed by this application.',

    /*
    |--------------------------------------------------------------------------
    | Base URL used in the generated code samples / try-it console
    |--------------------------------------------------------------------------
    */

    'base_url' => env('API_DOCS_BASE_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Which routes belong to the documentation
    |--------------------------------------------------------------------------
    |
    | The scanner walks Laravel's whole route collection, so it does not matter
    | how the project splits its route files, controllers or modules. These
    | filters simply decide which of those routes end up in the docs.
    |
    */

    'routes' => [
        // Only routes whose URI matches one of these patterns are documented.
        'include' => ['api/*'],

        // Routes matching these URI patterns are always skipped.
        'exclude' => [
            'api/documentation*',
            '_debugbar/*',
            'telescope*',
            'horizon*',
            'sanctum/*',
            'up',
        ],

        // Only routes served by one of these middleware groups (empty = any).
        'middleware' => [],

        // Skip routes handled by a Closure instead of a controller.
        'skip_closures' => false,

        // HTTP verbs that never show up on their own.
        'skip_methods' => ['HEAD', 'OPTIONS'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grouping
    |--------------------------------------------------------------------------
    |
    | "controller" -> group by controller class name (UserController => Users)
    | "uri"        -> group by the first meaningful URI segment (api/users => Users)
    |
    | A @group docblock tag or #[ApiGroup] attribute always wins over both.
    |
    */

    'grouping' => [
        'strategy' => 'controller',
        'default' => 'General',
        'order' => [], // e.g. ['Authentication', 'Users'] – listed groups come first
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication detection
    |--------------------------------------------------------------------------
    */

    'auth' => [
        // Middleware (prefixes) that mark an endpoint as authenticated.
        'middleware' => ['auth', 'auth:sanctum', 'auth:api', 'auth.basic', 'jwt.auth', 'jwt.verify'],

        'header' => 'Authorization',

        'value' => 'Bearer {YOUR_API_TOKEN}',

        'description' => 'Bearer token issued by the authentication endpoints.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers added to every documented endpoint
    |--------------------------------------------------------------------------
    */

    'headers' => [
        'defaults' => [
            'Accept' => 'application/json',
        ],

        // Sent on requests that carry a body (POST/PUT/PATCH).
        'body' => [
            'Content-Type' => 'application/json',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Responses
    |--------------------------------------------------------------------------
    */

    'responses' => [
        // Default success status per HTTP verb when nothing else can be inferred.
        'default_status' => [
            'GET' => 200,
            'POST' => 201,
            'PUT' => 200,
            'PATCH' => 200,
            'DELETE' => 204,
        ],

        // Automatically document the common failure paths.
        'include_errors' => true,

        // How deep API resources are followed when building a response shape.
        'max_depth' => 4,

        // Wrap inferred resource payloads in this key (null = no wrapper).
        'resource_wrapper' => 'data',
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAPI
    |--------------------------------------------------------------------------
    |
    | The scan result is compiled into a standards compliant OpenAPI document,
    | and that document is what the Blade UI renders. Point "source" at an
    | existing spec file to document an API this application does not own.
    |
    */

    'openapi' => [
        // "routes" builds the spec from this application, or give a path/URL
        // to an existing OpenAPI 3.x document (json or yaml).
        'source' => 'routes',

        'version' => '3.1.0',

        'servers' => [
            // ['url' => 'https://api.example.com/v1', 'description' => 'Production'],
        ],

        // Reusable component schemas are emitted for API resources so the
        // document stays DRY and tools can resolve $ref pointers.
        'use_components' => true,

        'security_schemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
                'description' => 'Bearer token issued by the authentication endpoints.',
            ],
        ],

        // Security scheme applied to endpoints detected as authenticated.
        'default_security_scheme' => 'bearerAuth',

        'contact' => [
            // 'name' => 'API Support', 'email' => 'support@example.com', 'url' => 'https://example.com',
        ],

        'license' => [
            // 'name' => 'MIT', 'identifier' => 'MIT',
        ],

        'terms_of_service' => null,

        // Extra keys merged into the generated document (external docs, x-* ...).
        'extensions' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Code samples shown next to every endpoint
    |--------------------------------------------------------------------------
    */

    'code_samples' => ['curl', 'javascript', 'php'],

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    */

    'ui' => [
        'theme' => 'auto',            // auto | light | dark
        'logo' => null,               // absolute URL to a logo image
        'try_it' => true,             // enable the in-page request console
        'show_controllers' => true,   // show the handling controller@method
        'show_middleware' => true,
        'collapse_groups' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint history
    |--------------------------------------------------------------------------
    |
    | Every time the documentation is generated it is compared against the last
    | recorded snapshot, and the difference is stored as a revision. The page
    | then shows, per endpoint, when it changed and what changed about it.
    |
    | Keep the history file in version control so the timeline survives across
    | environments and deployments.
    |
    */

    'history' => [
        'enabled' => true,

        'path' => 'resources/views/vendor/api-docs/history.json',

        // How many revisions to retain (oldest are dropped first).
        'keep' => 50,

        // Show the changelog and the per endpoint history on the page.
        'show_in_ui' => true,

        // How many revisions are listed under a single endpoint.
        'per_endpoint' => 5,

        // How many revisions the changelog at the top of the page shows.
        'changelog' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the generated Blade documentation is written
    |--------------------------------------------------------------------------
    |
    | The generator writes real Blade files into the host project so the docs
    | live next to the rest of your views and stay fully customisable.
    |
    */

    'output' => [
        'views_path' => 'resources/views/vendor/api-docs',
        'spec_file' => 'resources/views/vendor/api-docs/openapi.json',
        'static_html' => 'public/docs/index.html',
        'overwrite_views' => false, // keep local template tweaks on re-generate
    ],

    /*
    |--------------------------------------------------------------------------
    | The route the documentation is served from
    |--------------------------------------------------------------------------
    */

    'serve' => [
        'enabled' => true,
        'path' => 'api/documentation',
        'name' => 'api-docs.index',
        'middleware' => ['web'],
        'domain' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi tenancy
    |--------------------------------------------------------------------------
    |
    | A multi tenant application serves the same routes from many contexts, so
    | the artefacts have to be scoped per tenant. Put a {tenant} placeholder
    | anywhere in this file - output paths, the history path, the cache key,
    | the base URL, the title, the OpenAPI servers - and it is replaced with
    | the current tenant key:
    |
    |     'spec_file' => 'resources/views/vendor/api-docs/{tenant}/openapi.json',
    |     'base_url'  => 'https://{tenant}.example.com',
    |
    | The tenant is detected automatically for stancl/tenancy and
    | spatie/laravel-multitenancy. For anything else, point "resolver" at a
    | closure or an invokable class that returns the current tenant key.
    |
    */

    'tenancy' => [
        'enabled' => env('API_DOCS_TENANCY', false),

        // null = auto detect. Otherwise a closure or an invokable class name.
        'resolver' => null,

        // Used in place of {tenant} when no tenant is active.
        'central_key' => 'central',

        // "host" falls back to the request host when no tenancy package is found.
        'strategy' => null,

        // Never share one cached document between tenants.
        'scope_cache' => true,

        // Point the code samples and the try-it console at the host the docs
        // are actually being viewed on.
        'follow_request_host' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache the scan result instead of re-reading the code on every request
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => env('API_DOCS_CACHE', false),

        // Which cache store to use. null = the application default. Point this
        // at a store you know is reachable (for example "file") when the
        // default one lives somewhere the documentation cannot rely on, such
        // as a tenant database.
        'store' => env('API_DOCS_CACHE_STORE'),

        'key' => 'api-docs.documentation',

        'ttl' => 3600,
    ],
];
