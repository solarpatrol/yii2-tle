# TLE module for Yii2

This module extension provides [TLE](https://en.wikipedia.org/wiki/Two-line_element_set) storage component for Yii2.

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

    \Yii::$app->getModule('tle')->update(40069, time());

## Configuration

// TODO: