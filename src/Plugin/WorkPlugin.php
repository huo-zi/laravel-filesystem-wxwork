<?php

namespace Huozi\LaravelFilesystemWxwork\Plugin;

use League\Flysystem\Plugin\AbstractPlugin;

class WorkPlugin extends AbstractPlugin
{
    /**
     * Get the method name.
     *
     * @return string
     */
    public function getMethod()
    {
        return 'work';
    }

    public function handle($work)
    {
        $this->filesystem->getAdapter()->work($work);
    }
}
