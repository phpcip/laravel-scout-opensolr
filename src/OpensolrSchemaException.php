<?php

namespace Opensolr\ScoutOpensolr;

/**
 * The index cannot do what was asked: it is outside the vector regions, or its schema lacks
 * what the operation needs. getReport() is the full check (OpensolrClient::checkSchema()).
 */
class OpensolrSchemaException extends OpensolrException
{
    /** What each capability lets the package do, for the message. */
    public const CAPABILITY_TEXT = [
        'ingestion' => 'take documents through Data Ingestion',
        'vector_search' => 'be searched by meaning (vector or hybrid search)',
        'pdf_pages' => 'return PDF pages',
        'keyword_search' => 'be searched by keywords',
    ];

    public function __construct(
        protected string $index,
        protected string $capability,
        protected array $report,
    ) {
        $what = self::CAPABILITY_TEXT[$capability] ?? $capability;
        $problems = trim(implode(' ', (array) ($report['problems'] ?? []))) ?: 'The index check says it cannot.';
        $fix = trim((string) ($report['fix']['text'] ?? ''));
        parent::__construct(trim("Index '{$index}' cannot {$what}. {$problems} {$fix}")
            . " (OpensolrClient::checkSchema('{$index}') shows the details.)");
    }

    public function getIndex(): string
    {
        return $this->index;
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    public function getReport(): array
    {
        return $this->report;
    }
}
