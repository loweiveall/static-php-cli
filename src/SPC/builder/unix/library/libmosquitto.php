<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\store\FileSystem;

trait libmosquitto
{
    protected function build(): void
    {
        // 创建构建目录
        if (!is_dir($this->source_dir . '/build')) {
            mkdir($this->source_dir . '/build', 0755, true);
        }

        // 进入构建目录
        shell()->cd($this->source_dir . '/build')
            ->exec('rm -rf *')
            ->exec("{$this->builder->configure_env} cmake .. \
                -DBUILD_SHARED_LIBS=OFF \
                -DCMAKE_INSTALL_PREFIX={$this->builder->work_dir}/buildroot \
                -DCMAKE_BUILD_TYPE=Release \
                -DWITH_STATIC_LIBRARIES=ON \
                -DWITH_SHARED_LIBRARIES=OFF \
                -DWITH_TLS=ON \
                -DWITH_WEBSOCKETS=OFF \
                -DWITH_SRV=OFF \
                -DDOCUMENTATION=OFF \
                -DWITH_DOCS=OFF \
                -DPOSITION_INDEPENDENT_CODE=ON \
                -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
                -DWITH_CJSON=ON \
                -DWITH_STRIP=OFF")
            ->exec("make -j{$this->builder->concurrency}")
            ->exec('make install');

        // 复制头文件到正确位置（确保所有头文件都在 include 目录下）
        $this->copyHeaderFiles();

        // 生成 pkg-config 文件
        $this->patchPkgconf();
    }

    /**
     * 复制头文件到正确位置
     */
    protected function copyHeaderFiles(): void
    {
        // 检查头文件是否已经通过 make install 安装
        if (!file_exists(BUILD_INCLUDE_PATH . '/mosquitto.h')) {
            // 尝试从源码目录复制
            $source_include = $this->source_dir . '/include';
            if (is_dir($source_include)) {
                FileSystem::copyDir($source_include, BUILD_INCLUDE_PATH);
            }

            // 尝试从构建目录复制
            $build_include = $this->source_dir . '/build/lib/include';
            if (is_dir($build_include) && !file_exists(BUILD_INCLUDE_PATH . '/mosquitto.h')) {
                FileSystem::copyDir($build_include, BUILD_INCLUDE_PATH);
            }
        }

        // 确保 mosquitto.h 存在
        if (!file_exists(BUILD_INCLUDE_PATH . '/mosquitto.h')) {
            // 如果还是没有，尝试查找并复制
            $find_result = shell()->exec("find {$this->source_dir} -name 'mosquitto.h' -type f")->getOutput();
            if (!empty($find_result)) {
                $header_file = trim(explode("\n", $find_result)[0]);
                if (file_exists($header_file)) {
                    copy($header_file, BUILD_INCLUDE_PATH . '/mosquitto.h');
                }
            }
        }
    }

    protected function patchPkgconf(): void
    {
        // 获取版本号
        $version = '2.0.18'; // 默认版本

        // 尝试从源码中获取版本号
        if (file_exists($this->source_dir . '/CMakeLists.txt')) {
            $cmake_content = file_get_contents($this->source_dir . '/CMakeLists.txt');
            if (preg_match('/set\s*\(\s*VERSION\s+([0-9.]+)\s*\)/i', $cmake_content, $matches)) {
                $version = $matches[1];
            }
        }

        // 生成 pkg-config 文件
        $pc_content = <<<EOF
prefix={$this->builder->work_dir}/buildroot
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

        // 确保 pkgconfig 目录存在
        if (!is_dir(BUILD_LIB_PATH . '/pkgconfig')) {
            mkdir(BUILD_LIB_PATH . '/pkgconfig', 0755, true);
        }

        file_put_contents(BUILD_LIB_PATH . '/pkgconfig/libmosquitto.pc', $pc_content);
    }
}
