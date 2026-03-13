<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\util\executor\UnixCMakeExecutor;

trait libmosquitto
{
    protected function build(): void
    {
        // 进入构建目录
        UnixCMakeExecutor::create($this)
            ->addConfigureArgs(
                '-DWITH_STATIC_LIBRARIES=ON',
                '-DWITH_SHARED_LIBRARIES=OFF',
                '-DWITH_TLS=OFF',
                '-DWITH_WEBSOCKETS=OFF',
                '-DWITH_SRV=OFF',
                '-DDOCUMENTATION=OFF',
                '-DWITH_DOCS=OFF'
            )->build();

    }

    /**
     * 手动禁用插件和任何可能引入 SQLite3 的组件
     */
    protected function disablePlugins(): void
    {
        // 禁用 plugins/CMakeLists.txt
        $plugins_cmake = $this->source_dir . '/plugins/CMakeLists.txt';
        if (file_exists($plugins_cmake)) {
            rename($plugins_cmake, $plugins_cmake . '.disabled');
        }

        // 修改根 CMakeLists.txt，注释掉插件子目录
        $root_cmake = $this->source_dir . '/CMakeLists.txt';
        if (file_exists($root_cmake)) {
            $content = file_get_contents($root_cmake);
            // 注释掉 plugins 子目录的添加
            $content = preg_replace('/add_subdirectory\(plugins\)/', '# add_subdirectory(plugins)', $content);
            // 也禁用其他可能引入 SQLite3 的组件
            $content = preg_replace('/add_subdirectory\(apps\)/', '# add_subdirectory(apps)', $content);
            file_put_contents($root_cmake, $content);
        }

        // 如果存在 dynamic-security 插件目录，直接重命名
        $dynamic_security = $this->source_dir . '/plugins/dynamic-security';
        if (is_dir($dynamic_security)) {
            rename($dynamic_security, $dynamic_security . '.disabled');
        }
    }

    /**
     * 复制头文件到正确位置
     */
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
