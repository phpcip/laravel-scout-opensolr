<?php

namespace Opensolr\ScoutOpensolr;

use RuntimeException;

/**
 * An Opensolr API call failed. A RuntimeException, as everything the package threw before.
 */
class OpensolrException extends RuntimeException implements OpensolrExceptionInterface
{
}
