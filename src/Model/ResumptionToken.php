<?php

declare(strict_types=1);

namespace Survos\OaiBundle\Model;

/**
 * Represents the state encoded in an OAI-PMH resumption token.
 *
 * Tokens are serialised as base64-encoded JSON and are stateless —
 * no server-side storage required.
 */
final readonly class ResumptionToken
{
    public function __construct(
        public string $verb,
        public string $metadataPrefix,
        public int $offset,
        public int $pageSize,
        public ?string $set = null,
        public ?string $from = null,
        public ?string $until = null,
        public ?int $completeListSize = null,
        public ?\DateTimeImmutable $expirationDate = null,
    ) {}

    public static function encode(self $token): string
    {
        return base64_encode(json_encode([
            'verb'             => $token->verb,
            'metadataPrefix'   => $token->metadataPrefix,
            'offset'           => $token->offset,
            'pageSize'         => $token->pageSize,
            'set'              => $token->set,
            'from'             => $token->from,
            'until'            => $token->until,
            'completeListSize' => $token->completeListSize,
            'expirationDate'   => $token->expirationDate?->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR));
    }

    public static function decode(string $raw): self
    {
        $data = json_decode(base64_decode($raw, strict: true), associative: true, flags: JSON_THROW_ON_ERROR);

        return new self(
            verb:             $data['verb'],
            metadataPrefix:   $data['metadataPrefix'],
            offset:           (int) $data['offset'],
            pageSize:         (int) $data['pageSize'],
            set:              $data['set'] ?? null,
            from:             $data['from'] ?? null,
            until:            $data['until'] ?? null,
            completeListSize: isset($data['completeListSize']) ? (int) $data['completeListSize'] : null,
            expirationDate:   isset($data['expirationDate'])
                ? new \DateTimeImmutable($data['expirationDate'])
                : null,
        );
    }

    public function isExpired(): bool
    {
        return $this->expirationDate !== null
            && $this->expirationDate < new \DateTimeImmutable('now');
    }

    public function nextPage(): self
    {
        return new self(
            verb:             $this->verb,
            metadataPrefix:   $this->metadataPrefix,
            offset:           $this->offset + $this->pageSize,
            pageSize:         $this->pageSize,
            set:              $this->set,
            from:             $this->from,
            until:            $this->until,
            completeListSize: $this->completeListSize,
            expirationDate:   $this->expirationDate,
        );
    }
}
