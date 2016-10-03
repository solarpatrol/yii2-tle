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
                        'actualDaysCount' => 10,
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
    
3. In case of using modules' web and console controllers and actions (when direct access to the module
`\Yii::$app->getModule('tle')` is not planned) add the module to bootstrapping stage    

4. If `solarpatrol\tle\DatabaseStorage` is used as `storage` component then apply module migrations to create a table
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
    
    $tles = $storage->get(25994, '2016-07-18', '2016-07-20T23:59:59');
    
Get all TLEs for Terra and Aqua in the storage within specified time range (result is an associative array where
keys are NORAD identifiers of satellites and values are arrays of found TLEs):

    $tles = $storage->get([25994, 27424], '2016-07-18', '2016-07-20T23:59:59');
    
Find closest actual TLE for Terra within specified time range:

    $tles = $storage->get(25994, '2016-07-18', '2016-07-20T23:59:59');
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

- `actualDaysCount` — number of days to use to perform requested action if both start and end of time range are
not specified (defaults to `5`);
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

## Web requests

In order to use built-in web controller specify the module in [bootstrap](http://www.yiiframework.com/doc-2.0/guide-structure-modules.html#bootstrapping-modules)
configuration of web application:

    'bootstrap' => ['tle', ...other modules],
    'modules' => [
        'tle' => [
            'components' => [
                'storage' => [
                    'class' => 'solarpatrol\tle\FileStorage',
                    ...
                ]
            ]
        ]
    ],
    ...
    
and run web action:

    /tle/request?id[]=25994&id[]=27424&startTime=2016-09-21&endTime=2016-09-23T23:59:59
     
If you want to change controller's identifier (which is `tle` by default) set `webControllerId`:
   
    'module' => 
        'class' => 'solarpatrol\tle\FileStorage',
        'webControllerId` => 'orbit`
    ]
     
Run web action:
     
    /orbit/request?id[]=25994&id[]=27424&startTime=2016-09-21&endTime=2016-09-23T23:59:59
    
The following web actions are available:

1. Request TLEs for given satellites within given time range:

        /tle/request
    
    - `id` is array of satellites' NORAD identifiers;
    - `startTime` is start of time range in ISO 8601 or Unix timestamp (optional, if omitted then a moment
    `actualDaysCount` days earlier than `endTime` is taken);
    - `endTime` is end of time range in ISO 8601 or Unix timestamp (optional, if omitted then current system time is
    
    ### Examples
    
    - Request TLEs for Terra and Aqua for recent five days:
        
            /tle/request?id[]=25994&id[]=27424
        
    - Request TLEs for Terra, Aqua and Meteor-M №2 in time range 21st of September, 2016 — 23rd of September,
    2016:

            /tle/request?id[]=25994&id[]=27424id[]=40069&startTime=2016-09-21&endTime=2016-09-23T23:59:59

## Console commands

In order to use console commands specify the module in [bootstrap](http://www.yiiframework.com/doc-2.0/guide-structure-modules.html#bootstrapping-modules)
configuration of console application:

    'bootstrap' => ['tle', ...other modules],
    'modules' => [
        'tle' => [
            'components' => [
                'storage' => [
                    'class' => 'solarpatrol\tle\FileStorage',
                    ...
                ]
            ]
        ]
    ],
    ...
    
and run a command:
    
    ./yii tle/update 25994
    
If you want to change controller's identifier (which is `tle` by default) set `consoleControllerId`:
  
    'module' => 
        'class' => 'solarpatrol\tle\FileStorage',
        'consoleControllerId` => 'orbit`
    ]
    
Run a command:
    
    ./yii orbit/update 25994
    
The following console commands are available:
    
1. Download TLEs and save the in the storage (default): 
    
        ./yii tle/update ids [--startTime] [--endTime]
    
    where
    
    - `ids` is a set of NORAD identifiers;
    - `startTime` is start of time range in ISO 8601 or Unix timestamp (optional, if omitted then a moment
    `actualDaysCount` days earlier than `endTime` is taken);
    - `endTime` is end of time range in ISO 8601 or Unix timestamp (optional, if omitted then current system time is
    taken).
    
    ### Examples
    
    - Download and store TLEs for Terra and Aqua for recent five days:
    
            ./yii tle/update 25994 27424
        
    - Download and store TLEs for Terra, Aqua and Meteor-M №2 in time range 21st of September, 2016 — 23rd of September,
    2016:
    
            ./yii tle/update 25994 27424 40069 --startTime=2016-09-21 --endTime=2016-09-23T23:59:59

## Contribution

Before making a pull request, please, be sure that your changes are rebased to `dev` branch.