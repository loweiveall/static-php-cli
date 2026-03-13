<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\store\FileSystem;

trait libmosquitto
{
    protected function build(): void
    {
        // 直接使用 make 只编译 libmosquitto
        shell()->cd($this->source_dir)
            ->exec('make clean')
            ->exec("make WITH_TLS=yes WITH_CJSON=yes libmosquitto.a")
            ->exec('cp libmosquitto.a ' . BUILD_LIB_PATH . '/')
            ->exec('cp include/mosquitto.h ' . BUILD_INCLUDE_PATH . '/')
            ->exec('cp include/mosquitto_broker.h ' . BUILD_INCLUDE_PATH . '/ 2>/dev/null || true')
            ->exec('cp include/mqtt_protocol.h ' . BUILD_INCLUDE_PATH . '/ 2>/dev/null || true');

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
