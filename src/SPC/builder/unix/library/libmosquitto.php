<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\store\FileSystem;

trait libmosquitto
{
    protected function build(): void
    {
        // 1. 确保 cJSON 头文件存在
        $this->ensureCjsonHeaders();

        // 2. 修复头文件引用
        $this->fixCjsonIncludes();

        // 3. 修改 CMakeLists.txt 禁用 cpp
        $this->disableCpp();

        // 4. 修改 CMakeLists.txt 禁用测试
        $this->disableTesting();  // 新增

        // 创建构建目录
        if (!is_dir($this->source_dir . '/build')) {
            mkdir($this->source_dir . '/build', 0755, true);
        }

        // 获取正确的工作目录路径
        $work_dir = $this->builder->getOption('work_dir');

        // 进入构建目录
        shell()->cd($this->source_dir . '/build')
            ->exec('rm -rf *')
            ->exec("{$this->builder->getOption('configure_env')} cmake .. \
                -DBUILD_SHARED_LIBS=OFF \
                -DCMAKE_INSTALL_PREFIX={$work_dir}/buildroot \
                -DCMAKE_BUILD_TYPE=Release \
                -DWITH_STATIC_LIBRARIES=ON \
                -DWITH_SHARED_LIBRARIES=OFF \
                -DWITH_TLS=ON \
                -DWITH_CJSON=ON \
                -DWITH_BROKER=OFF \
                -DWITH_CLIENTS=OFF \
                -DWITH_PLUGINS=OFF \
                -DWITH_PERSISTENCE=OFF \
                -DWITH_BRIDGE=OFF \
                -DWITH_SYS_TREE=OFF \
                -DWITH_APPS=OFF \
                -DWITH_WEBSOCKETS=OFF \
                -DWITH_SRV=OFF \
                -DDOCUMENTATION=OFF \
                -DWITH_DOCS=OFF \
                -DWITH_STRIP=OFF \
                -DWITH_CPP=OFF \
                -DWITH_TESTING=OFF \
                -DCMAKE_POSITION_INDEPENDENT_CODE=ON")
            ->exec("make -j{$this->builder->concurrency}")
            ->exec('make install');

        // 确保库文件存在
        $this->ensureLibraryFiles();

        // 复制头文件
        $this->copyHeaderFiles();

        // 生成 pkg-config 文件
        $this->patchPkgconf();
    }

    /**
     * 禁用 C++ 库编译
     */
    protected function disableCpp(): void
    {
        $lib_cmake = $this->source_dir . '/lib/CMakeLists.txt';
        if (file_exists($lib_cmake)) {
            $content = file_get_contents($lib_cmake);
            $original = $content;

            // 注释掉 cpp 子目录
            $content = str_replace('add_subdirectory(cpp)', '# add_subdirectory(cpp)', $content);

            if ($content !== $original) {
                file_put_contents($lib_cmake, $content);
                echo "[I] Disabled cpp subdirectory in lib/CMakeLists.txt\n";
            }
        }

        // 如果存在 cpp 目录，重命名它
        $cpp_dir = $this->source_dir . '/lib/cpp';
        if (is_dir($cpp_dir)) {
            rename($cpp_dir, $cpp_dir . '.disabled');
            echo "[I] Renamed cpp directory\n";
        }
    }

    /**
     * 确保 cJSON 头文件存在
     */
    protected function ensureCjsonHeaders(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        // 确保 include 目录存在
        if (!is_dir(BUILD_INCLUDE_PATH)) {
            mkdir(BUILD_INCLUDE_PATH, 0755, true);
        }

        // 检查 cJSON.h 是否已存在
        if (!file_exists(BUILD_INCLUDE_PATH . '/cJSON.h')) {
            // 从 cjson 源码目录复制
            $cjson_source = dirname($this->source_dir) . '/cjson';
            if (file_exists($cjson_source . '/cJSON.h')) {
                copy($cjson_source . '/cJSON.h', BUILD_INCLUDE_PATH . '/cJSON.h');
                echo "[I] Copied cJSON.h from cjson source\n";
            }
        }

        // 确保 cjson 子目录存在并包含头文件（mosquitto 可能需要）
        if (!is_dir(BUILD_INCLUDE_PATH . '/cjson')) {
            mkdir(BUILD_INCLUDE_PATH . '/cjson', 0755, true);
        }

        if (file_exists(BUILD_INCLUDE_PATH . '/cJSON.h') && !file_exists(BUILD_INCLUDE_PATH . '/cjson/cJSON.h')) {
            copy(BUILD_INCLUDE_PATH . '/cJSON.h', BUILD_INCLUDE_PATH . '/cjson/cJSON.h');
        }

        if (!file_exists(BUILD_INCLUDE_PATH . '/cJSON.h')) {
            throw new \RuntimeException('cJSON.h not found! Please ensure cjson is built first.');
        }
    }

    /**
     * 修复 cJSON 头文件引用
     */
    protected function fixCjsonIncludes(): void
    {
        echo "[I] Fixing cJSON includes in mosquitto source...\n";

        shell()->cd($this->source_dir)
            ->exec("find . -name '*.c' -o -name '*.h' -o -name '*.cpp' | xargs sed -i 's/#include <cjson\\/cJSON.h>/#include <cJSON.h>/g' 2>/dev/null || true")
            ->exec("find . -name '*.c' -o -name '*.h' -o -name '*.cpp' | xargs sed -i 's/#include \"cjson\\/cJSON.h\"/#include \"cJSON.h\"/g' 2>/dev/null || true");
    }

