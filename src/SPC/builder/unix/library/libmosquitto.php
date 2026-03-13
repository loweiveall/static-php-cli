<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\util\executor\MosquittoCMakeExecutor;

trait libmosquitto
{
    protected function build(): void
    {
        // 强制禁用测试（直接修改 CMakeLists.txt）
        $this->forceDisableTests();

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
            ->exec("make -j{$this->builder->concurrency} mosquitto_static || make -j{$this->builder->concurrency}")
            ->exec('make install');

        // 复制头文件
        $this->copyHeaderFiles();

        // 生成 pkg-config 文件
        $this->patchPkgconf();
    }

    /**
     * 强制禁用测试 - 直接修改 CMakeLists.txt
     */
    protected function forceDisableTests(): void
    {
        $cmake_file = $this->source_dir . '/CMakeLists.txt';
        if (file_exists($cmake_file)) {
            $content = file_get_contents($cmake_file);

            // 1. 注释掉 find_package(GTest ...) 行
            $content = preg_replace('/find_package\s*\(\s*GTest.*?\)/m', '# $0', $content);

            // 2. 注释掉 enable_testing() 行
            $content = preg_replace('/enable_testing\s*\(\)/m', '# $0', $content);

            // 3. 注释掉 add_subdirectory(test) 行
            $content = preg_replace('/add_subdirectory\s*\(\s*test\s*\)/m', '# $0', $content);

            // 4. 查找并注释掉任何包含 "test" 或 "gtest" 的条件块
            $lines = explode("\n", $content);
            $in_test_block = false;
            $new_lines = [];

            foreach ($lines as $line) {
                // 如果进入测试相关的 if 块
                if (preg_match('/if\s*\(\s*WITH_TESTING\s*\)/i', $line) ||
                    preg_match('/if\s*\(\s*WITH_UNIT_TESTS\s*\)/i', $line)) {
                    $in_test_block = true;
                    $new_lines[] = '# ' . $line;  // 注释掉 if 行
                    continue;
                }

                // 如果在测试块内
                if ($in_test_block) {
                    $new_lines[] = '# ' . $line;  // 注释掉块内所有行
                    if (preg_match('/endif\s*\(.*\)/i', $line)) {
                        $in_test_block = false;  // 结束测试块
                    }
                    continue;
                }

                // 正常行
                $new_lines[] = $line;
            }

            $content = implode("\n", $new_lines);
            file_put_contents($cmake_file, $content);

            echo "[I] Modified CMakeLists.txt to disable tests\n";
        }

        // 如果存在 test 目录，直接重命名
        $test_dir = $this->source_dir . '/test';
        if (is_dir($test_dir)) {
            rename($test_dir, $test_dir . '.disabled');
            echo "[I] Disabled test directory\n";
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
            \SPC\store\FileSystem::copyDir($source_include, BUILD_INCLUDE_PATH);
        }

        // 从构建产物目录复制
        $build_include = $this->source_dir . '/build/lib/include';
        if (is_dir($build_include)) {
            \SPC\store\FileSystem::copyDir($build_include, BUILD_INCLUDE_PATH);
        }

        // 从安装目录复制
        $install_include = $this->builder->getOption('work_dir') . '/buildroot/include';
        if (is_dir($install_include)) {
            \SPC\store\FileSystem::copyDir($install_include, BUILD_INCLUDE_PATH);
        }

        // 确保关键头文件存在
        $key_headers = ['mosquitto.h', 'mqtt_protocol.h'];
        foreach ($key_headers as $header) {
            if (!file_exists(BUILD_INCLUDE_PATH . '/' . $header)) {
                $find_result = shell()->exec("find {$this->source_dir} -name '{$header}' -type f")->getOutput();
                if (!empty($find_result)) {
                    $header_file = trim(explode("\n", $find_result)[0]);
                    if (file_exists($header_file)) {
                        copy($header_file, BUILD_INCLUDE_PATH . '/' . $header);
                    }
                }
            }
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
