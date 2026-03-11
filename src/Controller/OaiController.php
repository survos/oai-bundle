<?php

declare(strict_types=1);

namespace Survos\OaiBundle\Controller;

use Survos\OaiBundle\Service\OaiXmlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAI-PMH 2.0 endpoint.
 *
 * Mount at /oai (default) by importing the bundle's routing:
 *
 *   # config/routes.yaml
 *   survos_oai:
 *       resource: '@SurvosOaiBundle/config/routes.yaml'
 *
 * Or override the path in your own routing config.
 */
final class OaiController
{
    public function __construct(
        private readonly OaiXmlBuilder $builder,
    ) {}

    #[Route('/oai', name: 'survos_oai', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $verb = trim((string) ($request->query->get('verb') ?? $request->request->get('verb') ?? ''));

        $xml = match ($verb) {
            'Identify'            => $this->builder->identify($verb, $now),
            'ListMetadataFormats' => $this->builder->listMetadataFormats(
                $verb,
                $now,
                $request->get('identifier'),
            ),
            'ListSets'            => $this->builder->listSets($verb, $now),
            'GetRecord'           => $this->handleGetRecord($verb, $now, $request),
            'ListIdentifiers'     => $this->handleList($verb, $now, $request),
            'ListRecords'         => $this->handleList($verb, $now, $request),
            default               => $this->builder->errorResponse(
                $verb ?: 'unknown',
                $now,
                'badVerb',
                $verb === ''
                    ? 'No verb supplied in request.'
                    : "'{$verb}' is not a legal OAI-PMH verb.",
            ),
        };

        return new Response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    private function handleGetRecord(string $verb, \DateTimeImmutable $now, Request $request): string
    {
        $identifier     = trim((string) ($request->get('identifier') ?? ''));
        $metadataPrefix = trim((string) ($request->get('metadataPrefix') ?? ''));

        if ($identifier === '') {
            return $this->builder->errorResponse($verb, $now, 'badArgument', 'Missing required argument: identifier.');
        }
        if ($metadataPrefix === '') {
            return $this->builder->errorResponse($verb, $now, 'badArgument', 'Missing required argument: metadataPrefix.');
        }

        return $this->builder->getRecord($verb, $now, $identifier, $metadataPrefix);
    }

    private function handleList(string $verb, \DateTimeImmutable $now, Request $request): string
    {
        $resumptionToken = $request->get('resumptionToken');

        // When a resumptionToken is supplied, all other arguments are ignored per spec
        if ($resumptionToken !== null) {
            $metadataPrefix = 'oai_dc'; // recovered from token
            $from = $until = $set = null;
        } else {
            $metadataPrefix = trim((string) ($request->get('metadataPrefix') ?? ''));
            if ($metadataPrefix === '') {
                return $this->builder->errorResponse($verb, $now, 'badArgument', 'Missing required argument: metadataPrefix.');
            }

            $from  = $this->parseDate($request->get('from'));
            $until = $this->parseDate($request->get('until'));
            $set   = $request->get('set') ? trim((string) $request->get('set')) : null;
        }

        return $verb === 'ListIdentifiers'
            ? $this->builder->listIdentifiers($verb, $now, $metadataPrefix, $from, $until, $set, $resumptionToken)
            : $this->builder->listRecords($verb, $now, $metadataPrefix, $from, $until, $set, $resumptionToken);
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        // Accept YYYY-MM-DD and YYYY-MM-DDThh:mm:ssZ
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
        return $dt ?: null;
    }
}
