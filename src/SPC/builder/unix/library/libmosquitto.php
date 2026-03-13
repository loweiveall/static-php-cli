<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\store\FileSystem;

trait libmosquitto
{
    protected function build(): void
    {
        // 1. 首先确保 cJSON 头文件存在
        $this->ensureCjsonHeaders();

        // 2. 直接修改源码中的 include 语句
        $this->fixCjsonIncludes();

        // 3. 禁用 C++ 库（不需要）
        $this->disableCppLib();

        // 创建构建目录
        if (!is_dir($this->source_dir . '/build')) {
            mkdir($this->source_dir . '/build', 0755, true);
        }

        // 进入构建目录
        shell()->cd($this->source_dir . '/build')
            ->exec('rm -rf *')
            ->exec("{$this->builder->getOption('configure_env')} cmake .. \
                -DBUILD_SHARED_LIBS=OFF \
                -DCMAKE_INSTALL_PREFIX={$this->builder->getOption('work_dir')}/buildroot \
                -DCMAKE_BUILD_TYPE=Release \
                -DWITH_STATIC_LIBRARIES=ON \
                -DWITH_SHARED_LIBRARIES=OFF \
                -DWITH_TLS=ON \
                -DWITH_WEBSOCKETS=OFF \
                -DWITH_SRV=OFF \
                -DDOCUMENTATION=OFF \
                -DWITH_DOCS=OFF \
                -DWITH_CJSON=ON \
                -DWITH_STRIP=OFF \
                -DWITH_BROKER=OFF \
                -DWITH_CLIENTS=OFF \
                -DWITH_PLUGINS=OFF \
                -DWITH_PERSISTENCE=OFF \
                -DWITH_BRIDGE=OFF \
                -DWITH_SYS_TREE=OFF \
                -DWITH_APPS=OFF \
                -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
                -DWITH_CPP=OFF")  // 新增：禁用 C++ 库
            ->exec("make -j{$this->builder->concurrency}")
            ->exec('make install');

        // 复制头文件
        $this->copyHeaderFiles();

        // 生成 pkg-config 文件
        $this->patchPkgconf();
    }

    /**
     * 确保 cJSON 头文件存在
     */
    protected function ensureCjsonHeaders(): void
    {
        // 检查 cJSON 头文件是否在 buildroot 中
        if (!file_exists(BUILD_INCLUDE_PATH . '/cJSON.h')) {
            // 尝试从其他位置查找
            $possible_paths = [
                '/usr/local/include/cJSON.h',
                '/usr/include/cJSON.h',
                $this->builder->getOption('work_dir') . '/buildroot/include/cJSON.h',
                dirname($this->source_dir) . '/cjson/cJSON.h'
            ];

            foreach ($possible_paths as $path) {
                if (file_exists($path)) {
                    copy($path, BUILD_INCLUDE_PATH . '/cJSON.h');
                    echo "[I] Found cJSON.h at {$path}\n";
                    break;
                }
            }
        }

        // 如果仍然不存在，报错
        if (!file_exists(BUILD_INCLUDE_PATH . '/cJSON.h')) {
            throw new \RuntimeException('cJSON.h not found! Please ensure cjson is built first.');
        }
    }

    /**
     * 修复 cJSON 头文件引用
     * 将所有 #include <cjson/cJSON.h> 改为 #include <cJSON.h>
     */
    protected function fixCjsonIncludes(): void
    {
        echo "[I] Fixing cJSON includes in mosquitto source...\n";

        // 查找所有需要修改的文件
        $files_to_fix = [
            $this->source_dir . '/include/mosquitto/libcommon_cjson.h',
            $this->source_dir . '/libcommon/cjson_common.c',
            $this->source_dir . '/lib/cpp/mosquittopp.cpp',
            $this->source_dir . '/lib/cpp/mosquittopp.h',
            $this->source_dir . '/lib/mosquitto.c',
        ];

        foreach ($files_to_fix as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $original = $content;

                // 替换 include 语句
                $content = str_replace(
                    ['#include <cjson/cJSON.h>', '#include "cjson/cJSON.h"'],
                    ['#include <cJSON.h>', '#include "cJSON.h"'],
                    $content
                );

                if ($content !== $original) {
                    file_put_contents($file, $content);
                    echo "[I] Fixed includes in: {$file}\n";
                }
            }
        }

        // 使用 find 命令搜索并替换所有文件
        shell()->cd($this->source_dir)
            ->exec("find . -name '*.c' -o -name '*.h' -o -name '*.cpp' | xargs sed -i 's/#include <cjson\\/cJSON.h>/#include <cJSON.h>/g' 2>/dev/null || true")
            ->exec("find . -name '*.c' -o -name '*.h' -o -name '*.cpp' | xargs sed -i 's/#include \"cjson\\/cJSON.h\"/#include \"cJSON.h\"/g' 2>/dev/null || true");
    }

    /**
     * 禁用 C++ 库编译
     */
    protected function disableCppLib(): void
    {
        $cmake_file = $this->source_dir . '/CMakeLists.txt';
        if (file_exists($cmake_file)) {
            $content = file_get_contents($cmake_file);
            // 注释掉 C++ 子目录
            $content = preg_replace('/add_subdirectory\(lib\/cpp\)/', '# add_subdirectory(lib/cpp)', $content);
            file_put_contents($cmake_file, $content);
        }

        // 重命名 cpp 目录
        $cpp_dir = $this->source_dir . '/lib/cpp';
        if (is_dir($cpp_dir)) {
            rename($cpp_dir, $cpp_dir . '.disabled');
        }
    }

    protected function copyHeaderFiles(): void
    {
        // 确保 include 目录存在
        if (!is_dir(BUILD_INCLUDE_PATH)) {
            mkdir(BUILD_INCLUDE_PATH, 0755, true);
        }

        // 从源码目录复制头文件
        $source_include = $this->source_dir . '/include';
        if (is_dir($source_include)) {
            FileSystem::copyDir($source_include, BUILD_INCLUDE_PATH);
        }

        // 从安装目录复制
        $install_include = $this->builder->getOption('work_dir') . '/buildroot/include';
        if (is_dir($install_include)) {
            FileSystem::copyDir($install_include, BUILD_INCLUDE_PATH);
        }
    }

    protected function patchPkgconf(): void
    {
        // 获取版本号
        $version = '2.0.18';

        if (file_exists($this->source_dir . '/CMakeLists.txt')) {
            $cmake_content = file_get_contents($this->source_dir . '/CMakeLists.txt');
            if (preg_match('/set\s*\(\s*VERSION\s+([0-9.]+)\s*\)/i', $cmake_content, $matches)) {
                $version = $matches[1];
            }
        }

        $pc_content = <<<EOF
prefix={$this->builder->getOption('work_dir')}/buildroot
exec_prefix=\${prefix}
libdir=\${exec_prefix}/lib
includedir=\${prefix}/include

Name: libmosquitto
Description: Eclipse Mosquitto MQTT library
Version: {$version}
Libs: -L\${libdir} -lmosquitto
Cflags: -I\${includedir}
Requires.private: openssl cjson

EOF;

        if (!is_dir(BUILD_LIB_PATH . '/pkgconfig')) {
            mkdir(BUILD_LIB_PATH . '/pkgconfig', 0755, true);
        }

        file_put_contents(BUILD_LIB_PATH . '/pkgconfig/libmosquitto.pc', $pc_content);
    }
}
