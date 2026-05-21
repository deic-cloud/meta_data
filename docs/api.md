# Metadata App — API Reference

All endpoints are served via the Nextcloud OCS framework. Prefix every URL with your Nextcloud base URL:

```
https://<nextcloud>/ocs/v2.php/apps/meta_data
```

**Authentication** — Use HTTP Basic auth (`Authorization: Basic <base64(user:password)>`) or any valid Nextcloud session token (`OCS-APIREQUEST: true` header required with Basic auth).

**Response format** — All responses are JSON-wrapped in the OCS envelope:

```json
{
  "ocs": {
    "meta": { "status": "ok", "statuscode": 200, ... },
    "data": { ... }
  }
}
```

The tables below show the contents of `ocs.data`.

**Identifier flexibility** — Wherever a `{tagId}` or `{keyId}` appears in a URL, and wherever `tag`, `tagid`, `attribute`, `keyid` appear as parameters, you may supply either the numeric ID or the human-readable name. Wherever a file is identified, you may supply either a numeric `fileid` or a user-relative `file` path (e.g. `/Photos/sunset.jpg`).

---

## Tags

A **tag** is a named metadata schema. Tags are shared across all users (Nextcloud collaborative tags). Each tag can have a color and a description.

### List / search tags

```
GET /api/v1/tags
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | Name pattern. Use `%` as wildcard (e.g. `Astro%`). Default: `%` (all tags). |
| `fileCount` | bool | If `true`, include the number of files carrying each tag. |

**Response**

```json
{
  "tags": [
    {
      "id": 42,
      "name": "Astronomy data",
      "description": "Raw telescope observations",
      "color": "ff9900",
      "userVisible": true,
      "userAssignable": true,
      "size": 17
    }
  ]
}
```

`size` is only present when `fileCount=true`.

#### Example

```
curl -u admin:dummy \
    -H "OCS-APIREQUEST: true" \
    "http://localhost/ocs/v2.php/apps/meta_data/api/v1/tags?format=json"
```
---

### Get a single tag

```
GET /api/v1/tags/{tagId}
```

`{tagId}` — numeric ID **or** tag name.

**Response**

```json
{ "tag": { "id": 42, "name": "Astronomy data", "description": "...", "color": "ff9900", ... } }
```

---

### Create a tag

```
POST /api/v1/tags
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | **Required.** Tag name. |
| `color` | string | Hex color without `#`, e.g. `ff9900`. Optional. |

**Response**

```json
{ "tag": { "id": 42, "name": "Astronomy data", ... } }
```

Returns `400` if the name is empty or the tag already exists.

---

### Update a tag

```
PUT /api/v1/tags/{tagId}
```

`{tagId}` — numeric ID **or** tag name.

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | New name. Optional. |
| `description` | string | New description. Optional. |
| `color` | string | New hex color. Optional. |

**Response**

```json
{ "success": true }
```

---

### Delete a tag

```
DELETE /api/v1/tags/{tagId}
```

`{tagId}` — numeric ID **or** tag name.

Deletes the tag and all associated attribute definitions and file metadata values.

**Response**

```json
{ "success": true }
```

---

## Attributes (keys)

Each tag has a schema — a list of named **attributes** (key definitions). Attribute values are stored per file.

### List attributes of a tag

```
GET /api/v1/tags/{tagId}/keys
```

`{tagId}` — numeric ID **or** tag name.

**Response**

```json
{
  "keys": [
    { "id": 7, "tagid": 42, "name": "Instrument", "type": "", "allowed_values": null },
    { "id": 8, "tagid": 42, "name": "Spectral Band", "type": "controlled", "allowed_values": "[\"UV\",\"Optical\",\"IR\"]" }
  ]
}
```

`allowed_values` is a JSON-encoded array when `type` is `controlled`, otherwise `null`.

---

### Add an attribute to a tag

```
POST /api/v1/tags/{tagId}/keys
```

`{tagId}` — numeric ID **or** tag name.

| Parameter | Type | Description |
|-----------|------|-------------|
| `keyname` | string | **Required.** Attribute name. |
| `type` | string | `controlled` or `json`. Optional. |
| `controlledvalues` | string | JSON-encoded array of allowed values, e.g. `["UV","Optical","IR"]`. Only used when `type=controlled`. |

**Response**

```json
{ "key": { "id": 7, "tagid": 42, "name": "Instrument", "type": "", "allowed_values": null } }
```

---

### Update an attribute

```
PUT /api/v1/tags/{tagId}/keys/{keyId}
```

`{tagId}` — numeric ID **or** tag name.  
`{keyId}` — numeric ID **or** attribute name.

| Parameter | Type | Description |
|-----------|------|-------------|
| `keyname` | string | New attribute name. |
| `type` | string | New type. Optional. |
| `controlledvalues` | string | New allowed values (JSON array). Optional. |

**Response**

```json
{ "success": true }
```

---

### Delete an attribute

```
DELETE /api/v1/tags/{tagId}/keys/{keyId}
```

`{tagId}` — numeric ID **or** tag name.  
`{keyId}` — numeric ID **or** attribute name.

Also removes all stored values for this attribute across all files.

**Response**

```json
{ "success": true }
```

---

## File–tag associations

### Add a tag to a file

