<?php

namespace App\Service\Dms;

/** The configured virus scanner could not give a verdict; uploads are refused (503) rather than stored unscanned. */
final class ScannerUnavailable extends \RuntimeException
{
}
