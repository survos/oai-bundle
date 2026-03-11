# survos/oai-bundle

Symfony 8 / PHP 8.4 bundle providing:

1. **OAI-PMH 2.0 server** — expose your Doctrine entities as a standards-compliant metadata repository
2. **OAI-PMH harvester command** — pull records from any OAI-PMH endpoint into NDJSON for the import pipeline

---

## Installation

```bash
composer require survos/oai-bundle
```

Enable the bundle if not using Flex:

```php
// config/bundles.php
Survos\OaiBundle\SurvosOaiBundle::class => ['all' => true],
```

Import routes:

```yaml
# config/routes.yaml
survos_oai:
    resource: '@SurvosOaiBundle/config/routes.yaml'
```

---

## Part 1: Serving OAI-PMH

### Implement `OaiDataProviderInterface`

Create a service that answers questions about your data:

```php
use Survos\OaiBundle\Contract\OaiDataProviderInterface;
use Survos\OaiBundle\Contract\OaiRecordInterface;
use App\Repository\ItemRepository;

final class ItemOaiProvider implements OaiDataProviderInterface
{
    public function __construct(private readonly ItemRepository $items) {}

    public function getRepositoryName(): string  { return 'My Museum Collection'; }
    public function getBaseUrl(): string          { return 'https://example.org/oai'; }
    public function getAdminEmails(): array       { return ['admin@example.org']; }
    public function getDeletedRecordPolicy(): string { return 'persistent'; }

    public function getEarliestDatestamp(): \DateTimeInterface
    {
        return $this->items->findEarliestModified() ?? new \DateTimeImmutable('2000-01-01');
    }

    public function getSets(): array
    {
        return [
            ['spec' => 'photographs', 'name' => 'Photographs', 'description' => null],
            ['spec' => 'documents',   'name' => 'Documents',   'description' => null],
        ];
    }

    public function getRecord(string $identifier): ?OaiRecordInterface
    {
        $item = $this->items->findByOaiIdentifier($identifier);
        return $item ? new ItemOaiRecord($item) : null;
    }

    public function getRecords(
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        ?string $set,
        int $offset,
        int $limit,
    ): iterable {
        return $this->items->findForOai($from, $until, $set, $offset, $limit);
    }

    public function countRecords(
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        ?string $set,
    ): ?int {
        return $this->items->countForOai($from, $until, $set);
    }
}
```

The service is auto-detected via Symfony autoconfiguration (tagged `survos_oai.data_provider`).

### Implement `OaiRecordInterface`

Wrap your entity:

```php
use Survos\OaiBundle\Contract\OaiRecordInterface;
use App\Entity\Item;

final readonly class ItemOaiRecord implements OaiRecordInterface
{
    public function __construct(private Item $item) {}

    public function getOaiIdentifier(): string
    {
        return 'oai:example.org:' . $this->item->getId();
    }

    public function getOaiDatestamp(): \DateTimeInterface
    {
        return $this->item->getModified();
    }

    public function getOaiSets(): array
    {
        return [$this->item->getType()]; // e.g. ['photographs']
    }

    public function isOaiDeleted(): bool
    {
        return $this->item->isDeleted();
    }

    public function getOaiDublinCore(): array
    {
        return [
            'identifier'  => $this->getOaiIdentifier(),
            'title'       => $this->item->getTitle(),
            'description' => $this->item->getDescription(),
            'creator'     => $this->item->getCreator(),
            'date'        => $this->item->getDate(),
            'type'        => $this->item->getType(),
            'rights'      => $this->item->getRightsUri(),
        ];
    }
}
```

### Endpoint

Your OAI-PMH endpoint is now live at `/oai`:

```
GET /oai?verb=Identify
GET /oai?verb=ListMetadataFormats
GET /oai?verb=ListSets
GET /oai?verb=ListRecords&metadataPrefix=oai_dc
GET /oai?verb=ListRecords&metadataPrefix=oai_dc&set=photographs&from=2020-01-01
GET /oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=oai:example.org:42
```

### Configuration

