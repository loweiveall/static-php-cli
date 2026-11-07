<?php

declare(strict_types=1);

namespace Package\Library;

use StaticPHP\Attribute\Package\BuildFor;
use StaticPHP\Attribute\Package\Library;
use StaticPHP\Package\LibraryPackage;
use StaticPHP\Runtime\Executor\UnixCMakeExecutor;
use StaticPHP\Util\FileSystem;

/**
 * Eclipse Mosquitto MQTT client library.
 *
 * The config pins the 2.0.x series: 2.1.x requires an external cJSON package that
 * StaticPHP does not ship, and 2.0.x is enough for the PHP `mosquitto` extension.
 */
#[Library('libmosquitto')]
class libmosquitto
{
    #[BuildFor('Darwin')]
    #[BuildFor('Linux')]
    public function buildUnix(LibraryPackage $lib): void
    {
        UnixCMakeExecutor::create($lib)
            ->addConfigureArgs(
                '-DWITH_STATIC_LIBRARIES=ON',
                '-DWITH_BROKER=OFF',
                '-DWITH_CLIENTS=OFF',
                '-DWITH_APPS=OFF',
                '-DWITH_PLUGINS=OFF',
                '-DWITH_LIB_CPP=OFF',
                '-DWITH_CJSON=OFF',
                '-DWITH_SRV=OFF',
                '-DDOCUMENTATION=OFF',
            )
            ->build();

        $lib_dir = $lib->getLibDir();

        // Upstream always builds a shared libmosquitto as well. Only the static archive
        // may stay in buildroot, otherwise `-lmosquitto` resolves to the shared object.
        foreach (FileSystem::scanDirFiles($lib_dir, false, true) ?: [] as $filename) {
            if (str_starts_with($filename, 'libmosquitto.') && !str_ends_with($filename, '.a')) {
                unlink("{$lib_dir}/{$filename}");
            }
        }

        // The static archive is installed as libmosquitto_static.a, while both the
        // pkg-config file and the extension's config.m4 link against `-lmosquitto`.
        if (file_exists("{$lib_dir}/libmosquitto_static.a")) {
            rename("{$lib_dir}/libmosquitto_static.a", "{$lib_dir}/libmosquitto.a");
        }

        $lib->patchPkgconfPrefix(['libmosquitto.pc']);
    }
}