```
PUT /api/v1/filetags
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `fileid` | int | Numeric file ID. Use this **or** `file`. |
| `file` | string | User-relative file path, e.g. `/Photos/sunset.jpg`. Use this **or** `fileid`. |
| `tagid` | int | Numeric tag ID. Use this **or** `tag`. |
| `tag` | string | Tag name. Use this **or** `tagid`. |

**Response**

```json
{ "success": true }
```

---

### Remove a tag from a file

```
DELETE /api/v1/filetags
```

Same parameters as **Add a tag to a file**.

**Response**

```json
{ "success": true }
```

---

### Get tags for one or more files

```
POST /api/v1/filetags
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `fileids` | int[] | Array of numeric file IDs. |
| `files` | string[] | Array of user-relative file paths. |
| `file` | string | Single user-relative file path. |

Any combination of the three parameters is accepted.

**Response**

```json
{
  "files": [
    {
      "id": 1234,
      "tags": [
        { "id": 42, "name": "Astronomy data", "color": "ff9900", ... }
      ]
    }
  ]
}
```

---

### List files carrying a tag

```
GET /api/v1/tags/{tagId}/files
```

`{tagId}` — numeric ID **or** tag name.

Returns all files the current user can access that carry the given tag.

**Response**

```json
{
  "files": [
    {
      "id": 1234,
      "name": "observation.fits",
      "path": "/admin/files/Data/observation.fits",
      "type": "file",
      "size": 2097152,
      "mtime": 1713600000,
      "mimetype": "application/fits",
      "permissions": 31,
      "tags": [ ... ]
    }
  ]
}
```

---

### Search files by attribute value

```
GET /api/v1/searchfiles
```

Find files that have the given tag and whose attribute value matches the pattern.

| Parameter | Type | Description |
|-----------|------|-------------|
| `tag` | string | **Required.** Numeric tag ID or tag name. |
| `attribute` | string | Numeric key ID or attribute name. Optional — omit to search across all attributes. |
| `value` | string | Value pattern. Use `%` as wildcard, e.g. `VLT%`. Required if `attribute` is given. |

**Response** — same format as **List files carrying a tag**.

**Example**

```
GET /api/v1/searchfiles?tag=Astronomy data&attribute=Instrument&value=VLT%
```

---

## File metadata values

### Get metadata for a file

```
GET /api/v1/getmetadata
```

Returns attribute names and values (human-readable).

| Parameter | Type | Description |
|-----------|------|-------------|
| `fileid` | int | Numeric file ID. Use this **or** `file`. |
| `file` | string | User-relative file path. Use this **or** `fileid`. |
| `tagid` | int | Numeric tag ID. Use this **or** `tag`. |
| `tag` | string | Tag name. Use this **or** `tagid`. |

**Response**

```json
{
  "tag": "Astronomy data",
  "attributes": [
    { "name": "Instrument", "value": "VLT/UVES" },
    { "name": "Spectral Band", "value": "Optical" }
  ]
}
```

---

### Set a metadata value

```
POST /api/v1/filemeta
```

Creates or updates a single attribute value for a file+tag combination.

| Parameter | Type | Description |
|-----------|------|-------------|
| `fileid` | int | Numeric file ID. Use this **or** `file`. |
| `file` | string | User-relative file path. Use this **or** `fileid`. |
| `tagid` | int | Numeric tag ID. Use this **or** `tag`. |
| `tag` | string | Tag name. Use this **or** `tagid`. |
| `keyid` | int | Numeric attribute ID. Use this **or** `attribute`. |
| `attribute` | string | Attribute name. Use this **or** `keyid`. |
| `value` | string | **Required.** Value to store. |

**Response**

```json
{ "success": true }
```

---

### Get raw metadata (key IDs)

```
GET /api/v1/filemeta
```

Same parameters as **Get metadata**, but returns numeric key IDs instead of names. Used internally by the sidebar UI.

**Response**

```json
{
  "data": [
    { "keyid": 7, "value": "VLT/UVES" },
    { "keyid": 8, "value": "Optical" }
  ]
}
```

---

## Quick reference

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/tags` | List / search tags |
| POST | `/api/v1/tags` | Create a tag |
| GET | `/api/v1/tags/{tagId}` | Get a tag |
| PUT | `/api/v1/tags/{tagId}` | Update a tag |
| DELETE | `/api/v1/tags/{tagId}` | Delete a tag |
| GET | `/api/v1/tags/{tagId}/keys` | List attributes |
| POST | `/api/v1/tags/{tagId}/keys` | Add an attribute |
| PUT | `/api/v1/tags/{tagId}/keys/{keyId}` | Update an attribute |
| DELETE | `/api/v1/tags/{tagId}/keys/{keyId}` | Delete an attribute |
| PUT | `/api/v1/filetags` | Add tag to file |
| DELETE | `/api/v1/filetags` | Remove tag from file |
| POST | `/api/v1/filetags` | Get tags for file(s) |
| GET | `/api/v1/tags/{tagId}/files` | List files with tag |
| GET | `/api/v1/searchfiles` | Search files by attribute value |
| GET | `/api/v1/getmetadata` | Get file metadata (named) |
| POST | `/api/v1/filemeta` | Set a metadata value |
| GET | `/api/v1/filemeta` | Get file metadata (raw key IDs) |
