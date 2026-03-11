<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('mosquitto')]
class mosquitto extends Extension
{
    public function patchBeforeMake(): bool
    {
        $patched = parent::patchBeforeMake();

        // Windows 平台的特殊处理
        if (PHP_OS_FAMILY === 'Windows') {
            // 修复可能的编译警告
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\php_mosquitto.h', '/^#warning.*/m', '');
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\mosquitto_private.h', '/^#warning.*/m', '');
            return true;
        }

        // Unix/Linux 平台可能需要的预处理
        // 确保头文件路径正确
        if (!file_exists(BUILD_INCLUDE_PATH . '/mosquitto.h')) {
            copy(
                BUILD_ROOT_PATH . '/include/mosquitto.h',
                BUILD_INCLUDE_PATH . '/mosquitto.h'
            );
        }

        return $patched;
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        return '--with-mosquitto' . ($shared ? '=shared' : '') .
            ' --with-libmosquitto-dir=' . BUILD_ROOT_PATH;
    }

    public function getWindowsConfigureArg($shared = false): string
    {
        return '--with-mosquitto';
    }
}
