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

        // 首先尝试直接修改 CMakeLists.txt 禁用插件
        $this->patchCMakeForPlugin();

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
            -DWITH_APPS=OFF \
            -DWITH_PERSISTENCE=OFF \
            -DCMAKE_POSITION_INDEPENDENT_CODE=ON")
            ->exec("make -j{$this->builder->concurrency} libmosquitto")
            ->exec('make install');

        // 复制头文件
        $this->copyHeaderFiles();

        // 生成 pkg-config 文件
        $this->patchPkgconf();
    }

    /**
     * 手动修改 CMakeLists.txt 禁用插件
     */
    protected function patchCMakeForPlugin(): void
    {
        $cmake_file = $this->source_dir . '/CMakeLists.txt';
        if (file_exists($cmake_file)) {
            $content = file_get_contents($cmake_file);
            // 注释掉 plugins 子目录的添加
            $content = preg_replace('/add_subdirectory\(plugins\)/', '# add_subdirectory(plugins)', $content);
            file_put_contents($cmake_file, $content);
        }

        // 如果 plugins/CMakeLists.txt 存在，也修改它
        $plugins_cmake = $this->source_dir . '/plugins/CMakeLists.txt';
        if (file_exists($plugins_cmake)) {
            // 重命名或删除，阻止编译
            rename($plugins_cmake, $plugins_cmake . '.bak');
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

        // 从构建产物目录复制（某些版本可能安装到这里）
        $build_include = $this->source_dir . '/build/lib/include';
        if (is_dir($build_include)) {
            FileSystem::copyDir($build_include, BUILD_INCLUDE_PATH);
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
