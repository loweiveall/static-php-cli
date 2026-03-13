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
                -DENABLE_CJSON_UTILS=ON \
                -DENABLE_TARGET_EXPORT=OFF \
                -DBUILD_SHARED_AND_STATIC_LIBS=OFF \
                -DCMAKE_POSITION_INDEPENDENT_CODE=ON")
            ->exec("make -j{$this->builder->concurrency}")
            ->exec('make install');

        // 生成 pkg-config 文件
        $this->patchPkgconf();
    }

    protected function patchPkgconf(): void
    {
        $pc_content = <<<EOF
prefix={$this->builder->getOption('work_dir')}/buildroot
exec_prefix=\${prefix}
libdir=\${exec_prefix}/lib
includedir=\${prefix}/include

Name: cJSON
Description: Ultralightweight JSON parser in ANSI C
Version: 1.7.18
Libs: -L\${libdir} -lcjson
Cflags: -I\${includedir}/cjson

EOF;

        if (!is_dir(BUILD_LIB_PATH . '/pkgconfig')) {
            mkdir(BUILD_LIB_PATH . '/pkgconfig', 0755, true);
        }

        file_put_contents(BUILD_LIB_PATH . '/pkgconfig/libcjson.pc', $pc_content);
        file_put_contents(BUILD_LIB_PATH . '/pkgconfig/cjson.pc', $pc_content);
    }
}
