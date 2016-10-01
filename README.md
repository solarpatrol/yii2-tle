# TLE module for Yii2

Module extension provides [TLE](https://en.wikipedia.org/wiki/Two-line_element_set) download and storage component
for Yii2. It relies on [Space Track API](https://www.space-track.org/documentation#/api) to get relevant TLE data.

## Requirements

This module relies on [CURL PHP extension](http://php.net/manual/book.curl.php).

## Installation

In order to use the module:

1. Add it to `composer.json` file of your Yii2 application:

        "solarpatrol/yii2-tle": "^1.0.0"

    or run:
    
        $ composer install solarpatrol/yii2-tle

2. Add module to your application's [configuration](http://www.yiiframework.com/doc-2.0/guide-concept-configurations.html)
and set up `storage` component:

        'modules' => [
            'tle' => [
                'class' => 'solarpatrol\tle\Module',
                'components' => [
                    'storage' => [
                        'class' => 'solarpatrol\tle\FileStorage',
                        'storagePath' => '@runtime/tle',
                        'spaceTrackLogin' => 'myspace',
                        'spaceTrackPassword' => 'passw0rd',
                        'dirMode' => 0777,
                        'fileMode' => 0666,
                        ...
                    ]
                ]
            ]
            ...
        ]

    Refer to [configuration](#configuration) section in order to get how to configure `storage` component.

3. If `solarpatrol\tle\DatabaseStorage` is used as `storage` component then apply module migrations to create a table
to store TLEs in:

        ./yii migrate --migrationPath=@vendor/solarpatrol/yii2-tle/migrations
        
## Usage        

Access TLE storage component:

    $storage = \Yii::$app->getModule('tle')->storage;

Download actual TLEs for Terra, Aqua and Meteor-M №2 satellites in a range of 3 days and add them to the storage
(satellites are identified by their [NORAD ids](https://en.wikipedia.org/wiki/Satellite_Catalog_Number)):

    $storage->update([25994, 27424, 40069], time() - 86400 * 3, time());
    
Add TLE for Terra to the storage manually (not recommended):

    $storage->add([
        '0 25994',
        '1 40069U 14037A   16200.39183603 -.00000022  00000-0  99041-5 0  9999',
        '2 40069  98.7043 255.1534 0006745  96.2107 263.9838 14.20632312105160'
    ]);
    
Get all TLEs for Terra in the storage within specified time range:
    
    $tles = $storage->getRange(25994, '2016-07-18', '2016-07-20T23:59:59');
    
Find closest actual TLE for Terra within specified time range:

    $tles = $storage->getRange(25994, '2016-07-18', '2016-07-20T23:59:59');
    $tle = Storage::getClosest($tles, '2016-07-19T16:44:44');
    
Remove Terra TLE from the storage manually (not recommended):

    $storage->remove([
         '0 25994',
         '1 40069U 14037A   16200.39183603 -.00000022  00000-0  99041-5 0  9999',
         '2 40069  98.7043 255.1534 0006745  96.2107 263.9838 14.20632312105160'
     ]); 

## Configuration

Two implementations of `solarpatrol\tle\Storage` component are supported:

- `solarpatrol\tle\FileStorage` — stores TLEs in file system;
- `solarpatrol\tle\DatabaseStorage` — stores TLEs in database.

Both of them have the following common sensitive configuration properties:

- `spaceTrackLogin` — [Space Track](https://www.space-track.org/) account's name (e-mail) that can be created
[here](https://www.space-track.org/auth/createAccount) (required);
- `spaceTrackPassword` — password for Space Track account (required);
- `connectionTimeout` — timeout in seconds for CURL requests to Space Track API (defaults to `30`);
- `enableCaching` — whether CURL requests' results should be cached (defaults to `true`);
- `cacheExpiration` — cache expiration in seconds (defaults to `21600`);
- `userAgent` — user agent header to set for CURL requests to Space Track API;
- `proxyHost` — proxy host (if used);
- `proxyPort` — proxy port (if used);
- `proxyAuthLogin` — proxy authentication login (if proxy requires authentication);
- `proxyAuthPassword` — proxy authentication password (if proxy requires authentication).

`FileStorage` has the following additional properties:

- `storagePath` — path to directory to store TLE files in (defaults to `@runtime/tle`);
- `dirMode` — directories' creation mode (defaults to `0775`);
- `fileMode` — files' creation mode.

`DatabaseStorage` has the following additional properties:

- `db` — name, configuration array or instance of database connection used to access TLE table (defaults to `db`).

## Contribution

Before making a pull request, please, be sure that your changes are rebased to `dev` branch.