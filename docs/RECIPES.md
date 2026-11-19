# Recipes

Concrete, copy-pasteable answers. Each recipe lists every file it touches, so
when something breaks you know where to look.

## Add a page

1. `app/Controllers/ReportController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Polaris\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ReportController
{
    public function __construct(private View $view)
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'reports/index.twig', [
            'page_title' => 'Reports',
        ]);
    }
}
```

2. `resources/views/reports/index.twig`

```twig
{% extends 'layout.twig' %}
{% block content %}
<h1>{{ page_title }}</h1>
{% endblock %}
```

3. `app/routes.php`

```php
$app->get('/reports', ReportController::class . ':index');
```

A page that only renders needs no service and no repository.

## Protect a route

```php
use Polaris\Middleware\AuthMiddleware;
use Polaris\Middleware\AdminMiddleware;

$app->get('/dashboard', DashboardController::class . ':index')
    ->add(AuthMiddleware::class);

$app->get('/admin', AdminController::class . ':index')
    ->add(AdminMiddleware::class);
```

`AdminMiddleware` already implies a signed-in user.

## Send a custom email

Extend the kernel mailer; its helpers are `protected` precisely for this:

```php
namespace App\Services;

use Polaris\Mail\Mailer;

final class MailService extends Mailer
{
    public function sendInvoice(string $to, string $number, string $url): bool
    {
        $content = '<p>' . $this->translator->trans('mail.invoice_body', ['number' => $number]) . '</p>'
            . '<p><a href="' . htmlspecialchars($url) . '">'
            . $this->translator->trans('mail.invoice_button') . '</a></p>';

        return $this->send(
            $to,
            $this->translator->trans('mail.invoice_subject', ['number' => $number]),
            $this->layout($this->translator->trans('mail.invoice_heading'), $content),
        );
    }
}
```

Bind it in `app/bootstrap-definitions.php` (or wherever the site keeps its
container definitions):

```php
Mailer::class      => static fn (ContainerInterface $c): Mailer => $c->get(MailService::class),
MailerInterface::class => static fn (ContainerInterface $c): MailerInterface => $c->get(MailService::class),
```

## Add Twig helpers for your own templates

```php
$app = Kernel::boot(__DIR__, $definitions, $routes, static function (Twig\Environment $twig) use ($container): void {
    $twig->addFunction(new Twig\TwigFunction(
        'money',
        static fn (int $cents): string => number_format($cents / 100, 2, ',', '.'),
    ));
});
```

The closure runs after the kernel helpers, so you can also replace one.

## Add an extra Twig global

Implement `Polaris\View\ViewGlobalsProvider` and register it:

```php
final class SiteGlobals implements Polaris\View\ViewGlobalsProvider
{
    public function globals(): array
    {
        return ['company_name' => 'Acme Ltd'];
    }
}
```

## Add a table with admin CRUD

The generator is the intended path (`bin/make.php crud --diff` shows the plan,
`--force` overwrites). Use it rather than copying an entity by hand: it writes
the repository, API group, admin controller, templates and migration together.
If you still need to see the shape it produces:

- `app/Repositories/XRepository.php` — SQL only
- `app/Services/XService.php` — validation and rules
- `app/Controllers/Admin/XController.php` — HTTP
- `resources/views/admin/x/{index,form}.twig`
- `database/upgrades/YYYYMMDDHHMMSS_create_x.sql` — idempotent, with the
  indexes the list screen needs

## Add a writable setting

1. Declare the rule in `config/settings.php`:

```php
'writable_settings' => [
    'company_vat' => 'required|max:30',
],
```

2. The kernel's `SettingsService` rejects unknown keys and invalid values, and
   `SettingRepository` caches the table for the request.

3. Read it anywhere with `SettingRepository::get('company_vat')`.

## Add a theme

```
themes/acme/
  theme.json
  views/layout.twig        overrides the kernel layout
  views/errors/404.twig    overrides one page
  assets/css/app.css
```

Set `THEME=acme` in `.env`. Only the files you copy are overridden; everything
else falls back to the application, then the kernel.

## Add a custom error page

Copy `kernel/resources/views/errors/404.twig` into `resources/views/errors/` and
edit it. No configuration needed: the cascade finds it first.

## Run something on a schedule

`php bin/cron.php` is the runner. Register tasks in the site's `config/cron.php`
as a map of name → callable receiving the container:

```php
return [
    'reminders.send_due' => static fn (ContainerInterface $c): int =>
        $c->get(ReminderService::class)->sendDue(),
];
```

The runner prunes the cache, then calls each task in order. A failure is logged
and recorded in the admin health panel; one failing task does not stop the rest,
and the process exits non-zero so the scheduler notices.

## Store an uploaded image

Inject `Polaris\Services\ImageService` into the controller and pass it the
temporary file from `$_FILES` (through the request's uploaded-files bag):

```php
$image = $this->images->store($request->getUploadedFiles()['photo']->getFilePath());
// $image['url'] is /uploads/<random>.<ext>; store $image['file'] in the database.
```

The service detects the type from the bytes, allows only JPEG/PNG/WebP, rejects
anything else, and re-encodes the pixels into a new file with a random name.
Never move an uploaded file into `uploads/` yourself: the directory's
`.htaccess` stops execution, but only this service guarantees the content is an
inert image.

## Add a database migration

1. Create `database/upgrades/20260101120000_add_invoice_number.sql`.
2. Write it idempotent: `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN` guarded by a
   check, `INSERT ... ON DUPLICATE KEY UPDATE`.
3. Never edit a migration that has already been applied; add a new one.

## Deploy

```bash
php bin/package.php          # builds deploy/ with changed files
php bin/deploy.php --upload  # FTP push + pending SQL, after the test gate
```

The guard runs `composer check` first and aborts on failure. The uploaded
migration runner deletes itself after applying the SQL.
