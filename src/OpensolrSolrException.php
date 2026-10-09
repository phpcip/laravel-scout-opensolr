<?php

namespace Opensolr\ScoutOpensolr;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;

/**
 * Solr refused a request on an index (HTTP 4xx): the message carries Solr's own reason and
 * the index name. Still a Guzzle ClientException, so code that caught that before still does.
 */
class OpensolrSolrException extends ClientException implements OpensolrExceptionInterface
{
    /** The same failure with Solr's reason in the message, as the package's own exception type. */
    public static function fromGuzzle(BadResponseException $e, string $index, string $what): BadResponseException
    {
        $response = $e->getResponse();
        $body = (string) $response->getBody();
        $json = json_decode($body, true);
        $why = is_array($json) ? (string) ($json['error']['msg'] ?? '') : '';
        if ($why === '') {
            $why = trim(mb_substr(trim(strip_tags($body)), 0, 300)) ?: $response->getReasonPhrase();
        }
        $message = "Solr refused the {$what} on index {$index} (HTTP {$response->getStatusCode()}): {$why}";
        $class = $response->getStatusCode() >= 500 ? OpensolrSolrServerException::class : self::class;

        return new $class($message, $e->getRequest(), $response, $e, $e->getHandlerContext());
    }
}
