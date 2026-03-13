<?php

declare(strict_types=1);

namespace SPC\builder\extension;

use SPC\builder\Extension;
use SPC\store\FileSystem;
use SPC\util\CustomExt;

#[CustomExt('mosquitto')]
class mosquitto extends Extension
{
    public function patchBeforeConfigure(): bool
    {
        // 在 configure 之前执行，确保修改生效
        $this->forceConfigurePatch();
        return true;
    }

    public function patchBeforeMake(): bool
    {
        $patched = parent::patchBeforeMake();

        // Windows 平台的特殊处理
        if (PHP_OS_FAMILY === 'Windows') {
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\php_mosquitto.h', '/^#warning.*/m', '');
            FileSystem::replaceFileRegex(BUILD_INCLUDE_PATH . '\mosquitto_private.h', '/^#warning.*/m', '');
            return true;
        }

        return $patched;
    }

    /**
     * 强制修改 configure 文件
     */
    protected function forceConfigurePatch(): void
    {
        $work_dir = $this->builder->getOption('work_dir');
        $configure_file = $this->source_dir . '/configure';
        $config_m4 = $this->source_dir . '/config.m4';

        // 方法1: 直接修改 configure 文件（最可靠）
        if (file_exists($configure_file)) {
            $content = file_get_contents($configure_file);

            // 在文件中插入我们的路径检查
            $search = 'if test "$PHP_MOSQUITTO" != "no"; then';
            $replace = $search . "\n\n" . <<<EOF
  dnl 直接设置路径
  MOSQUITTO_LIBDIR={$work_dir}/buildroot/lib
  MOSQUITTO_INCDIR={$work_dir}/buildroot/include

  dnl 添加头文件路径
  CPPFLAGS="\$CPPFLAGS -I\$MOSQUITTO_INCDIR"
  LDFLAGS="\$LDFLAGS -L\$MOSQUITTO_LIBDIR"

  dnl 检查库是否存在
  { $as_echo "$as_me:${as_lineno-$LINENO}: checking for mosquitto library" >&5
  $as_echo_n "checking for mosquitto library... " >&6; }
  if test -f "\$MOSQUITTO_LIBDIR/libmosquitto.a"; then
    { $as_echo "$as_me:${as_lineno-$LINENO}: result: found" >&5
  $as_echo "found" >&6; }
    MOSQUITTO_LIBS="-L\$MOSQUITTO_LIBDIR -lmosquitto"
  else
    { $as_echo "$as_me:${as_lineno-$LINENO}: result: not found" >&5
  $as_echo "not found" >&6; }
    as_fn_error \$? "Please reinstall the mosquitto distribution" "$LINENO" 5
  fi

EOF;

            $content = str_replace($search, $replace, $content);
            file_put_contents($configure_file, $content);
            echo "[I] Patched configure file directly\n";
        }

        // 方法2: 同时修改 config.m4 作为备份
        if (file_exists($config_m4)) {
            $new_config = <<<EOF
dnl config.m4 for mosquitto extension

PHP_ARG_WITH(mosquitto, for mosquitto support,
[  --with-mosquitto             Include mosquitto support])

if test "\$PHP_MOSQUITTO" != "no"; then
  MOSQUITTO_LIBDIR={$work_dir}/buildroot/lib
  MOSQUITTO_INCDIR={$work_dir}/buildroot/include

  PHP_ADD_INCLUDE(\$MOSQUITTO_INCDIR)
  PHP_ADD_LIBRARY_WITH_PATH(mosquitto, \$MOSQUITTO_LIBDIR, MOSQUITTO_SHARED_LIBADD)

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
            echo "[I] Rewrote config.m4\n";
        }

        // 方法3: 创建符号链接，让库在默认路径也能找到
        if (!file_exists('/usr/local/lib/libmosquitto.a')) {
            @mkdir('/usr/local/lib', 0755, true);
            @mkdir('/usr/local/include', 0755, true);
            shell()->exec("ln -sf {$work_dir}/buildroot/lib/libmosquitto.a /usr/local/lib/ 2>/dev/null || true");
            shell()->exec("ln -sf {$work_dir}/buildroot/include/mosquitto.h /usr/local/include/ 2>/dev/null || true");
            echo "[I] Created symlinks in /usr/local\n";
        }
    }

    public function getUnixConfigureArg(bool $shared = false): string
    {
        // 设置环境变量
        $work_dir = $this->builder->getOption('work_dir');

        putenv("PKG_CONFIG_PATH={$work_dir}/buildroot/lib/pkgconfig");
        putenv("CPPFLAGS=-I{$work_dir}/buildroot/include");
        putenv("LDFLAGS=-L{$work_dir}/buildroot/lib");
        putenv("CFLAGS=-I{$work_dir}/buildroot/include");

        return '--with-mosquitto' . ($shared ? '=shared' : '');
    }

    public function getWindowsConfigureArg($shared = false): string
    {
        return '--with-mosquitto';
    }
}
