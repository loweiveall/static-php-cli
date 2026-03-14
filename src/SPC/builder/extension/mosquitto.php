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
        // 在 configure 之前设置所有必要的路径和文件
        $this->setupEnvironment();
        return true;
    }

    public function patchBeforeMake(): bool
    {
        $patched = parent::patchBeforeMake();

        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }

        // 为 PHP 8.2 打补丁
        $this->patchForPHP82();

        return $patched;
    }

    /**
     * 设置完整的编译环境
     */
    protected function setupEnvironment(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        echo "[I] ===== Setting up mosquitto build environment =====\n";

        // 1. 确保所有目录存在
        $dirs = [
            '/usr/local/lib',
            '/usr/local/include',
            '/usr/local/include/mosquitto',
            $work_dir . '/buildroot/lib',
            $work_dir . '/buildroot/include',
            $work_dir . '/buildroot/include/mosquitto',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                echo "[I] Created directory: {$dir}\n";
            }
        }

        // 2. 查找并复制库文件到标准位置
        $lib_files = [
            'libmosquitto.a',
            'libmosquitto_static.a',
            'libcjson.a',
            'libssl.a',
            'libcrypto.a',
        ];

        foreach ($lib_files as $lib) {
            // 查找库文件
            $found = false;
            $search_paths = [
                $work_dir . '/buildroot/lib/' . $lib,
                '/buildroot/lib/' . $lib,
                '/app/buildroot/lib/' . $lib,
            ];

            foreach ($search_paths as $path) {
                if (file_exists($path)) {
                    echo "[I] Found {$lib} at: {$path}\n";

                    // 复制到 /usr/local/lib
                    $target = '/usr/local/lib/' . $lib;
                    if (!file_exists($target)) {
                        copy($path, $target);
                        echo "[I] Copied to: {$target}\n";
                    }

                    // 同时复制到 buildroot/lib
                    $buildroot_target = $work_dir . '/buildroot/lib/' . $lib;
                    if (!file_exists($buildroot_target)) {
                        copy($path, $buildroot_target);
                        echo "[I] Copied to: {$buildroot_target}\n";
                    }

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                echo "[W] Could not find {$lib}\n";
            }
        }

        // 3. 复制所有头文件
        $header_dirs = [
            $work_dir . '/buildroot/include',
            '/buildroot/include',
        ];

        foreach ($header_dirs as $header_dir) {
            if (is_dir($header_dir)) {
                $this->copyHeaders($header_dir, '/usr/local/include');
            }
        }

        // 4. 特别处理 mosquitto 头文件
        $mosquitto_headers = [
            'mosquitto.h',
            'mosquitto_broker.h',
            'mosquitto_plugin.h',
            'mosquittopp.h',
            'mqtt_protocol.h',
            'libmosquitto.h',
        ];

        foreach ($mosquitto_headers as $header) {
            $found = false;
            $search_paths = [
                $work_dir . '/buildroot/include/' . $header,
                $work_dir . '/buildroot/include/mosquitto/' . $header,
                '/buildroot/include/' . $header,
                '/buildroot/include/mosquitto/' . $header,
            ];

            foreach ($search_paths as $path) {
                if (file_exists($path)) {
                    // 复制到根目录
                    copy($path, '/usr/local/include/' . $header);
                    // 复制到 mosquitto 子目录
                    copy($path, '/usr/local/include/mosquitto/' . $header);
                    echo "[I] Installed header: {$header}\n";
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                echo "[W] Could not find header: {$header}\n";
            }
        }

        // 5. 创建或更新 pkg-config 文件
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

        $pc_path = '/usr/local/lib/pkgconfig/libmosquitto.pc';
        if (!is_dir(dirname($pc_path))) {
            mkdir(dirname($pc_path), 0755, true);
        }
        file_put_contents($pc_path, $pc_content);
        echo "[I] Created pkg-config file: {$pc_path}\n";

        // 6. 设置环境变量
        putenv("PKG_CONFIG_PATH=/usr/local/lib/pkgconfig:{$work_dir}/buildroot/lib/pkgconfig:/buildroot/lib/pkgconfig");
        putenv("CFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include -I/buildroot/include");
        putenv("CPPFLAGS=-I/usr/local/include -I{$work_dir}/buildroot/include -I/buildroot/include");
        putenv("LDFLAGS=-L/usr/local/lib -L{$work_dir}/buildroot/lib -L/buildroot/lib");

        // 7. 验证设置
        echo "[I] ===== Environment verification =====\n";

        // 检查库文件
        $check_libs = ['libmosquitto.a', 'libcjson.a', 'libssl.a', 'libcrypto.a'];
        foreach ($check_libs as $lib) {
            $paths = [
                '/usr/local/lib/' . $lib,
                $work_dir . '/buildroot/lib/' . $lib,
                '/buildroot/lib/' . $lib,
            ];
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    echo "[I] ✓ {$lib} found at: {$path}\n";
                    break;
                }
            }
        }

        // 检查头文件
        $check_headers = ['mosquitto.h', 'cJSON.h', 'mqtt_protocol.h'];
        foreach ($check_headers as $header) {
            $paths = [
                '/usr/local/include/' . $header,
                '/usr/local/include/mosquitto/' . $header,
            ];
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    echo "[I] ✓ {$header} found at: {$path}\n";
                    break;
                }
            }
        }

        echo "[I] ===== Environment setup complete =====\n";
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
        // 设置环境变量
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

    /**
     * 为 PHP 8.2 打完整的兼容性补丁
     */
    protected function patchForPHP82(): void
    {
        echo "[I] Applying comprehensive PHP 8.2 compatibility patches...\n";

        // 1. 修改 php_mosquitto.h
        $this->patchHeaderFile();

        // 2. 修改 mosquitto.c
        $this->patchSourceFile();

        // 3. 修改 mosquitto_message.c
        $this->patchMessageFile();

        // 4. 修改 mosquitto_client.c
        $this->patchClientFile();
    }

    /**
     * 修补头文件
     */
    protected function patchHeaderFile(): void
    {
        $header_file = $this->source_dir . '/php_mosquitto.h';
        if (!file_exists($header_file)) {
            return;
        }

        $content = file_get_contents($header_file);
        $original = $content;

        // 添加缺失的类型定义
        $type_definitions = <<<'EOF'
#ifndef PHP_MOSQUITTO_TYPES_DEFINED
#define PHP_MOSQUITTO_TYPES_DEFINED

/* Define callback types for PHP 8.2 compatibility */
typedef void (*php_mosquitto_read_t)(void);
typedef void (*php_mosquitto_write_t)(void);

#endif

EOF;

        // 在文件开头添加类型定义
        if (strpos($content, 'php_mosquitto_write_t') === false) {
            $content = $type_definitions . "\n" . $content;
        }

        // 移除所有 TSRMLS_CC
        $content = str_replace(' TSRMLS_CC', '', $content);
        $content = str_replace('TSRMLS_CC', '', $content);
        $content = str_replace(' TSRMLS_DC', '', $content);
        $content = str_replace('TSRMLS_DC', '', $content);

        // 修复 PHP_MOSQUITTO_ADD_PROPERTIES 宏
        $pattern = '/#define\s+PHP_MOSQUITTO_ADD_PROPERTIES\(\s*\(a\)\s*,\s*\(b\)\s*\)(.*?)(?=\n\S)/s';
        $replacement = <<<'EOF'
#define PHP_MOSQUITTO_ADD_PROPERTIES(a, b) \
    do { \
        int i; \
        for (i = 0; (b)[i].name != NULL; i++) { \
            php_mosquitto_message_add_property((a), (b)[i].name, (b)[i].name_length, \
                (php_mosquitto_read_t)(b)[i].read_func, (php_mosquitto_write_t)(b)[i].write_func); \
        } \
    } while(0)
EOF;

        $content = preg_replace($pattern, $replacement, $content);

        if ($content !== $original) {
            file_put_contents($header_file, $content);
            echo "[I] Patched php_mosquitto.h\n";
        }
    }

    /**
     * 修补主源文件
     */
    protected function patchSourceFile(): void
    {
        $source_file = $this->source_dir . '/mosquitto.c';
        if (!file_exists($source_file)) {
            return;
        }

        $content = file_get_contents($source_file);
        $original = $content;

        // 移除所有 TSRMLS_CC
        $content = str_replace(' TSRMLS_CC', '', $content);
        $content = str_replace('TSRMLS_CC', '', $content);

        // 修复 REGISTER_MOSQUITTO_LONG_CONST 宏
        $pattern = '/#define\s+REGISTER_MOSQUITTO_LONG_CONST\(\s*const_name\s*,\s*value\s*\)(.*?)(?=\n\S)/s';
        $replacement = <<<'EOF'
#define REGISTER_MOSQUITTO_LONG_CONST(const_name, value) \
    zend_declare_class_constant_long(mosquitto_ce_client, const_name, sizeof(const_name)-1, (long)value)
EOF;

        $content = preg_replace($pattern, $replacement, $content);

        if ($content !== $original) {
            file_put_contents($source_file, $content);
            echo "[I] Patched mosquitto.c\n";
        }
    }

    /**
     * 修补消息文件
     */
    protected function patchMessageFile(): void
    {
        $message_file = $this->source_dir . '/mosquitto_message.c';
        if (!file_exists($message_file)) {
            return;
        }

        $content = file_get_contents($message_file);
        $original = $content;

        // 移除所有 TSRMLS_CC
        $content = str_replace(' TSRMLS_CC', '', $content);
        $content = str_replace('TSRMLS_CC', '', $content);

        if ($content !== $original) {
            file_put_contents($message_file, $content);
            echo "[I] Patched mosquitto_message.c\n";
        }
    }

    /**
     * 修补客户端文件
     */
    protected function patchClientFile(): void
    {
        $client_file = $this->source_dir . '/mosquitto_client.c';
        if (!file_exists($client_file)) {
            return;
        }

        $content = file_get_contents($client_file);
        $original = $content;

        // 移除所有 TSRMLS_CC
        $content = str_replace(' TSRMLS_CC', '', $content);
        $content = str_replace('TSRMLS_CC', '', $content);

        if ($content !== $original) {
            file_put_contents($client_file, $content);
            echo "[I] Patched mosquitto_client.c\n";
        }
    }
}
