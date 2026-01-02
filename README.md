# JSON:API Frontend Layout Builder

`jsonapi_frontend_layout` is an optional add-on for `jsonapi_frontend` that exposes a normalized Layout Builder tree for true headless rendering.

## What it does

- Adds `GET /jsonapi/layout/resolve?path=/about-us&_format=json`
- Internally calls `jsonapi_frontend`’s resolver so aliases, redirects, language negotiation, and access checks behave the same
- When the resolved path is an entity rendered with Layout Builder, the response includes a `layout` tree (sections + components)

## Install

```bash
composer require drupal/jsonapi_frontend_layout
drush en jsonapi_frontend_layout
```

## Usage

```http
GET /jsonapi/layout/resolve?path=/about-us&_format=json
```

The response matches `/jsonapi/resolve` and adds a `layout` object when applicable:

- `layout.sections[]` includes `layout_id`, `layout_settings`, and normalized `components[]`
- Supported component types (MVP): `field_block`, `extra_field_block`, `inline_block`

## Notes

- This module is intentionally read-only and mirrors `jsonapi_frontend` caching behavior (anonymous cacheable; authenticated `no-store`).
- For rendering, you still fetch the resolved `jsonapi_url` (entity) and any referenced block content via JSON:API.

