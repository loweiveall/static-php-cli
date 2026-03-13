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
                -DCMAKE_POSITION_INDEPENDENT_CODE=ON")
            ->exec("make -j{$this->builder->concurrency}")
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
        $target_lib = BUILD_LIB_PATH . '/libcjson.a';

        // 如果 make install 没有复制库，手动查找并复制
        if (!file_exists($target_lib)) {
            // 在构建目录中查找
            $possible_libs = [
                $this->source_dir . '/build/libcjson.a',
                $this->source_dir . '/build/lib/libcjson.a',
                $this->source_dir . '/libcjson.a',
            ];

            $found = false;
            foreach ($possible_libs as $lib) {
                if (file_exists($lib)) {
                    copy($lib, $target_lib);
                    echo "[I] Copied cJSON static lib from {$lib}\n";
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // 使用 find 命令搜索
                $result = shell()->cd($this->source_dir)
                    ->exec("find . -name 'libcjson.a' -type f | head -1")
                    ->getOutput();
                $lib_file = trim($result);
                if (!empty($lib_file)) {
                    copy($lib_file, $target_lib);
                    echo "[I] Found and copied cJSON static lib from {$lib_file}\n";
                } else {
                    throw new \RuntimeException('libcjson.a not found after build!');
                }
            }
        }

        // 确保头文件存在
        if (!file_exists(BUILD_INCLUDE_PATH . '/cJSON.h')) {
            $possible_headers = [
                $this->source_dir . '/cJSON.h',
                $this->source_dir . '/build/cJSON.h',
                $this->source_dir . '/include/cJSON.h',
            ];

            $found = false;
            foreach ($possible_headers as $header) {
                if (file_exists($header)) {
                    copy($header, BUILD_INCLUDE_PATH . '/cJSON.h');
                    echo "[I] Copied cJSON header from {$header}\n";
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $result = shell()->cd($this->source_dir)
                    ->exec("find . -name 'cJSON.h' -type f | head -1")
                    ->getOutput();
                $header_file = trim($result);
                if (!empty($header_file)) {
                    copy($header_file, BUILD_INCLUDE_PATH . '/cJSON.h');
                    echo "[I] Found and copied cJSON header from {$header_file}\n";
                } else {
                    throw new \RuntimeException('cJSON.h not found after build!');
                }
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
