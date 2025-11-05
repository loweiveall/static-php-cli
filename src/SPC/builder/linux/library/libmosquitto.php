<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

class libmosquitto extends LinuxLibraryBase
{
    use \SPC\builder\unix\library\libmosquitto;

    public const NAME = 'libmosquitto';
}
