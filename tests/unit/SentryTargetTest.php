<?php

namespace asminog\yii2sentry\tests\unit;

use asminog\yii2sentry\SentryTarget;
use Codeception\Test\Unit;
use ReflectionClass;
use ReflectionException;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\web\NotFoundHttpException;

/**
 * Unit-tests for SentryTarget
 */
class SentryTargetTest extends Unit
{
    /** @var array test messages */
    protected array $messages = [
        ['test', Logger::LEVEL_INFO, 'test', 1481513561.197593, []],

    ];

    public function testInit()
    {
        $this->getSentryTarget('https://88e88888888888888eee888888eee8e8@sentry.io/1');
        codecept_debug(SentrySdk::getCurrentHub()->getClient()->getOptions()->getDsn());
        $this->assertEquals('https://88e88888888888888eee888888eee8e8@sentry.io/1', (string)SentrySdk::getCurrentHub()->getClient()->getOptions()->getDsn());

        $this->getSentryTarget('', 'v1.0.0', ['dsn' => 'http:://test.com', 'release' => 'v2.0.3', 'environment' => 'test']);

        $this->assertNotEmpty(SentrySdk::getCurrentHub());
        $this->assertNotEmpty(SentrySdk::getCurrentHub()->getClient());
        $this->assertNotEmpty(SentrySdk::getCurrentHub()->getClient()->getOptions());
        $this->assertNull(SentrySdk::getCurrentHub()->getClient()->getOptions()->getDsn());
        $this->assertEquals('v1.0.0', SentrySdk::getCurrentHub()->getClient()->getOptions()->getRelease());
        $this->assertEquals('test', SentrySdk::getCurrentHub()->getClient()->getOptions()->getEnvironment());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetRelease()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('getRelease');
        $method->setAccessible(true);
        $sentryTarget = $this->getSentryTarget('', 'auto');
        $result = $method->invokeArgs($sentryTarget, []);
        $this->assertNotEmpty($result);
        $this->assertEquals($result, SentrySdk::getCurrentHub()->getClient()->getOptions()->getRelease());
        $expected = trim(exec('test git --version 2>&1 >/dev/null && git log --pretty="%H" -n1 HEAD || echo "trunk"'));
        $this->assertEquals($expected, $result);
    }

    /**
     * Testing method getContextMessage()
     * - returns empty string ''
     * @throws ReflectionException
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
     * @throws ReflectionException
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
     * Testing method export()
     * - Sentry::capture is called on collect([...], true)
     * - messages stack is cleaned on  collect([...], true)
     * - Sentry::capture is called on export()
     * @throws InvalidConfigException
     * @see SentryTarget::export
     */
    public function testExport()
    {
        $sentryTarget = $this->getSentryTarget();

        $messages = [
            [new NotFoundHttpException('Not found'), Logger::LEVEL_ERROR, 'app', 1481513572.867054, []],
            ['Debug message', Logger::LEVEL_TRACE, 'app', 1481513572.867054, []],
        ];

        //test calling client and clearing messages on final collect
        $sentryTarget->collect($messages, true);
        $this->assertEmpty($sentryTarget->messages);

        //add messages and test simple export() method
        $sentryTarget->collect($messages, false);
        $sentryTarget->export();
        $this->assertSameSize($messages, $sentryTarget->messages);
    }

    /**
     * @throws ReflectionException
     */
    public function testSetScopeLevel()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('setScopeLevel');
        $method->setAccessible(true);
        $sentryTarget = $this->getSentryTarget();
        $method->invokeArgs($sentryTarget, [Logger::LEVEL_ERROR]);

        $result = $this->getSentryScopeProperty('level');

