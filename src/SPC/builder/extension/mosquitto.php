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
        $this->setupHeaderPaths();
        return true;
    }

    /**
     * 设置正确的头文件路径
     */
    protected function setupHeaderPaths(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        // 重要：mosquitto 安装到了 /buildroot，而不是 /app/buildroot
        $buildroot_include = '/buildroot/include';
        $app_buildroot_include = $work_dir . '/buildroot/include';

        echo "[I] Setting up header paths...\n";

        // 1. 确保 /buildroot/include 存在且包含所有头文件
        if (!is_dir('/buildroot')) {
            symlink($work_dir . '/buildroot', '/buildroot');
            echo "[I] Created symlink /buildroot -> {$work_dir}/buildroot\n";
        }

        // 2. 创建必要的目录结构
        $dirs = [
            '/usr/local/include',
            '/usr/local/include/mosquitto',
            $buildroot_include,
            $buildroot_include . '/mosquitto',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                echo "[I] Created directory: {$dir}\n";
            }
        }

        // 3. 复制所有头文件到标准位置
        if (is_dir($buildroot_include)) {
            $this->copyHeaders($buildroot_include, '/usr/local/include');
        }

        if (is_dir($app_buildroot_include)) {
            $this->copyHeaders($app_buildroot_include, '/usr/local/include');
        }

        // 4. 设置编译器环境变量 - 同时包含两个路径
        $include_paths = [
            '/usr/local/include',
            $buildroot_include,
            $app_buildroot_include,
            $buildroot_include . '/mosquitto',
            $app_buildroot_include . '/mosquitto',
        ];

        $cflags = '-g -fstack-protector-strong -fno-ident -fPIE -fPIC -Os';
        foreach ($include_paths as $path) {
            if (is_dir($path)) {
                $cflags .= ' -I' . $path;
            }
        }

        // 设置环境变量
        putenv("CFLAGS={$cflags}");
        putenv("CPPFLAGS={$cflags}");
        putenv("PKG_CONFIG_PATH=/buildroot/lib/pkgconfig:{$work_dir}/buildroot/lib/pkgconfig");

        echo "[I] Set CFLAGS: {$cflags}\n";
    }

    /**
     * 复制头文件
     */
    protected function copyHeaders(string $source, string $dest): void
    {
        if (!is_dir($source)) {
            return;
        }

        $files = scandir($source);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $source_path = $source . '/' . $file;
            $dest_path = $dest . '/' . $file;

            if (is_dir($source_path)) {
                if (!is_dir($dest_path)) {
                    mkdir($dest_path, 0755, true);
                }
                $this->copyHeaders($source_path, $dest_path);
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'h') {
                if (!file_exists($dest_path)) {
                    copy($source_path, $dest_path);
                    echo "[I] Copied header: {$file}\n";
                }
            }
        }
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        $work_dir = $this->builder->getOption('work_dir');

        // 设置所有必要的环境变量
        $include_paths = [
            '/usr/local/include',
            '/buildroot/include',
            $work_dir . '/buildroot/include',
            '/buildroot/include/mosquitto',
            $work_dir . '/buildroot/include/mosquitto',
        ];

        $cflags = '-g -fstack-protector-strong -fno-ident -fPIE -fPIC -Os';
        foreach ($include_paths as $path) {
            if (is_dir($path)) {
                $cflags .= ' -I' . $path;
            }
        }

        putenv("CFLAGS={$cflags}");
        putenv("CPPFLAGS={$cflags}");
        putenv("PKG_CONFIG_PATH=/buildroot/lib/pkgconfig:{$work_dir}/buildroot/lib/pkgconfig");
        putenv("LDFLAGS=-L/buildroot/lib -L{$work_dir}/buildroot/lib");

        return '--with-mosquitto' . ($shared ? '=shared' : '');
    }

    public function getWindowsConfigureArg($shared = false): string
    {
        return '--with-mosquitto';
    }
}
