<?php

declare(strict_types=1);

namespace Package\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Package\PhpExtensionPackage;
use StaticPHP\Util\FileSystem;

#[Extension('fastchart')]
class fastchart extends PhpExtensionPackage
{
    #[BeforeStage('php', [php::class, 'buildconfForUnix'], 'ext-fastchart')]
    #[PatchDescription('Keep the existing PHP_VERSION_ID instead of overwriting it with php-config output: in-tree static builds have no php-config, and clearing it breaks the version checks of extensions configured afterwards (e.g. mongodb).')]
    public function patchPhpVersionCheckForUnix(): void
    {
        $config = $this->getBuildDir() . '/config.m4';
        $needle = 'PHP_VERSION_ID=$($PHP_CONFIG --vernum)';
        // Already-patched sources are idempotent; newer upstream releases may drop this assignment.
        if (!str_contains(FileSystem::readFile($config), $needle)) {
            return;
        }
        FileSystem::replaceFileStr($config, $needle, 'PHP_VERSION_ID=${PHP_VERSION_ID:-$($PHP_CONFIG --vernum)}');
    }
}
