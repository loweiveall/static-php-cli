<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\util\executor\UnixCMakeExecutor;

trait cjson
{
    protected function build(): void
    {
        // 进入源码目录
        shell()->cd($this->source_dir)
            ->exec('make clean > /dev/null 2>&1')
            ->exec("{$this->builder->configure_env} cmake . \
                -DBUILD_SHARED_LIBS=OFF \
                -DCMAKE_INSTALL_PREFIX={$this->builder->work_dir}/buildroot \
                -DCMAKE_BUILD_TYPE=Release \
                -DENABLE_CJSON_TEST=OFF \
                -DENABLE_CJSON_UTILS=ON \
                -DENABLE_TARGET_EXPORT=OFF \
                -DBUILD_SHARED_AND_STATIC_LIBS=OFF \
                -DPOSITION_INDEPENDENT_CODE=ON")
            ->exec("make -j{$this->builder->concurrency}")
            ->exec('make install');

        // 生成 pkg-config 文件
        $this->patchPkgconf();
    }

    protected function patchPkgconf(): void
    {
        $pc_content = <<<EOF
prefix={$this->builder->work_dir}/buildroot
exec_prefix=\${prefix}
libdir=\${exec_prefix}/lib
includedir=\${prefix}/include

Name: cJSON
Description: Ultralightweight JSON parser in ANSI C
Version: 1.7.18
Libs: -L\${libdir} -lcjson
Cflags: -I\${includedir}/cjson

EOF;
        file_put_contents(BUILD_LIB_PATH . '/pkgconfig/libcjson.pc', $pc_content);
        file_put_contents(BUILD_LIB_PATH . '/pkgconfig/cjson.pc', $pc_content);
    }
}
