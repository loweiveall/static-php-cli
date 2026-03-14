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

    public function patchBeforeMake(): bool
    {
        $patched = parent::patchBeforeMake();

        if (PHP_OS_FAMILY === 'Windows') {
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\php_mosquitto.h', '/^#warning.*/m', '');
            return true;
        }

        // 为 PHP 8.2 打补丁
        $this->patchForPHP82();

        return $patched;
    }

    /**
     * 为 PHP 8.2 打补丁，移除 TSRMLS_CC
     */
    protected function patchForPHP82(): void
    {
        $source_file = $this->source_dir . '/mosquitto.c';
        if (!file_exists($source_file)) {
            return;
        }

        $content = file_get_contents($source_file);
        $original = $content;

        // 1. 移除 REGISTER_MOSQUITTO_LONG_CONST 宏中的 TSRMLS_CC
        $content = preg_replace(
            '/zend_declare_class_constant_long\(([^,]+), ([^,]+), ([^,]+), ([^)]+)\) TSRMLS_CC;/',
            'zend_declare_class_constant_long($1, $2, $3, $4);',
            $content
        );

        // 2. 移除其他地方的 TSRMLS_CC
        $content = str_replace(' TSRMLS_CC', '', $content);
        $content = str_replace('TSRMLS_CC', '', $content);

        // 3. 移除 TSRMLS_DC 相关的代码
        $content = str_replace('TSRMLS_DC', '', $content);
        $content = str_replace(', TSRMLS_DC', '', $content);

        // 4. 检查函数声明中是否有 TSRMLS_DC 参数
        $content = preg_replace(
            '/PHP_FUNCTION\(([^)]+)\)\s*{\s*TSRMLS_DC;/',
            'PHP_FUNCTION($1) {',
            $content
        );

        if ($content !== $original) {
            file_put_contents($source_file, $content);
            echo "[I] Applied PHP 8.2 compatibility patch to mosquitto.c\n";
        }

        // 同时检查并修改其他文件
        $other_files = [
            $this->source_dir . '/mosquitto_client.c',
            $this->source_dir . '/mosquitto_message.c',
            $this->source_dir . '/mosquitto_private.h',
            $this->source_dir . '/php_mosquitto.h',
        ];

        foreach ($other_files as $file) {
            if (file_exists($file)) {
                $file_content = file_get_contents($file);
                $file_original = $file_content;

                $file_content = str_replace(' TSRMLS_CC', '', $file_content);
                $file_content = str_replace('TSRMLS_CC', '', $file_content);
                $file_content = str_replace(' TSRMLS_DC', '', $file_content);
                $file_content = str_replace('TSRMLS_DC', '', $file_content);

                if ($file_content !== $file_original) {
                    file_put_contents($file, $file_content);
                    echo "[I] Applied PHP 8.2 compatibility patch to " . basename($file) . "\n";
                }
            }
        }
    }

    /**
     * 设置正确的头文件路径
     */
    protected function setupHeaderPaths(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $buildroot_include = '/buildroot/include';
        $app_buildroot_include = $work_dir . '/buildroot/include';

        echo "[I] Setting up header paths...\n";

        // 确保 /buildroot 存在
        if (!is_dir('/buildroot') && is_dir($work_dir . '/buildroot')) {
            symlink($work_dir . '/buildroot', '/buildroot');
            echo "[I] Created symlink /buildroot -> {$work_dir}/buildroot\n";
        }

        // 设置编译器环境变量
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

        putenv("CFLAGS={$cflags}");
        putenv("CPPFLAGS={$cflags}");
        putenv("PKG_CONFIG_PATH=/buildroot/lib/pkgconfig:{$work_dir}/buildroot/lib/pkgconfig");

        echo "[I] Set CFLAGS: {$cflags}\n";
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        $work_dir = $this->builder->getOption('work_dir');

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