    /**
     * 确保库文件存在
     */
    protected function ensureLibraryFiles(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $lib_dir = BUILD_LIB_PATH;

        echo "[I] Checking for mosquitto static library in: {$lib_dir}\n";

        // 列出目录内容以便调试
        if (is_dir($lib_dir)) {
            $files = scandir($lib_dir);
            echo "[I] Files in {$lib_dir}: " . implode(', ', array_diff($files, ['.', '..'])) . "\n";
        }

        // 检查是否有 libmosquitto_static.a
        if (file_exists($lib_dir . '/libmosquitto_static.a')) {
            echo "[I] Found libmosquitto_static.a\n";

            // 创建符号链接
            if (!file_exists($lib_dir . '/libmosquitto.a')) {
                shell()->exec("ln -sf {$lib_dir}/libmosquitto_static.a {$lib_dir}/libmosquitto.a");
                echo "[I] Created symlink: libmosquitto.a -> libmosquitto_static.a\n";
            }
        }
        // 检查安装目录
        elseif (file_exists($work_dir . '/buildroot/lib/libmosquitto_static.a')) {
            echo "[I] Found libmosquitto_static.a in buildroot, copying...\n";
            copy($work_dir . '/buildroot/lib/libmosquitto_static.a', $lib_dir . '/libmosquitto_static.a');

            // 创建符号链接
            shell()->exec("ln -sf {$lib_dir}/libmosquitto_static.a {$lib_dir}/libmosquitto.a");
        }
        else {
            throw new \RuntimeException('No mosquitto static library found in ' . $lib_dir . ' or ' . $work_dir . '/buildroot/lib');
        }
    }

    protected function copyHeaderFiles(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        if (!is_dir(BUILD_INCLUDE_PATH)) {
            mkdir(BUILD_INCLUDE_PATH, 0755, true);
        }

        // 从源码目录复制头文件
        $source_include = $this->source_dir . '/include';
        if (is_dir($source_include)) {
            FileSystem::copyDir($source_include, BUILD_INCLUDE_PATH);
        }

        // 从安装目录复制
        $install_include = $work_dir . '/buildroot/include';
        if (is_dir($install_include)) {
            FileSystem::copyDir($install_include, BUILD_INCLUDE_PATH);
        }

        // 新增：确保 mqtt_protocol.h 在正确的位置
        $mqtt_protocol_sources = [
            $this->source_dir . '/include/mqtt_protocol.h',
            $work_dir . '/buildroot/include/mqtt_protocol.h',
            $this->source_dir . '/lib/mqtt_protocol.h',
        ];

        foreach ($mqtt_protocol_sources as $source) {
            if (file_exists($source)) {
                copy($source, BUILD_INCLUDE_PATH . '/mqtt_protocol.h');
                echo "[I] Copied mqtt_protocol.h from {$source}\n";
                break;
            }
        }

        // 创建 mosquitto 子目录并复制头文件
        if (!is_dir(BUILD_INCLUDE_PATH . '/mosquitto')) {
            mkdir(BUILD_INCLUDE_PATH . '/mosquitto', 0755, true);
        }

        // 复制所有头文件到 mosquitto 子目录
        $headers = glob(BUILD_INCLUDE_PATH . '/*.h');
        foreach ($headers as $header) {
            $basename = basename($header);
            if (!file_exists(BUILD_INCLUDE_PATH . '/mosquitto/' . $basename)) {
                copy($header, BUILD_INCLUDE_PATH . '/mosquitto/' . $basename);
            }
        }
    }

    /**
     * 生成 pkg-config 文件
     */
    protected function patchPkgconf(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        // 获取版本号
        $version = '2.0.18';

        if (file_exists($this->source_dir . '/CMakeLists.txt')) {
            $cmake_content = file_get_contents($this->source_dir . '/CMakeLists.txt');
            if (preg_match('/set\s*\(\s*VERSION\s+([0-9.]+)\s*\)/i', $cmake_content, $matches)) {
                $version = $matches[1];
            }
        }

        $pc_content = <<<EOF
prefix={$work_dir}/buildroot
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

    /**
     * 彻底禁用测试
     */
    protected function disableTesting(): void
    {
        $cmake_file = $this->source_dir . '/CMakeLists.txt';
        if (!file_exists($cmake_file)) {
            return;
        }

        $content = file_get_contents($cmake_file);
        $original = $content;

        // 1. 注释掉 find_package(GTest ...) 行
        $content = preg_replace('/find_package\s*\(\s*GTest.*?\)/s', '# $0', $content);

        // 2. 注释掉 enable_testing() 行
        $content = str_replace('enable_testing()', '# enable_testing()', $content);

        // 3. 注释掉 add_subdirectory(test) 行
        $content = preg_replace('/add_subdirectory\s*\(\s*test\s*\)/', '# add_subdirectory(test)', $content);

        // 4. 注释掉所有 WITH_TESTING 相关的条件块
        $content = preg_replace('/if\s*\(\s*WITH_TESTING.*?endif\s*\(\)/s', '# $0', $content);

        if ($content !== $original) {
            file_put_contents($cmake_file, $content);
            echo "[I] Disabled testing in main CMakeLists.txt\n";
        }

        // 5. 如果存在 test 目录，重命名它
        $test_dir = $this->source_dir . '/test';
        if (is_dir($test_dir)) {
            rename($test_dir, $test_dir . '.disabled');
            echo "[I] Renamed test directory\n";
        }
    }
}
