<?php

/**
 * Stub for Barnetik\Tbai\Fingerprint\Vendor
 * Required because barnetik dev-main doesn't include this class
 */

namespace Barnetik\Tbai\Fingerprint;

class Vendor
{
    public string $license;
    public string $nif;
    public string $appName;
    public string $appVersion;

    public function __construct(string $license, string $nif, string $appName, string $appVersion)
    {
        $this->license = $license;
        $this->nif = $nif;
        $this->appName = $appName;
        $this->appVersion = $appVersion;
    }
}
