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

        if (PHP_OS_FAMILY === 'Windows') {
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\php_mosquitto.h', '/^#warning.*/m', '');
            return true;
        }

        return $patched;
    }

    /**
     * 设置完整的头文件结构
     */
    protected function setupHeaderFiles(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $buildroot_include = $work_dir . '/buildroot/include';
        $usr_include = '/usr/local/include';

        echo "[I] Setting up mosquitto header files...\n";

        // 1. 创建必要的目录
        $dirs = [
            $usr_include,
            $usr_include . '/mosquitto',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                echo "[I] Created directory: {$dir}\n";
            }
        }

        // 2. 复制所有头文件到正确的位置
        $this->copyHeadersRecursive($buildroot_include, $usr_include);

        // 3. 特别处理 mosquitto 子目录
        if (is_dir($buildroot_include . '/mosquitto')) {
            $this->copyHeadersRecursive($buildroot_include . '/mosquitto', $usr_include . '/mosquitto');
        }

        // 4. 确保 mqtt_protocol.h 同时存在于根目录和 mosquitto 子目录
        $mqtt_protocol_sources = [
            $buildroot_include . '/mqtt_protocol.h',
            $buildroot_include . '/mosquitto/mqtt_protocol.h',
            $work_dir . '/source/libmosquitto/include/mqtt_protocol.h',
        ];

        foreach ($mqtt_protocol_sources as $source) {
            if (file_exists($source)) {
                copy($source, $usr_include . '/mqtt_protocol.h');
                copy($source, $usr_include . '/mosquitto/mqtt_protocol.h');
                echo "[I] Copied mqtt_protocol.h from {$source}\n";
                break;
            }
        }

        // 5. 修复 mosquitto.h 中的包含路径
        $this->fixMosquittoHeader($usr_include . '/mosquitto.h');

        // 6. 设置编译环境变量
        putenv("CFLAGS=-I{$usr_include} -I{$buildroot_include}");
        putenv("CPPFLAGS=-I{$usr_include} -I{$buildroot_include}");

        echo "[I] Header files setup complete\n";
    }

    /**
     * 递归复制头文件
     */
    protected function copyHeadersRecursive(string $source, string $dest): void
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
                $this->copyHeadersRecursive($source_path, $dest_path);
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'h') {
                copy($source_path, $dest_path);
                echo "[I] Copied header: {$file}\n";
            }
        }
    }

    /**
     * 修复 mosquitto.h 中的包含路径
     */
    protected function fixMosquittoHeader(string $header_path): void
    {
        if (!file_exists($header_path)) {
            return;
        }

        $content = file_get_contents($header_path);
        $original = $content;

        // 将 #include <mosquitto/xxx.h> 改为 #include <xxx.h>
        $content = preg_replace('/#include\s+<mosquitto\/([^>]+)>/', '#include <$1>', $content);

        if ($content !== $original) {
            file_put_contents($header_path, $content);
            echo "[I] Fixed include paths in mosquitto.h\n";
        }
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
