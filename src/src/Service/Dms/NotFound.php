<?php

namespace App\Service\Dms;

/** A record referenced by a document operation does not exist or is not visible to the actor (the API answers 404). */
final class NotFound extends \RuntimeException
{
}
