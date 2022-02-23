<?php

namespace Xjaqil\AliOSS;

use OSS\OssClient;
use League\Flysystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;


class ServiceProvider extends LaravelServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {

        Storage::extend('oss', function ($app, $config) {


            $accessKeyId = $config['access_id'];
            $accessKeySecret = $config['access_key'];
            $endpoint = $config['is_cname'] ? $config['cdn_domain'] : ($config['endpoint_internal'] ?? $config['endpoint']);
            $client = new OssClient($accessKeyId, $accessKeySecret, $endpoint, $config['is_cname']);
            $adapter = new AliOssAdapter($client, $config);
            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
    }

}
