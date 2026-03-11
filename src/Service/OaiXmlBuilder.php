<?php

declare(strict_types=1);

namespace Survos\OaiBundle\Service;

use Survos\OaiBundle\Contract\OaiDataProviderInterface;
use Survos\OaiBundle\Contract\OaiRecordInterface;
use Survos\OaiBundle\Model\ResumptionToken;

/**
 * Builds OAI-PMH 2.0 compliant XML responses using XMLWriter (streaming, no DOM).
 */
final class OaiXmlBuilder
{
    private const OAI_NS        = 'http://www.openarchives.org/OAI/2.0/';
    private const OAI_SCHEMA    = 'http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd';
    private const OAI_DC_NS     = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
    private const OAI_DC_SCHEMA = 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd';
    private const DC_NS         = 'http://purl.org/dc/elements/1.1/';
    private const XSI_NS        = 'http://www.w3.org/2001/XMLSchema-instance';

    /** Valid bare Dublin Core element names. */
    private const DC_ELEMENTS = [
        'title', 'creator', 'subject', 'description', 'publisher',
        'contributor', 'date', 'type', 'format', 'identifier',
        'source', 'language', 'relation', 'coverage', 'rights',
    ];

    public function __construct(
        private readonly OaiDataProviderInterface $provider,
        private readonly int $pageSize = 100,
    ) {}

    // -------------------------------------------------------------------------
    // Public verb methods
    // -------------------------------------------------------------------------

    public function identify(string $verb, \DateTimeImmutable $responseDate): string
    {
        $w = $this->beginResponse($verb, $responseDate);
        $w->startElement('Identify');
        $w->writeElement('repositoryName', $this->provider->getRepositoryName());
        $w->writeElement('baseURL', $this->provider->getBaseUrl());
        $w->writeElement('protocolVersion', '2.0');
        foreach ($this->provider->getAdminEmails() as $email) {
            $w->writeElement('adminEmail', $email);
        }
        $w->writeElement('earliestDatestamp', $this->formatDate($this->provider->getEarliestDatestamp()));
        $w->writeElement('deletedRecord', $this->provider->getDeletedRecordPolicy());
        $w->writeElement('granularity', 'YYYY-MM-DDThh:mm:ssZ');
        $w->endElement(); // Identify
        return $this->endResponse($w);
    }

    public function listMetadataFormats(string $verb, \DateTimeImmutable $responseDate, ?string $identifier): string
    {
        $w = $this->beginResponse($verb, $responseDate);

        if ($identifier !== null) {
            $record = $this->provider->getRecord($identifier);
            if ($record === null) {
                return $this->errorResponse($verb, $responseDate, 'idDoesNotExist', "No record with identifier '{$identifier}'.");
            }
        }

        $w->startElement('ListMetadataFormats');
        $w->startElement('metadataFormat');
        $w->writeElement('metadataPrefix', 'oai_dc');
        $w->writeElement('schema', self::OAI_DC_SCHEMA);
        $w->writeElement('metadataNamespace', self::OAI_DC_NS);
        $w->endElement(); // metadataFormat
        $w->endElement(); // ListMetadataFormats
        return $this->endResponse($w);
    }

    public function listSets(string $verb, \DateTimeImmutable $responseDate): string
    {
        $sets = $this->provider->getSets();
        if (empty($sets)) {
            return $this->errorResponse($verb, $responseDate, 'noSetHierarchy', 'This repository does not support sets.');
        }

        $w = $this->beginResponse($verb, $responseDate);
        $w->startElement('ListSets');
        foreach ($sets as $set) {
            $w->startElement('set');
            $w->writeElement('setSpec', $set['spec']);
            $w->writeElement('setName', $set['name']);
            if ($set['description'] !== null) {
                $w->startElement('setDescription');
                $w->writeRaw($set['description']);
                $w->endElement();
            }
            $w->endElement(); // set
        }
        $w->endElement(); // ListSets
        return $this->endResponse($w);
    }

    public function getRecord(string $verb, \DateTimeImmutable $responseDate, string $identifier, string $metadataPrefix): string
    {
        if ($metadataPrefix !== 'oai_dc') {
            return $this->errorResponse($verb, $responseDate, 'cannotDisseminateFormat', "Metadata prefix '{$metadataPrefix}' is not supported.");
        }

        $record = $this->provider->getRecord($identifier);
        if ($record === null) {
            return $this->errorResponse($verb, $responseDate, 'idDoesNotExist', "No record with identifier '{$identifier}'.");
        }

        $w = $this->beginResponse($verb, $responseDate);
        $w->startElement('GetRecord');
        $this->writeRecord($w, $record, includeMetadata: true);
        $w->endElement(); // GetRecord
        return $this->endResponse($w);
    }

    public function listIdentifiers(
        string $verb,
        \DateTimeImmutable $responseDate,
        string $metadataPrefix,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        ?string $set,
        ?string $resumptionTokenRaw,
    ): string {
        return $this->listRecordsOrIdentifiers(
            verb: $verb,
            responseDate: $responseDate,
            metadataPrefix: $metadataPrefix,
            from: $from,
            until: $until,
            set: $set,
            resumptionTokenRaw: $resumptionTokenRaw,
            includeMetadata: false,
        );
    }

