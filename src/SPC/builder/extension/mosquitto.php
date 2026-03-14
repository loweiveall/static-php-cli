<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('mosquitto')]
class mosquitto extends Extension
{
    public function patchBeforeConfigure(): bool
    {
        // 在 configure 之前执行，确保头文件路径正确
        $this->setupHeaderFiles();
        return true;
    }

    public function patchBeforeMake(): bool
    {
        $patched = parent::patchBeforeMake();

        // Windows 平台的特殊处理
        if (PHP_OS_FAMILY === 'Windows') {
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\php_mosquitto.h', '/^#warning.*/m', '');
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\mosquitto_private.h', '/^#warning.*/m', '');
            return true;
        }

        return $patched;
    }

    /**
     * 设置头文件，确保 mosquitto 头文件能被找到
     */
    protected function setupHeaderFiles(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $buildroot_include = $work_dir . '/buildroot/include';

        // 1. 创建 mosquitto 子目录
        if (!is_dir('/usr/local/include/mosquitto')) {
            mkdir('/usr/local/include/mosquitto', 0755, true);
        }

        // 2. 复制所有 mosquitto 头文件到 /usr/local/include/mosquitto/
        $mosquitto_headers = [
            'mosquitto.h',
            'mosquitto_broker.h',
            'mosquitto_plugin.h',
            'mosquittopp.h',
            'mqtt_protocol.h',
        ];

        foreach ($mosquitto_headers as $header) {
            $source = $buildroot_include . '/' . $header;
            $target = '/usr/local/include/' . $header;
            $target_subdir = '/usr/local/include/mosquitto/' . $header;

            if (file_exists($source)) {
                // 复制到根目录
                copy($source, $target);
                // 复制到 mosquitto 子目录
                copy($source, $target_subdir);
                echo "[I] Copied {$header} to /usr/local/include/ and /usr/local/include/mosquitto/\n";
            }
        }

        // 3. 复制 cJSON 头文件
        if (file_exists($buildroot_include . '/cJSON.h')) {
            copy($buildroot_include . '/cJSON.h', '/usr/local/include/cJSON.h');
        }

        // 4. 设置编译环境变量
        putenv("CFLAGS=-I/usr/local/include -I{$buildroot_include}");
        putenv("CPPFLAGS=-I/usr/local/include -I{$buildroot_include}");

        echo "[I] Set CFLAGS/CPPFLAGS to include /usr/local/include and {$buildroot_include}\n";
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        $work_dir = $this->builder->getOption('work_dir');

        // 设置环境变量
        putenv("PKG_CONFIG_PATH={$work_dir}/buildroot/lib/pkgconfig");
        putenv("CFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include");
        putenv("CPPFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include");
        putenv("LDFLAGS=-L{$work_dir}/buildroot/lib");

        return '--with-mosquitto' . ($shared ? '=shared' : '');
    }

    public function getWindowsConfigureArg($shared = false): string
    {
        return '--with-mosquitto';
    }
}
