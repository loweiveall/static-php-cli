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

    protected function ensureLibraryFiles(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $lib_dir = BUILD_LIB_PATH;  // /app/buildroot/lib
        $buildroot_lib = '/buildroot/lib';

        echo "[I] ===== DEBUG: ensureLibraryFiles() =====\n";
        echo "[I] work_dir: {$work_dir}\n";
        echo "[I] BUILD_LIB_PATH: " . BUILD_LIB_PATH . "\n";
        echo "[I] lib_dir: {$lib_dir}\n";
        echo "[I] buildroot_lib: {$buildroot_lib}\n";

        // 检查目录是否存在
        echo "[I] Checking if directories exist:\n";
        echo "[I] - lib_dir exists: " . (is_dir($lib_dir) ? 'YES' : 'NO') . "\n";
        echo "[I] - buildroot_lib exists: " . (is_dir($buildroot_lib) ? 'YES' : 'NO') . "\n";
        echo "[I] - {$work_dir}/buildroot/lib exists: " . (is_dir($work_dir . '/buildroot/lib') ? 'YES' : 'NO') . "\n";

        // 列出 lib_dir 的内容
        if (is_dir($lib_dir)) {
            $files = scandir($lib_dir);
            echo "[I] Files in {$lib_dir}:\n";
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    echo "[I]   - {$file}\n";
                }
            }
        }

        // 列出 /buildroot/lib 的内容
        if (is_dir($buildroot_lib)) {
            $files = scandir($buildroot_lib);
            echo "[I] Files in {$buildroot_lib}:\n";
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    echo "[I]   - {$file}\n";
                }
            }
        }

        // 列出 work_dir/buildroot/lib 的内容
        $work_buildroot_lib = $work_dir . '/buildroot/lib';
        if (is_dir($work_buildroot_lib)) {
            $files = scandir($work_buildroot_lib);
            echo "[I] Files in {$work_buildroot_lib}:\n";
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    echo "[I]   - {$file}\n";
                }
            }
        }

        // 检查所有可能的位置
        $possible_libs = [
            $lib_dir . '/libmosquitto_static.a',
            $lib_dir . '/libmosquitto.a',
            $buildroot_lib . '/libmosquitto_static.a',
            $buildroot_lib . '/libmosquitto.a',
            $work_dir . '/buildroot/lib/libmosquitto_static.a',
            $work_dir . '/buildroot/lib/libmosquitto.a',
            '/buildroot/lib/libmosquitto_static.a',
            '/buildroot/lib/libmosquitto.a',
        ];

        $found = false;
        foreach ($possible_libs as $lib) {
            $exists = file_exists($lib);
            echo "[I] Checking {$lib}: " . ($exists ? 'FOUND' : 'not found') . "\n";

            if ($exists && !$found) {
                echo "[I] Found mosquitto static library at: {$lib}\n";

                // 确保在标准位置也有库文件
                if (!file_exists($lib_dir . '/libmosquitto.a')) {
                    copy($lib, $lib_dir . '/libmosquitto.a');
                    echo "[I] Copied to {$lib_dir}/libmosquitto.a\n";
                }

                if (!file_exists($lib_dir . '/libmosquitto_static.a')) {
                    copy($lib, $lib_dir . '/libmosquitto_static.a');
                    echo "[I] Copied to {$lib_dir}/libmosquitto_static.a\n";
                }

                $found = true;
            }
        }

        if (!$found) {
            // 最后尝试使用 find 命令搜索
            echo "[I] Attempting to find with find command...\n";
            $find_result = shell()->cd($work_dir)
                ->exec("find . -name 'libmosquitto*.a' -type f 2>/dev/null")
                ->getOutput();

            if (!empty($find_result)) {
                echo "[I] Find results:\n" . $find_result . "\n";
                $first_found = trim(explode("\n", $find_result)[0]);
                if (file_exists($first_found)) {
                    copy($first_found, $lib_dir . '/libmosquitto.a');
                    copy($first_found, $lib_dir . '/libmosquitto_static.a');
                    echo "[I] Copied from {$first_found}\n";
                    $found = true;
                }
            }
        }

        if (!$found) {
            throw new \RuntimeException('No mosquitto static library found after all attempts!');
        }

        echo "[I] ===== DEBUG END =====\n";
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

        // 创建完整的目录结构
        $this->createCompleteIncludeStructure();
    }

    /**
     * 创建完整的 include 目录结构
     */
    protected function createCompleteIncludeStructure(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $include_path = BUILD_INCLUDE_PATH;

        // 1. 确保 mosquitto 子目录存在
        if (!is_dir($include_path . '/mosquitto')) {
            mkdir($include_path . '/mosquitto', 0755, true);
        }

        // 2. 将所有 .h 文件复制到 mosquitto 子目录
        $headers = glob($include_path . '/*.h');
        foreach ($headers as $header) {
            $basename = basename($header);
            $target = $include_path . '/mosquitto/' . $basename;
            if (!file_exists($target)) {
                copy($header, $target);
                echo "[I] Copied {$basename} to mosquitto subdirectory\n";
            }
        }

        // 3. 特别确保 mqtt_protocol.h 存在
        $mqtt_sources = [
            $this->source_dir . '/include/mqtt_protocol.h',
            $this->source_dir . '/lib/mqtt_protocol.h',
            $include_path . '/mqtt_protocol.h',
        ];

        foreach ($mqtt_sources as $source) {
            if (file_exists($source)) {
                copy($source, $include_path . '/mqtt_protocol.h');
                copy($source, $include_path . '/mosquitto/mqtt_protocol.h');
                echo "[I] Ensured mqtt_protocol.h exists\n";
                break;
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
