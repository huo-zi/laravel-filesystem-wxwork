<?php

namespace Huozi\LaravelFilesystemWxwork;

use Illuminate\Filesystem\Cache AS FilesystemCache;

class Cache extends FilesystemCache
{
    /**
     * @override
     */
    public function read($path)
    {
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        return false;
    }
}
