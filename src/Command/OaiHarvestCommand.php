<?php

declare(strict_types=1);

namespace Survos\OaiBundle\Command;

use Phpoaipmh\Endpoint;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Harvest an OAI-PMH 2.0 endpoint and emit NDJSON (one record per line) to
 * stdout or a file.  Plug the output directly into the import-bundle pipeline:
 *
 *   bin/console oai:harvest https://oai.example.org/oai records.jsonl
 *   bin/console import:convert records.jsonl normalized.jsonl
 */
#[AsCommand('oai:harvest', 'Harvest an OAI-PMH 2.0 endpoint to NDJSON')]
final class OaiHarvestCommand
{
    public function __invoke(
        SymfonyStyle $io,

        #[Argument('OAI-PMH base URL')]
        string $baseUrl,

        #[Argument('Output file path (use "-" for stdout)')]
        string $out = '-',

        #[Option('metadataPrefix (oai_dc, edm, marc21, …)')]
        string $prefix = 'oai_dc',

        #[Option('setSpec to filter')]
        ?string $set = null,

        #[Option('From date YYYY-MM-DD')]
        ?string $from = null,

        #[Option('Until date YYYY-MM-DD')]
        ?string $until = null,

        #[Option('Emit headers only (ListIdentifiers verb)')]
        bool $identifiersOnly = false,

        #[Option('Stop after this many records (0 = unlimited)')]
        int $max = 0,

        #[Option('Records per page (resumption page size hint)')]
        int $pageSize = 100,
    ): int {
        $io->title(sprintf('OAI-PMH harvest: %s', $baseUrl));

        $endpoint = Endpoint::build($baseUrl);
        $fromDt   = $from  ? new \DateTimeImmutable($from)  : null;
        $untilDt  = $until ? new \DateTimeImmutable($until) : null;

        $writer = $out === '-' ? null : new \SplFileObject($out, 'w');
        $count  = 0;

        if ($identifiersOnly) {
            $iter = $endpoint->listIdentifiers($prefix, $fromDt, $untilDt, $set);
            foreach ($iter as $header) {
                $this->emit([
                    'verb'       => 'ListIdentifiers',
                    'identifier' => (string) ($header->identifier ?? ''),
                    'datestamp'  => (string) ($header->datestamp  ?? ''),
                    'setSpec'    => $this->toStringArray($header->setSpec ?? null),
                ], $writer, $io);

                if ($max > 0 && ++$count >= $max) {
                    break;
                }
            }
        } else {
            $iter = $endpoint->listRecords($prefix, $fromDt, $untilDt, $set);
            foreach ($iter as $rec) {
                $header     = $rec->header ?? null;
                [$dc, $raw] = $this->parseOaiDc($rec);

                $this->emit([
                    'verb'       => 'ListRecords',
                    'identifier' => $header ? (string) $header->identifier : '',
                    'datestamp'  => $header ? (string) $header->datestamp  : '',
                    'setSpec'    => $header ? $this->toStringArray($header->setSpec ?? null) : [],
                    'deleted'    => isset($header['status']) && (string) $header['status'] === 'deleted',
                    'metadata'   => $dc,
                    '_raw'       => $raw,
                ], $writer, $io);

                if ($max > 0 && ++$count >= $max) {
                    break;
                }
            }
        }

        $io->success(sprintf('Harvested %d record(s) → %s', $count, $out));
        return 0;
    }

    private function emit(array $row, ?\SplFileObject $file, SymfonyStyle $io): void
    {
        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if ($file !== null) {
            $file->fwrite($line);
        } else {
            $io->write($line, false);
        }
    }

    /**
     * Extract Dublin Core from <metadata><oai_dc:dc>…</oai_dc:dc>.
     * Returns [array|null $dc, string|null $rawXml].
     *
     * @return array{0: array<string,string|string[]>|null, 1: string|null}
     */
    private function parseOaiDc(\SimpleXMLElement $rec): array
    {
        $rawXml = isset($rec->metadata) ? $rec->metadata->asXML() ?: null : null;

        if (!isset($rec->metadata)) {
            return [null, $rawXml];
        }

        $ns      = $rec->metadata->getNamespaces(true);
        $oaiDcNs = $ns['oai_dc'] ?? null;
        $dcNs    = $ns['dc']     ?? null;

        if (!$oaiDcNs || !$dcNs) {
            return [null, $rawXml];
        }

        $dcEl = $rec->metadata->children($oaiDcNs)->dc ?? null;
        if (!$dcEl) {
            return [null, $rawXml];
        }

        $dc = [];
        foreach ($dcEl->children($dcNs) as $name => $value) {
            $dc[(string) $name][] = trim((string) $value);
        }

        // Collapse single-value arrays to plain strings
        foreach ($dc as $k => $vals) {
            if (count($vals) === 1) {
                $dc[$k] = $vals[0];
            }
        }

        return [$dc, $rawXml];
    }

    /** @return string[] */
    private function toStringArray(mixed $nodes): array
    {
        if ($nodes === null) {
            return [];
        }
        return array_map('strval', iterator_to_array($nodes));
    }
}
