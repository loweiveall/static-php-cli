<?php

declare(strict_types=1);

namespace Package\Artifact;

use StaticPHP\Attribute\Artifact\AfterSourceExtract;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Util\SourcePatcher;

class mosquitto
{
    #[AfterSourceExtract('ext-mosquitto')]
    #[PatchDescription('Patch mosquitto extension for POSIX strerror_r (musl/macOS returns int)')]
    public function patchPosixStrerrorR(string $target_path): void
    {
        SourcePatcher::patchFile('mosquitto_musl_strerror_r.patch', $target_path);
    }
}
