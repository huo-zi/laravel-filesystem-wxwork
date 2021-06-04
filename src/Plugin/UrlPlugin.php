<?php

namespace Huozi\LaravelFilesystemWxwork\Plugin;

use League\Flysystem\Plugin\AbstractPlugin;
use League\Flysystem\Util;

class UrlPlugin extends AbstractPlugin
{
    /**
     * Get the method name.
     *
     * @return string
     */
    public function getMethod()
    {
        return 'getUrl';
    }

    public function handle($path)
    {
        $path = Util::normalizePath($path);
        return $this->filesystem->getAdapter()->getUrl($path);
    }
}
