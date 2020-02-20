<?php

namespace asminog\yii2sentry;

use phpDocumentor\Reflection\Types\This;
use Sentry\Severity;
use Sentry\State\Scope;
use \Throwable;
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
    public $collectContext = ['_SESSION'];

    /**
     * @var callable Callback function that can modify extra's array
     */
    public $extraCallback;


    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->logVars = $this->collectContext;

        init(array_merge($this->options, ['dsn' => $this->dsn], ['release' => ($this->release == 'auto' ? $this->getRelease() : $this->release)]));

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
     */
    public function export()
    {
        foreach ($this->messages as $message) {
            list($message, $level, $category) = $message;

            $this->setScopeLevel($level);
            $this->setScopeUser();
            $this->setScopeTags(['category' => $category]);
            $this->setExtraContext();

            if ($message instanceof Throwable) {
                $this->setScopeExtras($this->runExtraCallback($message, []));
                captureException($message);
                continue;
            }

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

        configureScope(function(Scope $scope) use ($level) : void {
            $scope->setLevel($level);
        });
    }

    /**
     * Set sentry user scope based on yii2 Yii::$app->user
     */
    private function setScopeUser()
    {
        if (!Yii::$app->request->isConsoleRequest and !empty($this->collectUserAttributes)) {
            $attributes = ['id' => (Yii::$app->user ? Yii::$app->user->getId() : null)];
            if (($user = Yii::$app->user->identity) !== null) {
                foreach ($this->collectUserAttributes as $collectUserAttribute) {
                    $attributes[$collectUserAttribute] = ArrayHelper::getValue($user, $collectUserAttribute);
                }
            }

            configureScope(function(Scope $scope) use ($attributes): void {
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
        configureScope(function(Scope $scope) use ($tags): void {
            foreach ($tags as $key => $value) {
                $scope->setTag((string)$key, (string)$value);
            }
        });
    }

    /**
     * Set sentry scope extas
     * @param array $extras
     */
    private function setScopeExtras(array $extras)
    {
        configureScope(function(Scope $scope) use ($extras): void {
            foreach ($extras as $key => $value) {
                $scope->setExtra((string)$key, $value);
            }
        });
    }

    /**
     * Calls the extra user callback if it exists
     *
     * @param mixed $message log message from Logger::messages
     * @param array $extra
     * @return array
     */
    public function runExtraCallback($message, $extra = [])
    {
        if ($this->extraCallback !== false and is_callable($this->extraCallback)) {
            $extra = call_user_func($this->extraCallback, $message, $extra);
        }

        if (!is_array($extra)) {
            $extra['extra'] = VarDumper::dumpAsString($extra);
        }

        return $extra;
    }

    /**
     * @inheritdoc
     */
    protected function getContextMessage()
    {
        return '';
    }

    /**
     * Convert Logger level to Sentry level
     * @param int $level
     * @return Severity
     */
    private function convertLevel(int $level)
    {
        switch ($level) {
            case Logger::LEVEL_ERROR:
                return Severity::error();
            case Logger::LEVEL_WARNING:
                return Severity::warning();
            case Logger::LEVEL_INFO:
                return Severity::info();
            case Logger::LEVEL_TRACE:
            case Logger::LEVEL_PROFILE_BEGIN:
            case Logger::LEVEL_PROFILE_END:
                return Severity::debug();
        }
        return Severity::fatal();
    }

    /**
     * Add extra context if needed
     */
    private function setExtraContext()
    {
        if (!empty($this->collectContext)) {
            $context = ArrayHelper::filter($GLOBALS, $this->collectContext);
            foreach ($this->maskVars as $var) {
                if (ArrayHelper::getValue($context, $var) !== null) {
                    ArrayHelper::setValue($context, $var, '***');
                }
            }
            $result = [];
            foreach ($context as $key => $value) {
                $result[ltrim($key, '_')] = VarDumper::dumpAsString($value);
            }

            $this->setScopeExtras($result);
        }

    }
}
