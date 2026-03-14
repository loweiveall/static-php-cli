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
        // 设置头文件和库文件路径
        $this->setupEnvironment();
        return true;
    }

    /**
     * 设置编译环境
     */
    protected function setupEnvironment(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        echo "[I] Setting up mosquitto build environment...\n";

        // 1. 确保头文件在标准位置
        $this->ensureHeaders();

        // 2. 确保库文件在标准位置
        $this->ensureLibraries();

        // 3. 设置环境变量
        putenv("PKG_CONFIG_PATH=/usr/local/lib/pkgconfig:{$work_dir}/buildroot/lib/pkgconfig:/buildroot/lib/pkgconfig");
        putenv("CFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include -I/buildroot/include");
        putenv("CPPFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include -I/buildroot/include");
        putenv("LDFLAGS=-L/usr/local/lib -L{$work_dir}/buildroot/lib -L/buildroot/lib");
    }

    /**
     * 确保头文件存在
     */
    protected function ensureHeaders(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $header_dirs = [
            $work_dir . '/buildroot/include',
            '/buildroot/include',
        ];

        // 创建必要的目录
        if (!is_dir('/usr/local/include/mosquitto')) {
            mkdir('/usr/local/include/mosquitto', 0755, true);
        }

        // 复制所有头文件
        foreach ($header_dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = scandir($dir);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'h') {
                    $source = $dir . '/' . $file;
                    $target = '/usr/local/include/' . $file;
                    $target_sub = '/usr/local/include/mosquitto/' . $file;

                    if (!file_exists($target)) {
                        copy($source, $target);
                    }
                    if (!file_exists($target_sub)) {
                        copy($source, $target_sub);
                    }
                }
            }
        }

        echo "[I] Headers installed\n";
    }

    /**
     * 确保库文件存在
     */
    protected function ensureLibraries(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $lib_dirs = [
            $work_dir . '/buildroot/lib',
            '/buildroot/lib',
        ];

        if (!is_dir('/usr/local/lib')) {
            mkdir('/usr/local/lib', 0755, true);
        }

        // 复制所有 .a 文件
        foreach ($lib_dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = scandir($dir);
            foreach ($files as $file) {
                if (substr($file, -2) === '.a') {
                    $source = $dir . '/' . $file;
                    $target = '/usr/local/lib/' . $file;

                    if (!file_exists($target)) {
                        copy($source, $target);
                        echo "[I] Copied library: {$file}\n";
                    }
                }
            }
        }

        // 特别处理 mosquitto 库
        $mosquitto_libs = [
            'libmosquitto.a',
            'libmosquitto_static.a',
        ];

        foreach ($mosquitto_libs as $lib) {
            foreach ($lib_dirs as $dir) {
                $source = $dir . '/' . $lib;
                if (file_exists($source)) {
                    // 确保在标准位置
                    copy($source, '/usr/local/lib/' . $lib);

                    // 如果 libmosquitto.a 不存在，创建符号链接
                    if ($lib === 'libmosquitto_static.a' && !file_exists('/usr/local/lib/libmosquitto.a')) {
                        symlink('/usr/local/lib/libmosquitto_static.a', '/usr/local/lib/libmosquitto.a');
                    }
                    break;
                }
            }
        }

        // 创建 pkg-config 文件
        $this->createPkgConfig();
    }

    /**
     * 创建 pkg-config 文件
     */
    protected function createPkgConfig(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        $pc_content = <<<EOF
prefix=/usr/local
exec_prefix=\${prefix}
libdir=\${exec_prefix}/lib
includedir=\${prefix}/include

Name: libmosquitto
Description: Eclipse Mosquitto MQTT library
Version: 2.0.18
Libs: -L\${libdir} -lmosquitto
Cflags: -I\${includedir}
Requires.private: openssl cjson

EOF;

        $pc_dir = '/usr/local/lib/pkgconfig';
        if (!is_dir($pc_dir)) {
            mkdir($pc_dir, 0755, true);
        }

        file_put_contents($pc_dir . '/libmosquitto.pc', $pc_content);
        echo "[I] Created pkg-config file\n";
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        $work_dir = $this->builder->getOption('work_dir');

        // 设置环境变量
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
