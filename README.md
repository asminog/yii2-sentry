# [Sentry](https://sentry.io) logger for Yii2

[![Latest Stable Version](https://img.shields.io/packagist/v/asminog/yii2-sentry.svg)](https://packagist.org/packages/asminog/yii2-sentry)
[![Test](https://github.com/asminog/yii2-sentry/actions/workflows/php.yml/badge.svg)](https://github.com/asminog/yii2-sentry/actions/workflows/php.yml)
[![License](https://img.shields.io/github/license/asminog/yii2-sentry)](https://raw.githubusercontent.com/asminog/yii2-sentry/master/LICENSE)
[![PHP from Packagist](https://img.shields.io/packagist/php-v/asminog/yii2-sentry)](https://packagist.org/packages/asminog/yii2-sentry)
[![Code Intelligence Status](https://scrutinizer-ci.com/g/asminog/yii2-sentry/badges/code-intelligence.svg?b=master)](https://scrutinizer-ci.com/g/asminog/yii2-sentry/)
[![Scrutinizer code quality](https://img.shields.io/scrutinizer/quality/g/asminog/yii2-sentry)](https://scrutinizer-ci.com/g/asminog/yii2-sentry/)
[![Downloads](https://img.shields.io/packagist/dt/asminog/yii2-sentry)](https://packagist.org/packages/asminog/yii2-sentry)

## Installation

```bash
composer require asminog/yii2-sentry
```

Add target class in the application config:

```php
return [
    'components' => [
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'asminog\yii2sentry\SentryTarget',
                    'levels' => ['error', 'warning'],
                    'dsn' => 'https://88e88888888888888eee888888eee8e8@sentry.io/1',
//                    release option for project, default: null. Use "auto" to get it from git exec('git log --pretty="%H" -n1 HEAD')
                    'release' => 'my-project-name@2.3.12',
//                    Options for sentry client
                    'options' => [],
//                    Collect additional context from $_GLOBALS, default: ['_SESSION', 'argv']. To switch off set false.
                    /* @see https://docs.sentry.io/enriching-error-data/context/?platform=php#extra-context
                    'collectContext' => ['_SERVER', '_COOKIE', '_SESSION', 'argv'],
                    // user attributes to collect, default: ['id', 'username', 'email']. To switch off set false.
                    /* @see https://docs.sentry.io/enriching-error-data/context/?platform=php#capturing-the-user */
                    'collectUserAttributes' => ['userId', 'userName', 'email'],
                    // add something to extra using extraCallback, default: null
                    'extraCallback' => function ($message, $extra) {
                        $extra['YII_ENV'] = YII_ENV;
                        return $extra;
                    }
                ],
            ],
        ],
    ],
];
```

## Usage

Writing simple message:

```php
\Yii::error('message', 'category');
```

Writing messages with extra data:

```php
\Yii::warning([
    'msg' => 'message',
    'extra' => 'value',
], 'category');
```

### Tags

Writing messages with additional tags. If need to add additional tags for event, add `tags` key in message. Tags are various key/value pairs that get assigned to an event, and can later be used as a breakdown or quick access to finding related events.

Example:

```php
\Yii::warning([
    'msg' => 'message',
    'extra' => 'value',
    'tags' => [
        'extraTagKey' => 'extraTagValue',
    ]
], 'category');
```

More about tags see https://docs.sentry.io/learn/context/#tagging-events

## About

Inspired by [notamedia/yii2-sentry](https://github.com/notamedia/yii2-sentry)