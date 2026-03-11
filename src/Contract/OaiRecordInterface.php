<?php

declare(strict_types=1);

namespace Survos\OaiBundle\Contract;

/**
 * A single record as returned by an OaiDataProviderInterface.
 *
 * Implementors return objects that satisfy this interface from
 * OaiDataProviderInterface::getRecords() and ::getRecord().
 */
interface OaiRecordInterface
{
    /**
     * The OAI-PMH unique identifier (URI format, e.g. oai:example.org:12345).
     */
    public function getOaiIdentifier(): string;

    /**
     * Last modification date of this record (used for selective harvesting).
     */
    public function getOaiDatestamp(): \DateTimeInterface;

    /**
     * Set memberships. Return an empty array if sets are not supported.
     *
     * @return string[]
     */
    public function getOaiSets(): array;

    /**
     * Whether this record has been deleted (tombstone).
     */
    public function isOaiDeleted(): bool;

    /**
     * Dublin Core metadata as a flat key → value(s) map.
     *
     * Keys are bare DC element names: title, creator, subject, description,
     * publisher, contributor, date, type, format, identifier, source,
     * language, relation, coverage, rights.
     *
     * Values may be a string (single value) or array of strings (multi-value).
     * Ignored when isOaiDeleted() returns true.
     *
     * @return array<string, string|string[]>
     */
    public function getOaiDublinCore(): array;
}
