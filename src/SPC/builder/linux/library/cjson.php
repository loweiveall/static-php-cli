<?php

declare(strict_types=1);

namespace SPC\builder\linux\library;

class cjson extends LinuxLibraryBase
{
    use \SPC\builder\unix\library\cjson;

    public const NAME = 'cjson';
}
