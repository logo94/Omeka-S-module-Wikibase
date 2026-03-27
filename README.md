# Wikibase — Omeka S Module

Integration between Omeka S and a **Wikibase Cloud** instance (or any compatible Wikibase installation). The module allows linking Omeka item metadata to Wikibase entities, automatically enriching them with multilingual labels fetched from the Wikibase API at save time.

---

## Features

- **Autocomplete in the editor**: in configured properties, a search box suggests Wikibase entities as you type.
- **Automatic label population**: on save (manual, via API, or via CSV Import), providing only the URI of a Wikibase entity is enough — the module automatically fetches labels in the configured languages (e.g. Italian and English) and saves them as separate values.
- **Class filter**: suggestions can be filtered by Wikibase class (e.g. show only persons, only places).
- **Preload**: optionally, suggestions are shown immediately when the search box is focused, without typing.
- **CSV Import compatible**: works with both manual editing and bulk import via the CSV Import module.

---

## Requirements

- Omeka S **4.2** or higher
- PHP 7.4+
- Network access from the Omeka instance to the Wikibase API (port 443)
- **CSV Import** module (optional, for bulk imports)

---

## Installation

1. Copy the `Wikibase` folder into the Omeka S `modules/` directory.
2. Go to the administration panel → **Modules**.
3. Find **Wikibase** and click **Install**.
4. After installation, click **Configure** to set up your Wikibase instance.

---

## Configuration

Go to **Modules → Wikibase → Configure**.

### General settings

| Field | Description | Example |
|---|---|---|
| **Wikibase API URL** | API endpoint of the Wikibase instance | `https://my-instance.wikibase.cloud/w/api.php` |
| **Languages** | Label languages, comma-separated (order matters: first is the primary language) | `it, en` |
| **"Instance of" PID** | ID of the Wikibase property representing "instance of", used for class filtering | `P5` |

### Property mapping

For each Omeka property you want to link to Wikibase:

| Field | Description | Example |
|---|---|---|
| **Omeka property** | Property term in `vocabulary:term` format | `dcterms:creator` |
| **Wikibase class QIDs** | QIDs of the Wikibase classes used to filter suggestions (comma-separated) | `Q1640, Q1641` |
| **Label** | Label displayed in the search box placeholder | `Author` |
| **Preload** | If enabled, shows suggestions immediately on focus without typing | Yes / No |

---

## Usage

### Manual editing

1. Open or create an Item in Omeka S.
2. In configured properties (e.g. `dcterms:creator`) a search box appears above the URI field.
3. Type the name of the entity you are looking for: suggestions from the Wikibase API appear.
4. Click the desired suggestion: the URI field is populated automatically.
5. Save the item: the module fetches multilingual labels and saves them as additional values (one per language).

### Import via CSV Import

The CSV only needs to contain the Wikibase entity URIs in the columns of the mapped properties. Labels are added automatically at import time.

**Example CSV:**

```csv
dcterms:title,dcterms:creator,dcterms:spatial
"Portrait of a man",https://my-instance.wikibase.cloud/wiki/Item:Q718,https://my-instance.wikibase.cloud/wiki/Item:Q3083
"View of Rome",https://my-instance.wikibase.cloud/wiki/Item:Q719,https://my-instance.wikibase.cloud/wiki/Item:Q2442
```

**CSV Import mapping settings:**

- Data type for URI columns → select **URI**
- Language → leave empty (set automatically by the module)

> **Note:** the module intercepts all `uri` type values in mapped properties. No custom data type is required — standard Omeka S URI is sufficient.

### Import via API

Same logic as CSV Import: just provide the URI in the `@id` field with `type: uri`, labels are added automatically.

```json
{
  "dcterms:creator": [
    {
      "type": "uri",
      "@id": "https://my-instance.wikibase.cloud/wiki/Item:Q718"
    }
  ]
}
```

---

## How it works

### Autocomplete (frontend)

The file `asset/js/wikibase-suggest.js` detects configured properties in the admin editor and injects a search box above the URI input. Search requests are routed through the module's PHP proxy (`/wikibase/proxy`) to avoid CORS issues. Clicking a suggestion populates only the URI field; labels and language are handled server-side at save time.

### Label population (backend)

The module hooks into the `api.hydrate.pre` event of the `ItemAdapter`. Before Omeka hydrates values from the request, the module:

1. Scans `uri` type values in mapped properties that do not already have a label.
2. Extracts the QID from the URI (supports both `/entity/Q123` and `/wiki/Item:Q123` formats).
3. Calls `wbgetentities` on the Wikibase API to fetch labels in the configured languages.
4. Modifies the request by adding label and language to the existing value, and appending a separate value for each additional language.

If the API does not respond (timeout or error), the value is saved as-is without labels — the save operation never fails because of this.

### API proxy (`/wikibase/proxy`)

Handles search requests from the frontend to the Wikibase API, applying any configured class filters. Also exposes the `/wikibase/labels` endpoint for fetching labels by entity ID.

---

## Module structure

```
Wikibase/
├── Module.php                          # Core logic, event listeners
├── config/
│   ├── module.config.php               # Routes, controllers, default config
│   └── module.ini                      # Module metadata
├── asset/
│   └── js/
│       └── wikibase-suggest.js         # Frontend autocomplete
├── src/
│   ├── Controller/
│   │   └── ProxyController.php         # Proxy to Wikibase API
│   └── Service/
│       └── Controller/
│           └── ProxyControllerFactory.php
└── view/
    └── wikibase/
        └── admin/
            └── config-form.phtml       # Configuration form
```

---

## Supported URI formats

The module automatically recognises both URI formats used by Wikibase:

| Format | Example |
|---|---|
| Entity endpoint | `https://my-instance.wikibase.cloud/entity/Q123` |
| Wiki page | `https://my-instance.wikibase.cloud/wiki/Item:Q123` |

---

## Troubleshooting

**The search box does not appear in the editor**
- Check that the property is listed in the mapping under Configure.
- Verify that the module is active and the API URL is correct.

**Labels are not populated on save**
- Verify that Omeka S has network access to the Wikibase instance (port 443).
- Check that the URI contains a QID in the format `Q` followed by digits.
- Make sure the data type of the property is set to **URI** (not Literal or any other type).

**CSV Import saves only URIs without labels**
- Make sure to select **URI** as the data type in the CSV Import mapping screen.
- Verify that the column property terms match exactly those configured in the mapping (e.g. `dcterms:creator`).
- If a value already has a label, the module will not overwrite it.

---

## Author

**Logo94** — [GitHub](https://github.com/logo94)  
Bug reports and contributions: [Issues](https://github.com/logo94/Omeka-S-module-Wikibase/issues)