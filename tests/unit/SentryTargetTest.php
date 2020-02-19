<?php

namespace asminog\yii2sentry\tests\unit;

use Codeception\Test\Unit;
use asminog\yii2sentry\SentryTarget;
use ReflectionClass;
use Sentry\Severity;
use yii\log\Logger;

/**
 * Unit-tests for SentryTarget
 */
class SentryTargetTest extends Unit
{
    /** @var array test messages */
    protected $messages = [
        ['test', Logger::LEVEL_INFO, 'test', 1481513561.197593, []],
        ['test 2', Logger::LEVEL_INFO, 'test 2', 1481513572.867054, []]
    ];

    /**
     * Testing method getContextMessage()
     * - returns empty string ''
     * @see SentryTarget::getContextMessage
     */
    public function testGetContextMessage()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('getContextMessage');
        $method->setAccessible(true);

        $sentryTarget = $this->getSentryTarget();
        $result = $method->invokeArgs($sentryTarget, []);

        $this->assertEmpty($result);
    }

    /**
     * Testing method getLevelName()
     * - returns level name for each logger level
     * @see SentryTarget::getLevelName
     */
    public function testConvertLevel()
    {
        //valid level names
        $levelNames = [
            Severity::fatal(),
            Severity::info(),
            Severity::error(),
            Severity::warning(),
            Severity::debug(),
        ];

        $loggerClass = new ReflectionClass(Logger::class);
        $loggerLevelConstants = $loggerClass->getConstants();

        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('convertLevel');
        $method->setAccessible(true);

        $sentryTarget = $this->getSentryTarget();

        foreach ($loggerLevelConstants as $constant => $value) {
            if (strpos($constant, 'LEVEL_') === 0) {
                $level = $method->invokeArgs($sentryTarget, [$value]);
                $this->assertNotEmpty($level);
                $this->assertInstanceOf(Severity::class, $level);
                $this->assertTrue(in_array($level, $levelNames), sprintf('Level "%s" is incorrect', $level));
            }
        }

        //check default level name
        $this->assertEquals(Severity::fatal(), $method->invokeArgs($sentryTarget, [99]));
        $this->assertEquals(Severity::fatal(), $method->invokeArgs($sentryTarget, [rand()]));
    }

    /**
     * Testing method collect()
     * - assigns messages to Target property
     * - creates Sentry object
     * @see SentryTarget::collect
     */
    public function testCollect()
    {
        $sentryTarget = $this->getSentryTarget();

        $sentryTarget->collect($this->messages, false);
        $this->assertEquals(count($this->messages), count($sentryTarget->messages));
    }

    /**
     * Testing method export()
     * - Sentry::capture is called on collect([...], true)
     * - messages stack is cleaned on  collect([...], true)
     * - Sentry::capture is called on export()
     * @see SentryTarget::export
     */
    public function testExport()
    {
        $sentryTarget = $this->getSentryTarget();

        //test calling client and clearing messages on final collect
        $sentryTarget->collect($this->messages, true);
        $this->assertEmpty($sentryTarget->messages);

        //add messages and test simple export() method
        $sentryTarget->collect($this->messages, false);
        $sentryTarget->export();
        $this->assertEquals(count($this->messages), count($sentryTarget->messages));
    }

    /**
     * Returns configured SentryTarget object
     *
     * @return SentryTarget
     * @throws \yii\base\InvalidConfigException
     */
    protected function getSentryTarget()
    {
        $sentryTarget = new SentryTarget();
        $sentryTarget->exportInterval = 100;
        $sentryTarget->setLevels(Logger::LEVEL_INFO);
        return $sentryTarget;
    }

}
