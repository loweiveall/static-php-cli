<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\store\FileSystem;

trait libmosquitto
{
    protected function build(): void
    {
        // 1. 确保cJSON头文件存在
        $this->ensureCjsonHeaders();

        // 2. 修复cJSON包含路径
        $this->fixCjsonIncludes();

        // 3. 精确修改CMakeLists.txt
        $this->patchCMakeLists();

        // 4. 特别修改 lib/CMakeLists.txt
        $this->patchLibCMakeLists();

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
                -DCMAKE_POSITION_INDEPENDENT_CODE=ON")
            ->exec("make -j{$this->builder->concurrency}")
            ->exec('make install');

        // 复制头文件
        $this->copyHeaderFiles();

        // 生成pkg-config文件
        $this->patchPkgconf();
    }

    /**
     * 精确修改 lib/CMakeLists.txt
     */
    protected function patchLibCMakeLists(): void
    {
        $lib_cmake = $this->source_dir . '/lib/CMakeLists.txt';
        if (file_exists($lib_cmake)) {
            $content = file_get_contents($lib_cmake);
            $original = $content;

            // 注释掉第2行的 add_subdirectory(cpp)
            $lines = explode("\n", $content);
            if (isset($lines[1]) && strpos($lines[1], 'add_subdirectory(cpp)') !== false) {
                $lines[1] = '# ' . $lines[1];
                $content = implode("\n", $lines);
            } else {
                // 如果不在第2行，就全局替换
                $content = str_replace('add_subdirectory(cpp)', '# add_subdirectory(cpp)', $content);
            }

            if ($content !== $original) {
                file_put_contents($lib_cmake, $content);
                echo "[I] Patched lib/CMakeLists.txt\n";
            }
        }

        // 确保cpp目录不存在
        $cpp_dir = $this->source_dir . '/lib/cpp';
        if (is_dir($cpp_dir)) {
            rename($cpp_dir, $cpp_dir . '.disabled');
            echo "[I] Disabled cpp directory\n";
        }
    }

    /**
     * 修改主CMakeLists.txt
     */
    protected function patchCMakeLists(): void
    {
        $cmake_file = $this->source_dir . '/CMakeLists.txt';
        if (!file_exists($cmake_file)) {
            return;
        }

        $content = file_get_contents($cmake_file);
        $original = $content;

        // 注释掉GTest查找
        $content = preg_replace('/find_package\s*\(\s*GTest.*?\)/s', '# $0', $content);

        // 注释掉测试相关块
        $content = preg_replace('/if\s*\(\s*WITH_TESTING.*?endif\s*\(\)/s', '# $0', $content);

        // 注释掉enable_testing
        $content = str_replace('enable_testing()', '# enable_testing()', $content);

        // 注释掉test子目录
        $content = preg_replace('/add_subdirectory\s*\(\s*test\s*\)/', '# add_subdirectory(test)', $content);

        if ($content !== $original) {
            file_put_contents($cmake_file, $content);
            echo "[I] Patched main CMakeLists.txt\n";
        }

        // 重命名test目录
        $test_dir = $this->source_dir . '/test';
        if (is_dir($test_dir)) {
            rename($test_dir, $test_dir . '.disabled');
            echo "[I] Disabled test directory\n";
        }
    }

    /**
     * 确保cJSON头文件存在
     */
    protected function ensureCjsonHeaders(): void
    {
        // 从cjson源码目录复制头文件
        $cjson_source = dirname($this->source_dir) . '/cjson';
        if (file_exists($cjson_source . '/cJSON.h')) {
            if (!is_dir(BUILD_INCLUDE_PATH)) {
                mkdir(BUILD_INCLUDE_PATH, 0755, true);
            }
            copy($cjson_source . '/cJSON.h', BUILD_INCLUDE_PATH . '/cJSON.h');
            echo "[I] Copied cJSON.h from cjson source\n";
        }

        // 确保cjson子目录存在
        if (!is_dir(BUILD_INCLUDE_PATH . '/cjson')) {
            mkdir(BUILD_INCLUDE_PATH . '/cjson', 0755, true);
        }

        // 复制到cjson子目录
        if (file_exists(BUILD_INCLUDE_PATH . '/cJSON.h')) {
            copy(BUILD_INCLUDE_PATH . '/cJSON.h', BUILD_INCLUDE_PATH . '/cjson/cJSON.h');
        }

        if (!file_exists(BUILD_INCLUDE_PATH . '/cJSON.h')) {
            throw new \RuntimeException('cJSON.h not found! Please ensure cjson is built first.');
        }
    }

    /**
     * 修复cJSON头文件引用
     */
    protected function fixCjsonIncludes(): void
    {
        echo "[I] Fixing cJSON includes in mosquitto source...\n";

        shell()->cd($this->source_dir)
            ->exec("find . -name '*.c' -o -name '*.h' -o -name '*.cpp' | xargs sed -i 's/#include <cjson\\/cJSON.h>/#include <cJSON.h>/g' 2>/dev/null || true")
            ->exec("find . -name '*.c' -o -name '*.h' -o -name '*.cpp' | xargs sed -i 's/#include \"cjson\\/cJSON.h\"/#include \"cJSON.h\"/g' 2>/dev/null || true");
    }

    protected function copyHeaderFiles(): void
    {
        if (!is_dir(BUILD_INCLUDE_PATH)) {
            mkdir(BUILD_INCLUDE_PATH, 0755, true);
        }

        $source_include = $this->source_dir . '/include';
        if (is_dir($source_include)) {
            FileSystem::copyDir($source_include, BUILD_INCLUDE_PATH);
        }

        $install_include = $this->builder->getOption('work_dir') . '/buildroot/include';
        if (is_dir($install_include)) {
            FileSystem::copyDir($install_include, BUILD_INCLUDE_PATH);
        }
    }

    protected function patchPkgconf(): void
    {
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
