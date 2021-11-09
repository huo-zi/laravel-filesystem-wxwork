<?php

namespace Huozi\LaravelFilesystemWxwork;

use Huozi\LaravelFilesystemWxwork\Plugin\MediaPlugin;
use Huozi\LaravelFilesystemWxwork\Plugin\WorkPlugin;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use League\Flysystem\Filesystem;

class ServiceProvider extends LaravelServiceProvider
{
    public function register()
    {
        ;
    }

    public function boot()
    {
        Storage::extend('wxwork', function ($app, $config) {
            $adapter = new WorkWechatAdapter($app, $config);

            $filesystem = new Filesystem($adapter);
            $filesystem->addPlugin(new WorkPlugin());
            $filesystem->addPlugin(new MediaPlugin());
            return $filesystem;
        });
    }
}