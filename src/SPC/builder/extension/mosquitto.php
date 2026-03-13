<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('mosquitto')]
class mosquitto extends Extension
{
    public function patchBeforeMake(): bool
    {
        $patched = parent::patchBeforeMake();

        // Windows 平台的特殊处理
        if (PHP_OS_FAMILY === 'Windows') {
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\php_mosquitto.h', '/^#warning.*/m', '');
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\mosquitto_private.h', '/^#warning.*/m', '');
            return true;
        }

        // Unix/Linux 平台：确保头文件路径正确
        if (!file_exists(BUILD_INCLUDE_PATH . '/mosquitto.h')) {
            // 查找 mosquitto.h 的位置
            $find_result = shell()->exec("find {$this->builder->getOption('work_dir')}/buildroot -name 'mosquitto.h' -type f")->getOutput();
            if (!empty($find_result)) {
                $header_file = trim(explode("\n", $find_result)[0]);
                copy($header_file, BUILD_INCLUDE_PATH . '/mosquitto.h');
            }
        }

        // 修改扩展的 config.m4，让它知道库的位置
        $this->patchConfigM4();

        return $patched;
    }

    /**
     * 修改 config.m4 文件，添加库路径
     */
    protected function patchConfigM4(): void
    {
        $config_m4 = $this->source_dir . '/config.m4';
        if (!file_exists($config_m4)) {
            return;
        }

        $content = file_get_contents($config_m4);
        $original = $content;

        // 在库搜索部分添加我们的路径
        $search_pattern = '/PHP_CHECK_LIBRARY\(mosquitto/';
        $replacement = 'PHP_ADD_LIBRARY_WITH_PATH(mosquitto, ' . BUILD_LIB_PATH . ', MOSQUITTO_SHARED_LIBADD)' . "\n" .
            '  PHP_ADD_INCLUDE(' . BUILD_INCLUDE_PATH . ')' . "\n" .
            '  $0';

        $content = preg_replace($search_pattern, $replacement, $content);

        // 如果上面的替换没生效，尝试另一种方式
        if ($content === $original) {
            // 在文件开头添加路径定义
            $add_paths = <<<EOF
dnl Add our build paths
PHP_ADD_LIBPATH({$this->builder->getOption('work_dir')}/buildroot/lib, MOSQUITTO_SHARED_LIBADD)
PHP_ADD_INCLUDE({$this->builder->getOption('work_dir')}/buildroot/include)

EOF;
            $content = $add_paths . $content;
        }

        if ($content !== $original) {
            file_put_contents($config_m4, $content);
            echo "[I] Patched mosquitto config.m4\n";
        }
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        // 对于 mosquitto 扩展，我们使用环境变量来传递库路径
        $work_dir = $this->builder->getOption('work_dir');

        // 设置环境变量让 configure 能找到库
        putenv("CPPFLAGS=-I{$work_dir}/buildroot/include");
        putenv("LDFLAGS=-L{$work_dir}/buildroot/lib");
        putenv("PKG_CONFIG_PATH={$work_dir}/buildroot/lib/pkgconfig");

        // 返回标准参数
        return '--with-mosquitto' . ($shared ? '=shared' : '');
    }

    public function getWindowsConfigureArg($shared = false): string
    {
        return '--with-mosquitto';
    }
}
