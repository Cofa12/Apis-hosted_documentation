# Laravel API Docs

[![CI](https://github.com/Cofa12/Apis-hosted_documentation/actions/workflows/ci.yml/badge.svg)](https://github.com/Cofa12/Apis-hosted_documentation/actions/workflows/ci.yml)

Zero-config API documentation for Laravel, built on the **OpenAPI 3.1** standard and rendered as **Blade views inside your own project**.

Point it at an application and it reads the whole API — however the project is
organised — then compiles what it found into an OpenAPI document and renders
that document as a searchable reference page with headers, parameters, request
bodies, expected responses, code samples and an in-page request console.

It also remembers: every generation is compared against the last one, so each
endpoint carries a timeline of what changed about it and when.

```bash
composer require cofa/laravel-api-docs
php artisan api-docs:generate
```

Then open `/api/documentation`.

---

## Why it works on any project layout

The scanner reads **Laravel's route collection**, not your directory tree. By
the time it runs, every route is registered — whether it came from
`routes/api.php`, a module's service provider, a package, an invokable
controller or a closure. Nothing has to be moved, renamed or annotated.

From each route it then works backwards through the code that handles it:

| What you get | Where it comes from |
| --- | --- |
| Method, URI, name, middleware, handler | the route itself |
| Group, summary, description | `@group` / docblock / `#[ApiGroup]` / controller name |
| URL parameters | URI placeholders, `where()` constraints, route model binding, action type hints |
| Body parameters | the form request's `rules()`, or `$request->validate([...])` / `Validator::make()` inside the action |
| Query parameters | `$request->query()`, `input()`, `boolean()`, `integer()`… plus `paginate()` detection |
| Types, constraints, enums, examples | the validation rules, including `Rule::in()`, `Rule::enum()`, `Password::min()` |
| Headers | configured defaults, auth middleware, `#[ApiHeader]`, `@header` |
| Authentication | auth middleware, `@authenticated`, `#[Authenticated]` |
| Success responses | API resources (followed into nested resources), models, `response()->json()`, `@response` |
| Error responses | auth (401), authorization (403), model binding (404), validation (422), throttling (429), `abort()` |
| Change history | the diff between this generation and the last recorded snapshot |

Rules are read by instantiating the form request when that is safe, and by
parsing the source with [nikic/php-parser](https://github.com/nikic/PHP-Parser)
when it is not — so rules that depend on runtime state still get documented.
An action the generator cannot read is reported, never fatal: the rest of the
API is still documented.

## OpenAPI is the source of truth

The scan result is compiled into an OpenAPI 3.1 document, and **the Blade UI
renders that document**. That means:

* `GET /api/documentation.json` serves a spec you can hand to Postman, Insomnia, an SDK generator or a contract test.
* Resources become reusable `components/schemas` entries referenced with `$ref`.
* Validation rules become real JSON Schema (`minLength`, `maximum`, `pattern`, `enum`, `format`, union types for nullables).
* Authentication becomes a `securitySchemes` entry plus per-operation `security`.
* The renderer also works on a spec **you did not generate** — point `openapi.source` at any OpenAPI 3.x file or URL and it will document that instead.

Nothing OpenAPI reserves is lost either: `Accept`, `Content-Type` and
`Authorization` stay out of `parameters` (as the specification requires) but are
kept on the operation under `x-headers`, alongside `x-controller`,
`x-middleware` and `x-route-name`.

## Endpoint history

Every `api-docs:generate` compares the new document against the last recorded
snapshot and stores the difference as a revision. The page then shows a
changelog of recent revisions, and each endpoint carries its own timeline:

```
rev-4  2026-08-30  1 added, 2 changed
  Added    POST /api/webhooks
  Changed  PUT  /api/users/{user}
      · Body field `email` is now required   [breaking]
      · Response 422 added
  Changed  GET  /api/users
      · Added query parameter `filter`
```

It tracks summaries and descriptions, grouping, deprecation, authentication,
path/query/header parameters, request body fields (nested ones included),
response status codes and response body fields — reporting each one as a
sentence rather than a JSON diff. Changes that can break an existing client (a
removed endpoint or field, a newly required field, newly required auth) are
flagged as breaking.

```bash
php artisan api-docs:history                      # the timeline, newest first
php artisan api-docs:history --endpoint=users     # only endpoints matching a path
php artisan api-docs:history --breaking           # only revisions that break clients
php artisan api-docs:history --json               # the raw record
php artisan api-docs:generate --no-history        # generate without recording
```

The record lives in `resources/views/vendor/api-docs/history.json`. Commit it:
that is what makes the timeline survive across environments and deployments.
Configure retention and display under `api-docs.history`, or set
`history.enabled` to `false` to turn the whole feature off.

## Installation

```bash
composer require cofa/laravel-api-docs
```

The service provider is auto-discovered. Publish the config if you want to tune it:

```bash
php artisan vendor:publish --tag=api-docs-config
```

## Usage

```bash
# scan, write openapi.json and the Blade templates into resources/views/vendor/api-docs
php artisan api-docs:generate

# overwrite templates you have already customised
php artisan api-docs:generate --force

# only refresh the spec
php artisan api-docs:generate --no-views

# also build a single self-contained HTML file (public/docs/index.html)
php artisan api-docs:generate --static

# just the spec, anywhere you like
php artisan api-docs:export storage/app/openapi.yaml --format=yaml
php artisan api-docs:export --print

# drop the cached document
php artisan api-docs:clear
```

You do not have to run anything at all in development: the route renders the
documentation live from the current code. Enable `api-docs.cache.enabled` in
production and refresh it during deployment.

The generated Blade files are ordinary views. Edit them and they stay edited —
`api-docs:generate` never overwrites an existing template unless you pass
`--force`.

## Documenting by hand

Everything is inferred, but anything can be overridden. Both a docblock and an
attribute syntax are available; explicit documentation always wins.

```php
/**
 * @group Users
 *
 * Endpoints for managing user accounts.
 */
class UserController
{
    /**
     * Create a user
     *
     * Creates the account and sends the welcome email.
     *
     * @authenticated
     * @header X-Tenant acme  The tenant the user belongs to.
     * @bodyParam name string required The full name. Example: Ada Lovelace
     * @queryParam notify boolean Send the welcome email. Example: true
     * @urlParam team integer required The team to add the user to.
     * @response 201 {"data": {"id": 1, "name": "Ada Lovelace"}}
     * @response 409 The email is already taken.
     * @apiResource 200 App\Http\Resources\UserResource
     */
    public function store(StoreUserRequest $request) { /* … */ }
}
```

The same thing with attributes:

```php
use Cofa\ApiDocs\Attributes\{ApiDoc, ApiGroup, ApiHeader, ApiParam, ApiResponse, Authenticated, HideFromDocs};

#[ApiGroup('Users', description: 'Endpoints for managing user accounts.')]
class UserController
{
    #[ApiDoc(summary: 'Create a user', description: 'Creates the account and sends the welcome email.')]
    #[Authenticated]
    #[ApiHeader(name: 'X-Tenant', value: 'acme', required: true)]
    #[ApiParam(name: 'notify', type: 'boolean', in: 'query', description: 'Send the welcome email.')]
    #[ApiResponse(status: 201, resource: UserResource::class)]
    #[ApiResponse(status: 409, content: ['message' => 'That email is taken.'])]
    public function store(StoreUserRequest $request) { /* … */ }
}
```

Hide something with `#[HideFromDocs]` or `@ignore` on the action or the whole
controller.

Form requests can describe their own fields, and the descriptions are merged
with the ones derived from the rules:

```php
public function bodyParameters(): array
{
    return [
        'name' => ['description' => 'The full name of the user.', 'example' => 'Ada Lovelace'],
    ];
}
```

## Configuration

`config/api-docs.php` covers:

* **`routes`** — include/exclude URI patterns, required middleware, whether to skip closures.
* **`grouping`** — group by controller or by URI segment, plus an explicit group order.
* **`auth`** — which middleware means "authenticated", and the header to show.
* **`headers`** — defaults sent with every request, and with every body.
* **`responses`** — default status per verb, whether to document error paths, resource wrapper key, how deep to follow nested resources.
* **`openapi`** — spec version, servers, contact/license, security schemes, whether to emit component schemas, and `source` for rendering an external spec.
* **`code_samples`** — any of `curl`, `javascript`, `php`, `python`.
* **`ui`** — theme, logo, try-it console, whether to show controllers and middleware.
* **`output`** — where the templates, the spec and the static build are written.
* **`serve`** — path, route name, middleware and domain for the documentation route.
* **`cache`** — cache the compiled document instead of re-reading the code on every request.

## The page itself

* Three-pane layout with a grouped, filterable sidebar (press `/` to search).
* Method-coloured endpoint cards that collapse, deep-link and print cleanly.
* Parameter tables with types, requiredness, constraints, enum values and examples — nested objects and arrays included.
* Response tabs per status code with syntax-highlighted bodies and an expandable schema table.
* Code samples in cURL, JavaScript, PHP and Python, filled in with real example values.
* A "try it" console that sends the request from the browser and shows the live response.
* A changelog of recent revisions, and a per-endpoint history showing what changed and when.
* Light and dark themes, respecting the system preference and remembering the choice.
* No CDN, no build step, no external requests — the CSS and JS are inlined, so it works behind a strict CSP and offline.

## Testing

```bash
composer install
composer test
```

218 tests cover the rule parser, docblock parser, parameter nesting, schema
generation, the spec reader (including third-party OpenAPI documents), code
samples, the change differ and history store, the scanner end to end, the
generated document, the rendered page and every console command.

CI runs the suite on PHP 8.2, 8.3 and 8.4 on every push and pull request.

## Requirements

* PHP 8.2+
* Laravel 12

The code itself runs unchanged on Laravel 10 and 11 — the suite passes against
both — but those branches are past their security support window, so a current
Composer refuses to install them and they are not listed as supported. If you
are pinned to one of them, require this package with your own advisory
exception (`policy.advisories.block`) and it will work.

## License

MIT.
