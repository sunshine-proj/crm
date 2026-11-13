<?php

namespace App\Service\Dms;

/** The actor may not perform the operation on the document (the API answers 403). */
final class DocumentAccessDenied extends \RuntimeException
{
}
