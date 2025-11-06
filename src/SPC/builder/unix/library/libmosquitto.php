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
            '-DDOCUMENTATION=OFF',
            '-DWITH_DOCS=OFF'
        )->build();
    }
}
