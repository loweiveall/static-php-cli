<?php

declare(strict_types=1);

namespace SPC\builder\unix\library;

use SPC\util\executor\UnixCMakeExecutor;

trait libmosquitto
{
    protected function build(): void
    {
        UnixCMakeExecutor::create($this)->addConfigureArgs(
            '-DWITH_STATIC_LIBRARIES=yes',
            '-DWITH_SHARED_LIBRARIES=no',
            '-DWITH_TLS=no',
            '-DWITH_WEBSOCKETS=no',
            '-DWITH_SRV=no',
            '-DWITH_DOCS=no'
        )->build();
    }
}
