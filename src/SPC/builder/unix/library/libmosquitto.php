<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\util\executor\UnixCMakeExecutor;

trait libmosquitto
{
    protected function build(): void
    {
        UnixCMakeExecutor::create($this)->addConfigureArgs(
            '-DWITH_STATIC_LIBRARIES=ON',
            '-DWITH_SHARED_LIBRARIES=OFF',
            '-DWITH_TLS=OFF',
            '-DWITH_WEBSOCKETS=OFF',
            '-DWITH_SRV=OFF',
            '-DWITH_CLIENTS=OFF',
            '-DWITH_BROKER=OFF'
        )->build();
    }
}
