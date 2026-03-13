<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\store\FileSystem;

trait cjson
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
            ->exec("{$this->builder->getOption('configure_env')} cmake .. \
                -DBUILD_SHARED_LIBS=OFF \
                -DCMAKE_INSTALL_PREFIX={$this->builder->getOption('work_dir')}/buildroot \
                -DCMAKE_BUILD_TYPE=Release \
                -DENABLE_CJSON_TEST=OFF \
                -DENABLE_CJSON_UTILS=OFF \
                -DBUILD_SHARED_AND_STATIC_LIBS=OFF \
                -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
                -DCJSON_BUILD_SHARED_LIBS=OFF \
                -DCJSON_OVERRIDE_BUILD_SHARED_LIBS=OFF")
            ->exec("make -j{$this->builder->concurrency} cjson-static")
            ->exec('make install');

        // 确保静态库被正确复制
        $this->ensureStaticLib();

        // 生成 pkg-config 文件
        $this->patchPkgconf();
    }

    /**
     * 确保静态库存在
     */
    protected function ensureStaticLib(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $source_lib = $this->source_dir . '/build/libcjson.a';
        $target_lib = BUILD_LIB_PATH . '/libcjson.a';

        // 如果 cmake install 没有复制库，手动复制
        if (!file_exists($target_lib)) {
            if (file_exists($source_lib)) {
                copy($source_lib, $target_lib);
                echo "[I] Manually copied libcjson.a\n";
            } else {
                // 尝试查找编译出的库
                $find_result = shell()->cd($this->source_dir)->exec("find . -name 'libcjson.a' -type f")->getOutput();
                if (!empty($find_result)) {
                    $lib_file = trim(explode("\n", $find_result)[0]);
                    copy($lib_file, $target_lib);
                    echo "[I] Found and copied libcjson.a from {$lib_file}\n";
                } else {
                    throw new \RuntimeException('libcjson.a not found!');
                }
            }
        }

        // 确保头文件存在
        if (!file_exists(BUILD_INCLUDE_PATH . '/cJSON.h')) {
            $source_header = $this->source_dir . '/cJSON.h';
            if (file_exists($source_header)) {
                copy($source_header, BUILD_INCLUDE_PATH . '/cJSON.h');
            }
        }
    }

    protected function patchPkgconf(): void
    {
        $work_dir = $this->builder->getOption('work_dir');

        $pc_content = <<<EOF
prefix={$work_dir}/buildroot
exec_prefix=\${prefix}
libdir=\${exec_prefix}/lib
includedir=\${prefix}/include

Name: cJSON
Description: Ultralightweight JSON parser in ANSI C
Version: 1.7.18
Libs: -L\${libdir} -lcjson
Cflags: -I\${includedir}

EOF;

        if (!is_dir(BUILD_LIB_PATH . '/pkgconfig')) {
            mkdir(BUILD_LIB_PATH . '/pkgconfig', 0755, true);
        }

        file_put_contents(BUILD_LIB_PATH . '/pkgconfig/libcjson.pc', $pc_content);
        file_put_contents(BUILD_LIB_PATH . '/pkgconfig/cjson.pc', $pc_content);
    }
}
