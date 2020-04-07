<?php

namespace asminog\yii2sentry;

use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;
use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;
use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\configureScope;
use function Sentry\init;

/**
 * SentryTarget send logs to Sentry.
 *
 * @see https://sentry.io
 *
 * @property array $scopeExtras
 * @property int $scopeLevel
 * @property array $scopeTags
 * @property string $contextMessage
 */
class SentryTarget extends Target
{
    /**
     * @var string DNS for sentry.
     */
    public $dsn;

    /**
     * @var string Release for sentry.
     */
    public $release;

    /**
     * @var array Options for sentry.
     */
    public $options = [];

    /**
     * @var array UserIdentity attributes for user context.
     */
    public $collectUserAttributes = ['id', 'username', 'email'];

    /**
     * @var array Allow to collect context automatically.
     */
    public $collectContext = ['_SESSION', 'argv'];

    /**
     * @var callable Callback function that can modify extra's array
     */
    public $extraCallback;

    const SENTRY_LEVELS = [
        Logger::LEVEL_ERROR => Severity::ERROR,
        Logger::LEVEL_WARNING => Severity::WARNING,
        Logger::LEVEL_INFO => Severity::INFO,
        Logger::LEVEL_TRACE => Severity::DEBUG,
        Logger::LEVEL_PROFILE_BEGIN => Severity::DEBUG,
        Logger::LEVEL_PROFILE_END => Severity::DEBUG,
        Logger::LEVEL_PROFILE => Severity::DEBUG,
    ];


    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->logVars = [];

        init(
            array_merge(
                $this->options,
                [
                    'dsn' => $this->dsn,
                    'release' => ($this->release == 'auto' ? $this->getRelease() : $this->release)
                ]
            )
        );

        parent::init();
    }

    /**
     * Get release based on git information
     * @return string
     */
    private function getRelease()
    {
        return trim(exec('git log --pretty="%H" -n1 HEAD'));
    }

    /**
     * @inheritdoc
     * @throws \yii\base\InvalidConfigException
     */
    public function export()
    {
        foreach ($this->messages as $message) {
            list($message, $level, $category) = $message;

            $this->clearScope();
            $this->setScopeUser();
            $this->setExtraContext();
            $this->setScopeLevel($level);
            $this->setScopeTags(['category' => $category]);

            if ($message instanceof Throwable) {
                $this->setScopeExtras($this->runExtraCallback($message, []));
                captureException($message);
                continue;
            }

            $message = $this->convertMessage($message);

            captureMessage($message);
        }
    }

    /**
     * Set sentry level scope based on yii2 level message
     *
     * @param int $level
     */
    private function setScopeLevel(int $level)
    {
        $level = $this->convertLevel($level);

        configureScope(function (Scope $scope) use ($level) : void {
            $scope->setLevel($level);
        });
    }

    /**
     * Convert Logger level to Sentry level
     * @param int $level
     * @return Severity
     */
    private function convertLevel(int $level)
    {
        return isset(self::SENTRY_LEVELS[$level]) ? new Severity(self::SENTRY_LEVELS[$level]) : Severity::fatal();
    }

    /**
     * Set sentry user scope based on yii2 Yii::$app->user
     * @throws \yii\base\InvalidConfigException
     */
    private function setScopeUser()
    {
        if (session_status() === PHP_SESSION_ACTIVE and Yii::$app->get('user', false) !== null and !empty($this->collectUserAttributes)) {
            $attributes = ['id' => (Yii::$app->user ? Yii::$app->user->getId() : null)];
            if (($user = Yii::$app->user->identity) !== null) {
                foreach ($this->collectUserAttributes as $collectUserAttribute) {
                    $attributes[$collectUserAttribute] = ArrayHelper::getValue($user, $collectUserAttribute);
                }
            }

            configureScope(function (Scope $scope) use ($attributes): void {
                $scope->setUser($attributes, true);
            });
        }
    }

    /**
     * Set sentry scope tags
     *
     * @param array $tags
     */
    private function setScopeTags(array $tags)
    {
        configureScope(function (Scope $scope) use ($tags): void {
            $scope->setTags($tags);
        });
    }

    /**
     * Add extra context if needed
     */
    private function setExtraContext()
    {
        if (!empty($this->collectContext)) {
            $this->logVars = $this->collectContext;
            $extraContext = $this->getContextMessage();

            $this->setScopeExtras(['CONTEXT' => $extraContext]);
            $this->logVars = [];
        }
    }

    /**
     * Set sentry scope extas
     * @param array $extras
     */
    private function setScopeExtras(array $extras)
    {
        configureScope(function (Scope $scope) use ($extras): void {
            $scope->setExtras($extras);
        });
    }

    /**
     * Calls the extra user callback if it exists
     *
     * @param mixed $message log message from Logger::messages
     * @param array $extra
     * @return array
     */
    protected function runExtraCallback($message, $extra = [])
    {
        if ($this->extraCallback !== false and is_callable($this->extraCallback)) {
            $extra = call_user_func($this->extraCallback, $message, $extra);
        }

        if (!is_array($extra)) {
            $extra = ['extra' => VarDumper::dumpAsString($extra)];
        }

        return $extra;
    }

    /**
     * Clear sentry scope for new message
     */
    private function clearScope()
    {
        configureScope(function (Scope $scope): void {
            $scope->clear();
        });
    }

    /**
     * @param $message
     * @return array|mixed|string
     */
    private function convertMessage($message)
    {
        if (is_array($message)) {
            if (isset($message['tags'])) {
                $this->setScopeTags($message['tags']);
                unset($message['tags']);
            }

            if (isset($message['extra'])) {
                $this->setScopeExtras($this->runExtraCallback($message, $message['extra']));
                unset($message['extra']);
            }

            if (count($message) == 1 and isset($message['msg'])) {
                $message = $message['msg'];
            }
        }

        if (!is_string($message)) {
            $message = VarDumper::dumpAsString($message);
        }

        return $message;
    }
}