    public function listRecords(
        string $verb,
        \DateTimeImmutable $responseDate,
        string $metadataPrefix,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        ?string $set,
        ?string $resumptionTokenRaw,
    ): string {
        return $this->listRecordsOrIdentifiers(
            verb: $verb,
            responseDate: $responseDate,
            metadataPrefix: $metadataPrefix,
            from: $from,
            until: $until,
            set: $set,
            resumptionTokenRaw: $resumptionTokenRaw,
            includeMetadata: true,
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function listRecordsOrIdentifiers(
        string $verb,
        \DateTimeImmutable $responseDate,
        string $metadataPrefix,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        ?string $set,
        ?string $resumptionTokenRaw,
        bool $includeMetadata,
    ): string {
        if ($metadataPrefix !== 'oai_dc') {
            return $this->errorResponse($verb, $responseDate, 'cannotDisseminateFormat', "Metadata prefix '{$metadataPrefix}' is not supported.");
        }

        $offset = 0;
        $tokenExpiresAt = new \DateTimeImmutable('+1 hour');

        // Decode resumption token if present
        if ($resumptionTokenRaw !== null) {
            try {
                $token = ResumptionToken::decode($resumptionTokenRaw);
            } catch (\Throwable) {
                return $this->errorResponse($verb, $responseDate, 'badResumptionToken', 'The resumption token is invalid or malformed.');
            }
            if ($token->isExpired()) {
                return $this->errorResponse($verb, $responseDate, 'badResumptionToken', 'The resumption token has expired.');
            }
            $offset         = $token->offset;
            $from           = $token->from  ? new \DateTimeImmutable($token->from)  : null;
            $until          = $token->until ? new \DateTimeImmutable($token->until) : null;
            $set            = $token->set;
            $metadataPrefix = $token->metadataPrefix;
        }

        $records = $this->provider->getRecords($from, $until, $set, $offset, $this->pageSize);
        $items   = iterator_to_array($records, preserve_keys: false);

        if (empty($items) && $offset === 0) {
            return $this->errorResponse($verb, $responseDate, 'noRecordsMatch', 'No records match the given criteria.');
        }

        $total = $this->provider->countRecords($from, $until, $set);

        $w = $this->beginResponse($verb, $responseDate);
        $w->startElement($verb); // ListRecords or ListIdentifiers

        foreach ($items as $record) {
            $this->writeRecord($w, $record, $includeMetadata);
        }

        // Emit resumption token if there may be more pages
        if (count($items) === $this->pageSize) {
            $nextToken = new ResumptionToken(
                verb:             $verb,
                metadataPrefix:   $metadataPrefix,
                offset:           $offset + $this->pageSize,
                pageSize:         $this->pageSize,
                set:              $set,
                from:             $from?->format('Y-m-d'),
                until:            $until?->format('Y-m-d'),
                completeListSize: $total,
                expirationDate:   $tokenExpiresAt,
            );

            $w->startElement('resumptionToken');
            $w->writeAttribute('expirationDate', $tokenExpiresAt->format(\DateTimeInterface::ATOM));
            if ($total !== null) {
                $w->writeAttribute('completeListSize', (string) $total);
            }
            $w->writeAttribute('cursor', (string) $offset);
            $w->text(ResumptionToken::encode($nextToken));
            $w->endElement();
        } elseif ($resumptionTokenRaw !== null) {
            // Final page after resumption: emit empty token to signal completion
            $w->startElement('resumptionToken');
            $w->writeAttribute('cursor', (string) $offset);
            if ($total !== null) {
                $w->writeAttribute('completeListSize', (string) $total);
            }
            $w->text('');
            $w->endElement();
        }

        $w->endElement(); // verb element
        return $this->endResponse($w);
    }

    private function writeRecord(\XMLWriter $w, OaiRecordInterface $record, bool $includeMetadata): void
    {
        $w->startElement('record');

        // Header
        $w->startElement('header');
        if ($record->isOaiDeleted()) {
            $w->writeAttribute('status', 'deleted');
        }
        $w->writeElement('identifier', $record->getOaiIdentifier());
        $w->writeElement('datestamp', $this->formatDate($record->getOaiDatestamp()));
        foreach ($record->getOaiSets() as $setSpec) {
            $w->writeElement('setSpec', $setSpec);
        }
        $w->endElement(); // header

        // Metadata (omitted for deleted records and ListIdentifiers)
        if ($includeMetadata && !$record->isOaiDeleted()) {
            $w->startElement('metadata');
            $w->startElementNS('oai_dc', 'dc', self::OAI_DC_NS);
            $w->writeAttributeNS('xmlns', 'dc', null, self::DC_NS);
            $w->writeAttributeNS('xmlns', 'xsi', null, self::XSI_NS);
            $w->writeAttributeNS('xsi', 'schemaLocation', null,
                self::OAI_DC_NS . ' ' . self::OAI_DC_SCHEMA);

            foreach ($record->getOaiDublinCore() as $element => $value) {
                if (!in_array($element, self::DC_ELEMENTS, true)) {
                    continue;
                }
                $values = is_array($value) ? $value : [$value];
                foreach ($values as $v) {
                    $w->startElementNS('dc', $element, null);
                    $w->text((string) $v);
                    $w->endElement();
                }
            }

            $w->endElement(); // oai_dc:dc
            $w->endElement(); // metadata
        }

        $w->endElement(); // record
    }

    private function beginResponse(string $verb, \DateTimeImmutable $responseDate): \XMLWriter
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElementNS(null, 'OAI-PMH', self::OAI_NS);
        $w->writeAttributeNS('xmlns', 'xsi', null, self::XSI_NS);
        $w->writeAttributeNS('xsi', 'schemaLocation', null,
            self::OAI_NS . ' ' . self::OAI_SCHEMA);
        $w->writeElement('responseDate', $responseDate->format(\DateTimeInterface::ATOM));
        $w->startElement('request');
        $w->writeAttribute('verb', $verb);
        $w->text($this->provider->getBaseUrl());
        $w->endElement();
        return $w;
    }

    private function endResponse(\XMLWriter $w): string
    {
        $w->endElement(); // OAI-PMH
        $w->endDocument();
        return $w->outputMemory();
    }

    public function errorResponse(string $verb, \DateTimeImmutable $responseDate, string $code, string $message): string
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElementNS(null, 'OAI-PMH', self::OAI_NS);
        $w->writeAttributeNS('xmlns', 'xsi', null, self::XSI_NS);
        $w->writeAttributeNS('xsi', 'schemaLocation', null,
            self::OAI_NS . ' ' . self::OAI_SCHEMA);
        $w->writeElement('responseDate', $responseDate->format(\DateTimeInterface::ATOM));
        $w->startElement('request');
        $w->text($this->provider->getBaseUrl());
        $w->endElement();
        $w->startElement('error');
        $w->writeAttribute('code', $code);
        $w->text($message);
        $w->endElement();
        $w->endElement(); // OAI-PMH
        $w->endDocument();
        return $w->outputMemory();
    }

    private function formatDate(\DateTimeInterface $dt): string
    {
        return \DateTimeImmutable::createFromInterface($dt)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
