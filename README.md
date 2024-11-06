# Flysystem adapter for Zoho Workdrive (uses virtual path)

[![Flysystem API version](https://img.shields.io/badge/Flysystem%20API-V2-blue?style=flat-square)](https://github.com/thephpleague/flysystem/)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/joytekmotion/zoho-workdrive.svg?label=Packagist&style=flat-square)](https://packagist.org/packages/joytekmotion/zoho-workdrive)
[![License](https://img.shields.io/github/license/joytekmotion/zoho-workdrive?label=License)](https://github.com/joytekmotion/zoho-workdrive/blob/1.x/LICENSE)

This package contains Laravel Flysystem adapter for Zoho Workdrive. It uses the virtual path to interact with the Zoho Workdrive API.

## Requirements
* PHP 8.1 or higher
* Laravel 8.x or higher

## Installation

You can install this package via composer:

```bash
composer require joytekmotion/zoho-workdrive
```

## Setup

* Add the following zoho credentials to your `.env` file:

```dotenv
ZOHO_CLIENT_ID=
ZOHO_CLIENT_SECRET=
ZOHO_REFRESH_TOKEN=
ZOHO_SCOPE=WorkDrive.files.ALL,ZohoFiles.files.READ,WorkDrive.files.sharing.CREATE
```
To get the `ZOHO_CLIENT_ID` and `ZOHO_CLIENT_SECRET`, you need to create a Zoho Self Client in the [Zoho Developer Console](https://accounts.zoho.com/developerconsole).

* To generate refresh token, you can use the following command:

```bash
php artisan zoho-oauth:refresh-token {code}
```
Replace `{code}` with the authorization code you generated from [Zoho Developer Console](https://accounts.zoho.com/developerconsole), and copy the refresh token to your `.env` file.


* Add the following disk configuration to your `config/filesystems.php` file:

```php
'workdrive' => [
    'driver' => 'workdrive',
    'client_id' => env('ZOHO_CLIENT_ID'),
    'client_secret' => env('ZOHO_CLIENT_SECRET'),
    'refresh_token' => env('ZOHO_REFRESH_TOKEN')
]
```

* Optional: The service provider will be automatically registered by Laravel. If you need to manually register the service provider, add the following to your `config/app.php` file:

```php
'providers' => [
    ...
    Joytekmotion\Zoho\Oauth\Providers\ZohoOauthServiceProvider::class,
    ...
]
```

* Add the driver by extending the `Storage` facade in your `AppServiceProvider` or any other service provider:
```php
namespace App\Providers;

use Illuminate\Support\Facades\Storage;
use Joytekmotion\Zoho\Workdrive\WorkdriveAdapter;
use Joytekmotion\Zoho\Oauth\SelfClient;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;

publc function boot()
{
    Storage::extend('workdrive', function ($app, $config) {
        $adapter =  new WorkDriveAdapter(
            new SelfClient(
                config('zoho.oauth_base_url'),
                $config['client_id'],
                $config['client_secret'],
                $config['refresh_token']
            )
        );
        return new FilesystemAdapter(
            new Filesystem($adapter, $config), $adapter, $config
        );
    });
}
```

* Optional: You can publish the configuration file using the following command:

```bash
php artisan vendor:publish --provider="Joytekmotion\Zoho\Oauth\Providers\ZohoOauthServiceProvider"
```

## Usage
### To write a file to the disk:
```php
use Illuminate\Support\Facades\Storage;

Storage::disk('workdrive')->put('virtual-folder-id/file.txt', 'contents');
```

### To read a file from the disk:
```php
use Illuminate\Support\Facades\Storage;

$contents = Storage::disk('workdrive')->get('virtual-folder-id/file.txt');
```

### To check if a file exists:
```php
use Illuminate\Support\Facades\Storage;

$exists = Storage::disk('workdrive')->exists('virtual-folder-id/file.txt');
```

## Acknowledgements
This package is inspired by [flysystem-google-drive-ext](https://github.com/masbug/flysystem-google-drive-ext) created by [masbug](https://ko-fi.com/masbug).

## License
The MIT License (MIT). Please see [License File](LICENSE) for more information.
