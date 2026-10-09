<?php

namespace Opensolr\ScoutOpensolr;

use GuzzleHttp\Exception\ServerException;

/**
 * Solr failed on an index (HTTP 5xx): the message carries Solr's own reason and the index
 * name. Still a Guzzle ServerException, so code that caught that before still does.
 */
class OpensolrSolrServerException extends ServerException implements OpensolrExceptionInterface
{
}
