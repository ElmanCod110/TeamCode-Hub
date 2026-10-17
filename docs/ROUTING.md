# Routing

## Conventions

- All application routes enter through `public/index.php`.
- Pretty URLs are handled centrally; feature code must not inspect raw paths to make authorization decisions.
- HTTP method and path both participate in route matching.
- Route parameters are decoded and exposed as request data; handlers must validate them before use.
- A route match is not an authorization decision. Protected handlers must invoke an authorization policy/service before reading or changing protected resources.
- Return consistent 404 responses for unknown routes. Avoid exposing stack traces or internal paths to clients.

## Current foundation

The minimal router supports explicit method/path registration, named path parameters, and a not-found response. It is an initial primitive, not a complete middleware, authorization, or routing framework. Those features must be added with tests before protected application routes are introduced.
