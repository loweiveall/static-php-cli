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

        // Unix/Linux 平台：彻底修改 config.m4
        $this->overwriteConfigM4();

        return $patched;
    }

    /**
     * 完全重写 config.m4 文件
     */
    protected function overwriteConfigM4(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $config_m4 = $this->source_dir . '/config.m4';

        // 创建新的 config.m4 内容
        $new_config = <<<EOF
dnl config.m4 for mosquitto extension

PHP_ARG_WITH(mosquitto, for mosquitto support,
[  --with-mosquitto             Include mosquitto support])

if test "\$PHP_MOSQUITTO" != "no"; then
  dnl 直接设置库和头文件路径
  MOSQUITTO_LIBDIR={$work_dir}/buildroot/lib
  MOSQUITTO_INCDIR={$work_dir}/buildroot/include

  dnl 添加头文件路径
  PHP_ADD_INCLUDE(\$MOSQUITTO_INCDIR)

  dnl 添加库路径
  PHP_ADD_LIBRARY_WITH_PATH(mosquitto, \$MOSQUITTO_LIBDIR, MOSQUITTO_SHARED_LIBADD)

  dnl 检查库是否存在
  PHP_CHECK_LIBRARY(mosquitto, mosquitto_lib_version, [
    AC_DEFINE(HAVE_MOSQUITTO, 1, [ ])
  ], [
    AC_MSG_ERROR([mosquitto library not found. Please install libmosquitto])
  ], [
    -L\$MOSQUITTO_LIBDIR
  ])

  PHP_NEW_EXTENSION(mosquitto, mosquitto.c, \$ext_shared)
  PHP_SUBST(MOSQUITTO_SHARED_LIBADD)
fi
EOF;

        file_put_contents($config_m4, $new_config);
        echo "[I] Completely rewrote mosquitto config.m4\n";
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        // 设置环境变量，但主要依赖修改后的 config.m4
        $work_dir = $this->builder->getOption('work_dir');

        putenv("PKG_CONFIG_PATH={$work_dir}/buildroot/lib/pkgconfig");
        putenv("CFLAGS=-I{$work_dir}/buildroot/include");
        putenv("LDFLAGS=-L{$work_dir}/buildroot/lib");

        return '--with-mosquitto' . ($shared ? '=shared' : '');
    }

    public function getWindowsConfigureArg($shared = false): string
    {
        return '--with-mosquitto';
    }
}
