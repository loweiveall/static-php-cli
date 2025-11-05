<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\util\executor\UnixCMakeExecutor;

trait libmosquitto
{
    protected function build(): void
    {
        UnixCMakeExecutor::create($this)->addConfigureArgs(
            '-DBUILD_STATIC_LIBS=ON',
            '-DWITH_STATIC_LIBRARIES=ON',
            '-DWITH_SHARED_LIBRARIES=OFF'
        )->build();
    }
}
