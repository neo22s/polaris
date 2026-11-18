# Security policy

## Reporting

Email the maintainer instead of opening a public issue. Include the affected
version (`kernel/VERSION`), a reproduction and the impact you expect.

You can expect an acknowledgement within 72 hours and a fix or a plan within
30 days for confirmed issues.

## Scope

- Authentication and session handling
- CSRF and request validation
- SQL injection, path traversal, file upload
- Template escaping
- Secrets handling and logging

## Practices

- All SQL uses prepared statements; `Database` binds values with their real
  types.
- Templates autoescape HTML. Raw output is explicit and greppable.
- Uploads are re-encoded, never trusted by extension or MIME header.
- Magic links are hashed at rest, single use, and expire.
- Redirects from request data go through `Url::safePath()`.
- Production runs with `APP_DEBUG=false` and shows no stack traces.