        $this->assertEquals(Severity::error(), $result);
    }

    /**
     * @throws InvalidConfigException
     * @throws ReflectionException
     */
    public function testSetScopeUser()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('setScopeUser');
        $method->setAccessible(true);

        $sentryTarget = $this->getSentryTarget();
        $method->invokeArgs($sentryTarget, []);

        $result = $this->getSentryScopeProperty('user');

        $this->assertNull($result);

        Yii::$app->user->setIdentity(UserIdentity::findIdentity('user1'));

        $sentryTarget = $this->getSentryTarget('', 'v0.0.1', [], ['id']);

        // user scope not set if session not started
        $method->invokeArgs($sentryTarget, []);
        $result = $this->getSentryScopeProperty('user');

        codecept_debug(Yii::$app->get('user') !== null);

        $this->assertNull($result);

        // user scope set test
        session_start();
        $method->invokeArgs($sentryTarget, []);
        $result = $this->getSentryScopeProperty('user');

        codecept_debug(Yii::$app->get('user') !== null);

        $this->assertEquals(New UserDataBag('user1'), $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testSetScopeTags()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('setScopeTags');
        $method->setAccessible(true);
        $sentryTarget = $this->getSentryTarget();
        $method->invokeArgs($sentryTarget, [['tag' => 'test']]);

        $result = $this->getSentryScopeProperty('tags');

        codecept_debug(Yii::$app->user->identity);

        $this->assertEquals(['tag' => 'test'], $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testSetExtraContext()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('setExtraContext');
        $method->setAccessible(true);
        $sentryTarget = $this->getSentryTarget();
        $method->invokeArgs($sentryTarget, []);

        $result = $this->getSentryScopeProperty('extra');

        $this->assertEquals([], $result);

        $sentryTarget = $this->getSentryTarget('', 'auto', [], [], ['_SESSION']);
        $method->invokeArgs($sentryTarget, []);
        $result = $this->getSentryScopeProperty('extra');
        $this->assertEquals(['CONTEXT' => '$_SESSION = []'], $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testClearScope()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $sentryTarget = $this->getSentryTarget();
        $method = $class->getMethod('setScopeTags');
        $method->setAccessible(true);
        $method->invokeArgs($sentryTarget, [['tag' => 'test']]);

        $tags = $this->getSentryScopeProperty('tags');
        $this->assertNotEquals([], $tags);

        $method = $class->getMethod('setScopeExtras');
        $method->setAccessible(true);
        $method->invokeArgs($sentryTarget, [['extra' => 'test']]);

        $extras = $this->getSentryScopeProperty('extra');
        $this->assertNotEquals([], $extras);

        $method = $class->getMethod('clearScope');
        $method->setAccessible(true);
        $method->invokeArgs($sentryTarget, []);

        $tags = $this->getSentryScopeProperty('tags');
        $this->assertEquals([], $tags);
        $extras = $this->getSentryScopeProperty('extra');
        $this->assertEquals([], $extras);
    }

    /**
     * @throws ReflectionException
     */
    public function testConvertMessage()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $sentryTarget = $this->getSentryTarget();
        $method = $class->getMethod('convertMessage');
        $method->setAccessible(true);

        $result = $method->invokeArgs($sentryTarget, [['msg' => 'Test message', 'tags' => ['tag' => 'test'], 'extra' => ['test' => 'data']]]);

        $this->assertEquals('Test message', $result);
        $this->assertEquals(['test' => 'data'], $this->getSentryScopeProperty('extra'));
        $this->assertEquals(['tag' => 'test'], $this->getSentryScopeProperty('tags'));

        $result = $method->invokeArgs($sentryTarget, [['test' => 'value']]);

        $this->assertEquals(VarDumper::dumpAsString(['test' => 'value']), $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testRunExtraCallback()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('runExtraCallBack');
        $method->setAccessible(true);

        $sentryTarget = $this->getSentryTarget('', '', [], [], [], function($message, $extra) {$extra['Callback'] = true; return $extra;});
        $result = $method->invokeArgs($sentryTarget, ['Message', []]);

        $this->assertEquals(['Callback' => true], $result);

        $sentryTarget = $this->getSentryTarget('', '', [], [], [], function() {return 'extra';});
        $result = $method->invokeArgs($sentryTarget, ['Message', []]);

        $this->assertEquals(['extra' => VarDumper::dumpAsString('extra')], $result);
    }

    /**
     * Returns configured SentryTarget object
     *
     * @param string $dsn
     * @param string $release
     * @param array $options
     * @param array $collectUserAttributes
     * @param array $collectContext
     * @param ?callable $extraCallback
     * @return SentryTarget
     */
    protected function getSentryTarget(string $dsn = '', string $release = 'v0.0.1', array $options = [], array $collectUserAttributes = [], array $collectContext = [], callable $extraCallback = null): SentryTarget
    {
        return new SentryTarget([
            'dsn' => $dsn,
            'release' => $release,
            'options' => $options,
            'collectUserAttributes' => $collectUserAttributes,
            'collectContext' => $collectContext,
            'extraCallback' => $extraCallback
        ]);
    }

    /**
     * @return mixed
     * @throws ReflectionException
     */
    private function getSentryScopeProperty($name)
    {
        $class = new ReflectionClass(Hub::class);
        $method = $class->getMethod('getScope');
        $method->setAccessible(true);

        $sentryHub = SentrySdk::getCurrentHub();
        $this->assertNotEmpty($sentryHub);
        $scope = $method->invokeArgs($sentryHub, []);

        $class = new ReflectionClass(Scope::class);
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        $this->assertNotEmpty($scope);
        return $property->getValue($scope);
    }
}