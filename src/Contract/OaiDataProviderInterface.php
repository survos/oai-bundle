<?php

declare(strict_types=1);

namespace Survos\OaiBundle\Contract;

/**
 * Implement this interface in your application to expose data via OAI-PMH.
 *
 * Register your implementation as a Symfony service tagged with
 * `survos_oai.data_provider`, or configure it explicitly:
 *
 *   survos_oai:
 *       data_provider: App\Service\MyOaiProvider
 *
 * The bundle wires this interface to your implementation automatically when
 * exactly one tagged service exists.
 */
interface OaiDataProviderInterface
{
    /**
     * Human-readable name of this repository (returned in Identify response).
     */
    public function getRepositoryName(): string;

    /**
     * Base URL of this OAI-PMH endpoint (returned in Identify response).
     * Typically the full URL to the /oai route.
     */
    public function getBaseUrl(): string;

    /**
     * Admin email address(es).
     *
     * @return string[]
     */
    public function getAdminEmails(): array;

    /**
     * The earliest datestamp of any record in the repository.
     */
    public function getEarliestDatestamp(): \DateTimeInterface;

    /**
     * Deleted record support level: 'no', 'transient', or 'persistent'.
     */
    public function getDeletedRecordPolicy(): string;

    /**
     * Available sets. Return an empty array if sets are not supported.
     *
     * Each entry: ['spec' => string, 'name' => string, 'description' => string|null]
     *
     * @return array<int, array{spec: string, name: string, description: string|null}>
     */
    public function getSets(): array;

    /**
     * Fetch a single record by OAI identifier, or null if not found.
     */
    public function getRecord(string $identifier): ?OaiRecordInterface;

    /**
     * Fetch records for ListRecords / ListIdentifiers.
     *
     * @return iterable<OaiRecordInterface>
     */
    public function getRecords(
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        ?string $set,
        int $offset,
        int $limit,
    ): iterable;

    /**
     * Total count for a given filter (used to build resumption tokens).
     * Return null if counting is too expensive; resumption will still work
     * but completeListSize will be omitted from the token.
     */
    public function countRecords(
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        ?string $set,
    ): ?int;
}
