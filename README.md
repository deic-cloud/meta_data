# meta_data — Metadata Tags

Tag schemas with typed key/value fields for files and directories, integrated with Nextcloud systemtags.

Authors: Christian Brinch, DTU and Frederik Orellana, DTU (frederik@orellana.dk) — developed for the [ScienceData](https://sciencedata.dk) cloud platform. Ported to Nextcloud 34 with assistance from Claude Sonnet (Anthropic).

## Overview

`meta_data` lets users define named tag schemas — each tag has a set of typed key/value fields — and attach them to files and directories. Files can be searched and filtered by metadata values from the Nextcloud unified search bar.

Tags appear as chips in the Files app sidebar and file list (via `systemtags:node:updated`). In a ScienceData sharded deployment with `files_sharding`, tag schemas are propagated across nodes so that federated-share files carry the same tags on every node. Tag identity across nodes is resolved by name, not by systemtag ID (IDs differ per node).

## Requirements

- Nextcloud 34+
- PHP 8.2+
- `files_sharding` (optional) — required for cross-silo tag schema sync and federated-share tag lookup

## Installation

```bash
occ app:enable meta_data
```

Migrations run automatically.

## Configuration

Set in `config/config.php`:

| Key | Description |
|-----|-------------|
| `files_sharding_shared_secret` | Shared secret for inter-node calls on `/internal/` endpoints. Must match on all nodes. |

## API

All OCS endpoints are under `/ocs/v2.php/apps/meta_data`. Append `?format=json` for JSON responses.

### Tags

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/api/v1/tags` | List tags. Optional `name` filter (`%` wildcard). Add `fileCount=1` for per-tag file counts. |
| `GET` | `/api/v1/tags/{tagId}` | Get one tag by ID or name. |
| `POST` | `/api/v1/tags` | Create tag. |
| `PUT` | `/api/v1/tags/{tagId}` | Update tag. |
| `DELETE` | `/api/v1/tags/{tagId}` | Delete tag and all its keys. |

### Keys (fields within a tag schema)

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/api/v1/tags/{tagId}/keys` | List keys for a tag. |
| `POST` | `/api/v1/tags/{tagId}/keys` | Add key to tag. |
| `PUT` | `/api/v1/tags/{tagId}/keys/{keyId}` | Update key. |
| `DELETE` | `/api/v1/tags/{tagId}/keys/{keyId}` | Delete key. |

### File–tag associations

| Method | URL | Description |
|--------|-----|-------------|
| `POST` | `/api/v1/filetags` | Get tags for a file. Pass `fileId` or `filePath`. |
| `PUT` | `/api/v1/filetags` | Attach tag to file. |
| `DELETE` | `/api/v1/filetags` | Remove tag from file. |
| `GET` | `/api/v1/tags/{tagId}/files` | List files with a given tag. |
| `GET` | `/api/v1/searchfiles` | Search files by metadata key/value. |

### File key-value metadata

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/api/v1/filemeta` | Get key-value pairs for a file. |
| `POST` | `/api/v1/filemeta` | Set/update a key-value pair. |
| `GET` | `/api/v1/getmetadata` | Full metadata (tag + all key/value pairs) for a file. |

### Internal endpoints (inter-silo, shared-secret required)

`Authorization: Bearer <files_sharding_shared_secret>` required.

| Method | URL | Description |
|--------|-----|-------------|
| `POST` | `/internal/tags/sync` | Upsert tag schema by name (keys reconciled by name). |
| `POST` | `/internal/tags/delete` | Delete tag schema by name. |
| `POST` | `/internal/filetags-by-token` | Tags for a file identified by federated share token (DB-only, avoids DAV lazy-loading issue). |

## Architecture notes

**Cross-silo tag lookup via direct DB** — `getFileTagsByToken` queries `systemtag_object_mapping` directly to avoid a DAV `AppConfig` lazy-loading exception that occurs during federated-share requests on silo nodes.

**Tag identity by name** — Systemtag IDs are node-local. Sync operations match tags and keys by name; IDs are resolved locally after matching.

**`systemtags:node:updated` event** — Emitted when file–tag associations change, so the Files app refreshes chips without a page reload.

## Development

No build step. Plain PHP + jQuery (bundled in `js/jquery.min.js`; no npm).

```bash
rsync -av --delete apps/meta_data/ server:/var/www/nextcloud/apps/meta_data/
```
