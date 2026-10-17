# Operations and Deployment

## Before deployment

- Configure the document root to the repository's public directory where possible.
- Configure environment variables or a private configuration file outside the web root.
- Create a dedicated database user with only the permissions the application requires.
- Enable HTTPS and configure session cookie security accordingly.
- Disable displayed PHP errors in production and enable restricted server-side error logs.
- Confirm private storage, configuration, backup, and log paths cannot be downloaded over HTTP.
- Back up the database and files before applying future schema changes.

## Initial health endpoint

GET /health reports whether the minimal request/router bootstrap responds. It is not a deep dependency check, does not connect to the database, and must not be interpreted as proof that all product features are healthy.

## Administrator bootstrap

Do not create an administrator based on the first visitor or an unauthenticated request. An eventual install flow must be explicit, one-time, and protected by a secret that is not stored in the public document root. Disable the installer after successful initialization.

## Shared-hosting constraints

Do not require a long-running worker or WebSocket server for the baseline. Use bounded AJAX polling and incremental cursors where real-time updates are introduced. Keep logs and caches bounded and define retention policies for personal/security data.
