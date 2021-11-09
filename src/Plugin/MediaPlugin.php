<?php

namespace Huozi\LaravelFilesystemWxwork\Plugin;

use League\Flysystem\Plugin\AbstractPlugin;

class MediaPlugin extends AbstractPlugin
{
    /**
     * Get the method name.
     *
     * @return string
     */
    public function getMethod()
    {
        return 'media';
    }

    public function handle($path)
    {
        return call_user_func([$this->filesystem->getAdapter(), 'getMediaId'], $path);
    }
}
