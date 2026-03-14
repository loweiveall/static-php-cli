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
        $this->setupEnvironment();
        $this->patchMosquittoHeader(); // 新增：修补 mosquitto.h
        return true;
    }

    /**
     * 直接修补 mosquitto.h 文件
     */
    protected function patchMosquittoHeader(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        // 查找 mosquitto.h 的位置
        $possible_locations = [
            '/usr/local/include/mosquitto.h',
            $work_dir . '/buildroot/include/mosquitto.h',
            '/buildroot/include/mosquitto.h',
            '/app/buildroot/include/mosquitto.h',
        ];

        $header_file = null;
        foreach ($possible_locations as $loc) {
            if (file_exists($loc)) {
                $header_file = $loc;
                break;
            }
        }

        if (!$header_file) {
            echo "[W] Could not find mosquitto.h, skipping patch\n";
            return;
        }

        echo "[I] Patching mosquitto.h at: {$header_file}\n";

        $content = file_get_contents($header_file);
        $original = $content;

        // 1. 将 #include <mosquitto/libmosquitto.h> 改为 #include <libmosquitto.h>
        $content = str_replace(
            '#include <mosquitto/libmosquitto.h>',
            '#include <libmosquitto.h>',
            $content
        );

        // 2. 将 #include <mosquitto/mqtt_protocol.h> 改为 #include <mqtt_protocol.h>
        $content = str_replace(
            '#include <mosquitto/mqtt_protocol.h>',
            '#include <mqtt_protocol.h>',
            $content
        );

        // 3. 添加一个防止多次包含的守卫（如果需要）
        if (strpos($content, '#ifndef MOSQUITTO_H') === false) {
            $content = preg_replace(
                '/#ifndef MOSQUITTO_H/',
                '#ifndef MOSQUITTO_H_PATCHED',
                $content
            );
        }

        if ($content !== $original) {
            file_put_contents($header_file, $content);
            echo "[I] Successfully patched mosquitto.h\n";

            // 同时更新可能存在的副本
            $copy_locations = [
                '/usr/local/include/mosquitto.h',
                $work_dir . '/buildroot/include/mosquitto.h',
            ];

            foreach ($copy_locations as $loc) {
                if ($loc !== $header_file && file_exists($loc)) {
                    copy($header_file, $loc);
                    echo "[I] Updated copy at: {$loc}\n";
                }
            }
        }
    }

    protected function setupEnvironment(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        echo "[I] Setting up mosquitto build environment...\n";

        // 确保所有必要的目录存在
        $dirs = [
            '/usr/local/include',
            '/usr/local/include/mosquitto',
            '/usr/local/lib',
            '/usr/local/lib/pkgconfig',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // 复制所有头文件
        $this->copyHeaders();

        // 复制所有库文件
        $this->copyLibraries();

        // 设置环境变量
        putenv("PKG_CONFIG_PATH=/usr/local/lib/pkgconfig:{$work_dir}/buildroot/lib/pkgconfig:/buildroot/lib/pkgconfig");
        putenv("CFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include -I/buildroot/include");
        putenv("CPPFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include -I/buildroot/include");
        putenv("LDFLAGS=-L/usr/local/lib -L{$work_dir}/buildroot/lib -L/buildroot/lib");
    }

    protected function copyHeaders(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $source_dirs = [
            $work_dir . '/buildroot/include',
            '/buildroot/include',
        ];

        foreach ($source_dirs as $source_dir) {
            if (!is_dir($source_dir)) {
                continue;
            }

            $files = scandir($source_dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $source_path = $source_dir . '/' . $file;

                if (is_dir($source_path)) {
                    // 递归复制子目录
                    $this->copyDir($source_path, '/usr/local/include/' . $file);
                } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'h') {
                    // 复制到头文件目录
                    $target = '/usr/local/include/' . $file;
                    if (!file_exists($target)) {
                        copy($source_path, $target);
                    }

                    // 同时复制到 mosquitto 子目录
                    $target_sub = '/usr/local/include/mosquitto/' . $file;
                    if (!file_exists($target_sub)) {
                        copy($source_path, $target_sub);
                    }
                }
            }
        }

        echo "[I] Headers copied\n";
    }

    protected function copyDir(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $files = scandir($source);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $source_path = $source . '/' . $file;
            $dest_path = $dest . '/' . $file;

            if (is_dir($source_path)) {
                $this->copyDir($source_path, $dest_path);
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'h') {
                if (!file_exists($dest_path)) {
                    copy($source_path, $dest_path);
                }
            }
        }
    }

    protected function copyLibraries(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $source_dirs = [
            $work_dir . '/buildroot/lib',
            '/buildroot/lib',
        ];

        $libs_copied = false;

        foreach ($source_dirs as $source_dir) {
            if (!is_dir($source_dir)) {
                continue;
            }

            $files = scandir($source_dir);
            foreach ($files as $file) {
                if (substr($file, -2) === '.a') {
                    $source = $source_dir . '/' . $file;
                    $target = '/usr/local/lib/' . $file;

                    if (!file_exists($target)) {
                        copy($source, $target);
                        echo "[I] Copied library: {$file}\n";
                        $libs_copied = true;
                    }
                }
            }
        }

        // 特别处理 mosquitto 库
        $mosquitto_lib = null;
        foreach (['libmosquitto_static.a', 'libmosquitto.a'] as $lib) {
            if (file_exists('/usr/local/lib/' . $lib)) {
                $mosquitto_lib = '/usr/local/lib/' . $lib;
                break;
            }
        }

        if ($mosquitto_lib && !file_exists('/usr/local/lib/libmosquitto.a')) {
            symlink($mosquitto_lib, '/usr/local/lib/libmosquitto.a');
        }

        // 创建 pkg-config 文件
        $this->createPkgConfig();

        if ($libs_copied) {
            echo "[I] Libraries copied\n";
        }
    }

    protected function createPkgConfig(): void
    {
        $pc_content = <<<'EOF'
prefix=/usr/local
exec_prefix=${prefix}
libdir=${exec_prefix}/lib
includedir=${prefix}/include

Name: libmosquitto
Description: Eclipse Mosquitto MQTT library
Version: 2.0.18
Libs: -L${libdir} -lmosquitto
Cflags: -I${includedir}
Requires.private: openssl cjson
EOF;

        $pc_file = '/usr/local/lib/pkgconfig/libmosquitto.pc';
        if (!file_exists($pc_file)) {
            file_put_contents($pc_file, $pc_content);
            echo "[I] Created pkg-config file\n";
        }
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        $work_dir = $this->builder->getOption('work_dir');

        putenv("PKG_CONFIG_PATH=/usr/local/lib/pkgconfig:{$work_dir}/buildroot/lib/pkgconfig:/buildroot/lib/pkgconfig");
        putenv("CFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include -I/buildroot/include");
        putenv("CPPFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include -I/buildroot/include");
        putenv("LDFLAGS=-L/usr/local/lib -L{$work_dir}/buildroot/lib -L/buildroot/lib");

        return '--with-mosquitto' . ($shared ? '=shared' : '');
    }

    public function getWindowsConfigureArg($shared = false): string
    {
        return '--with-mosquitto';
    }
}