```yaml
# config/packages/survos_oai.yaml
survos_oai:
    page_size: 100   # records per resumption page (default: 100)
```

### Resumption tokens

Large result sets are automatically paginated using stateless, base64-encoded JSON resumption tokens. No database or cache storage is required. Tokens expire after 1 hour.

---

## Part 2: Harvesting OAI-PMH

```bash
# Harvest all records from an endpoint → NDJSON
bin/console oai:harvest https://oai.digitalcommonwealth.org/catalog/oai records.jsonl

# Harvest specific set, date range, identifiers only
bin/console oai:harvest https://oai.example.org/oai out.jsonl \
    --prefix=oai_dc \
    --set=photographs \
    --from=2020-01-01 \
    --until=2023-12-31

# Stream to stdout (pipe into jq, wc, etc.)
bin/console oai:harvest https://oai.example.org/oai - | wc -l

# Headers only (faster — no metadata payload)
bin/console oai:harvest https://oai.example.org/oai ids.jsonl --identifiers-only
```

Each NDJSON line has this shape:

```json
{
  "verb": "ListRecords",
  "identifier": "oai:example.org:12345",
  "datestamp": "2023-04-01",
  "setSpec": ["photographs"],
  "deleted": false,
  "metadata": {
    "title": "Portrait of a Woman",
    "creator": "Smith, Jane",
    "date": "1895",
    "type": "photograph",
    "identifier": ["oai:example.org:12345", "https://example.org/items/12345"]
  },
  "_raw": "<metadata>…</metadata>"
}
```

Pipe output through the `import-bundle` pipeline:

```bash
bin/console oai:harvest https://oai.example.org/oai - \
    | bin/console import:convert - normalized.jsonl
```

---

## Protocol comparison

| Protocol | Direction | Format | Use case |
|---|---|---|---|
| **OAI-PMH 2.0** | Pull (harvest) | XML/DC | Standard metadata exchange; required by DPLA, Europeana, Digital Commonwealth |
| **IIIF Presentation** | Pull (viewer) | JSON-LD | Rich image/AV viewing; handled by `survos/iiif-bundle` |
| **ResourceSync** | Pull (sync) | XML Sitemap | See below |

---

## ResourceSync (future)

**ResourceSync** (ANSI/NISO Z39.99) is the OAI-PMH successor developed by the same OAI community. Where OAI-PMH is request/response (a harvester asks "give me records changed since X"), ResourceSync is sitemap-based (a publisher announces exactly what changed and when).

Key concepts:

- **Resource List** — sitemap of all resources with checksums
- **Resource Dump** — ZIP of all resources for bulk sync
- **Change List** — incremental list of creates/updates/deletes since a baseline
- **Capability List** — discovery document at `/.well-known/resourcesync`

ResourceSync is better suited for large collections (millions of objects) and binary content (images, audio), while OAI-PMH remains the lingua franca for metadata-only exchange in the GLAM sector.

Planned additions to this bundle:

- `ResourceSyncController` — serve `/.well-known/resourcesync`, capability list, resource list, change list
- `ResourceSyncHarvestCommand` — `oai:sync` command to consume a ResourceSync endpoint
- `ChangeListBuilder` — track and emit change events for Doctrine entities

---

## Integration with Omeka-S

Omeka-S has a first-party `OaiPmhRepository` module. If you are running Omeka-S alongside this application:

- **Harvesting from Omeka-S**: use `oai:harvest` — Omeka-S exposes `/oai` by default
- **Exposing to aggregators**: implement `OaiDataProviderInterface` against your own `Item`/`Value` entities (see `zm` project); the `dcterms:*` property values map directly to Dublin Core elements

---

## Testing your endpoint

Use the [OAI-PMH validator](http://validator.oaipmh.com/) or the re.cs.uct.ac.za repository explorer.

```bash
# Quick smoke test
curl "https://yoursite.example.org/oai?verb=Identify"
curl "https://yoursite.example.org/oai?verb=ListMetadataFormats"
curl "https://yoursite.example.org/oai?verb=ListRecords&metadataPrefix=oai_dc"
```
