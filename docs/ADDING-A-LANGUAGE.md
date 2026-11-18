# Adding a language

A Polaris site ships the languages it declares. Nothing is hardcoded: adding a
language is three files and one line.

## 1. Add the language file

Copy the English file and translate the values:

```bash
cp resources/lang/en.php resources/lang/fr.php
```

Keys stay in English; only the values change. `Polaris\Tests\KernelPurityTest`
(or the site's own parity test) fails if the key sets differ, which is the
cheap way to catch "the French page shows `mail.magic_subject`".

The kernel's own strings are merged in, so you only need the keys the site
actually uses. A missing key falls back to the default language, never to an
empty string.

## 2. Declare it

In the site's `config/settings.php`, or in `.env`:

```php
'locales' => ['en', 'fr'],
```

```dotenv
LOCALES=en,fr
```

The order is the order of the language switcher. The first one is the default
and the fallback.

That single list drives:

- locale detection from the URL prefix (`/fr/…`),
- the account and session preference,
- the `Accept-Language` fallback,
- the `<html lang>` attribute,
- the language switcher,
- the `<link rel="alternate" hreflang>` tags,
- the translation editor in the admin.

A site that lists `['en']` never offers, detects or renders a second language,
even when the browser asks for one.

## 3. Translate the content

Database content (CMS pages) is per locale. A page is visible under
`/{locale}/{slug}`, so a French page is `/fr/mentions-legales`:

- The admin page editor already stores one slug and title per language it is
given.
- `translations` can be overridden at runtime from the admin, so a wording fix
  does not need a deploy.

## How the locale is chosen for a request

Highest priority first:

1. **URL prefix** — `/fr/faq` renders French, always. This is what makes a
   shared link reliable.
2. **Account preference** — the language saved on the signed-in user.
3. **Session choice** — what the switcher last set for this visit.
4. **`Accept-Language`** — the browser's order, honouring `q` values.
5. **Default** — the first language in `locales`.

The URL winning over the account is deliberate: otherwise a Spanish link sent
to an English account would render half in each language.

## Right-to-left languages

`<html dir>` comes from the language itself, not from a hardcoded `ltr`. When
adding `ar` or `he`, add the direction to the language file:

```php
'lang.dir' => 'rtl',
```

and print it in the layout:

```twig
<html lang="{{ locale }}" dir="{{ t('lang.dir') }}">
```

## Checklist

- [ ] `resources/lang/<code>.php` exists and has every key
- [ ] `locales` includes the code
- [ ] the switcher shows it
- [ ] `/ <code>` renders translated strings **and** `<html lang="<code>">`
- [ ] CMS pages have a slug for that language
- [ ] the sitemap lists the new URLs
- [ ] `composer check` passes
