<?php

namespace asminog\yii2sentry\tests\unit;

use Codeception\Test\Unit;
use asminog\yii2sentry\SentryTarget;
use ReflectionClass;
use Sentry\Context\Context;
use Sentry\Context\TagsContext;
use Sentry\Context\UserContext;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\Scope;
use yii\base\Component;
use yii\base\NotSupportedException;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\web\IdentityInterface;
use yii\web\NotFoundHttpException;

/**
 * Unit-tests for SentryTarget
 */
class SentryTargetTest extends Unit
{
    /** @var array test messages */
    protected $messages = [
        ['test', Logger::LEVEL_INFO, 'test', 1481513561.197593, []],

    ];

    public function testInit()
    {
        $this->getSentryTarget('https://88e88888888888888eee888888eee8e8@sentry.io/1');
        $this->assertEquals('https://sentry.io', SentrySdk::getCurrentHub()->getClient()->getOptions()->getDsn());

        $this->getSentryTarget('', 'v1.0.0', ['dsn' => 'http:://test.com', 'release' => 'v2.0.3', 'environment' => 'test']);

        $this->assertNotEmpty(SentrySdk::getCurrentHub());
        $this->assertNotEmpty(SentrySdk::getCurrentHub()->getClient());
        $this->assertNotEmpty(SentrySdk::getCurrentHub()->getClient()->getOptions());
        $this->assertNull(SentrySdk::getCurrentHub()->getClient()->getOptions()->getDsn());
        $this->assertEquals('v1.0.0', SentrySdk::getCurrentHub()->getClient()->getOptions()->getRelease());
        $this->assertEquals('test', SentrySdk::getCurrentHub()->getClient()->getOptions()->getEnvironment());
    }

    public function testGetRelease()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('getRelease');
        $method->setAccessible(true);
        $sentryTarget = $this->getSentryTarget('', 'auto');
        $result = $method->invokeArgs($sentryTarget, []);
        $this->assertNotEmpty($result);
        $this->assertEquals($result, SentrySdk::getCurrentHub()->getClient()->getOptions()->getRelease());
        $expected = trim(exec('git log --pretty="%H" -n1 HEAD'));
        $this->assertEquals($expected, $result);
    }

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
     * Testing method export()
     * - Sentry::capture is called on collect([...], true)
     * - messages stack is cleaned on  collect([...], true)
     * - Sentry::capture is called on export()
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
        $this->assertEquals(count($messages), count($sentryTarget->messages));
    }

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

    public function testSetScopeUser()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('setScopeUser');
        $method->setAccessible(true);

        $sentryTarget = $this->getSentryTarget();
        $method->invokeArgs($sentryTarget, []);

        $result = $this->getSentryScopeProperty('user');

        $this->assertEquals(New UserContext(), $result);
        // todo test with user
        \Yii::$app->user->setIdentity(UserIdentity::findIdentity('user1'));
        $sentryTarget = $this->getSentryTarget('', null, [], ['id']);
        $method->invokeArgs($sentryTarget, []);
        $result = $this->getSentryScopeProperty('user');

        codecept_debug(\Yii::$app->get('user') !== null);

        $this->assertEquals(New UserContext(['id' => 'user1']), $result);
    }

    public function testSetScopeTags()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('setScopeTags');
        $method->setAccessible(true);
        $sentryTarget = $this->getSentryTarget();
        $method->invokeArgs($sentryTarget, [['tag' => 'test']]);

        $result = $this->getSentryScopeProperty('tags');

        codecept_debug(\Yii::$app->user->identity);

        $this->assertEquals(New TagsContext(['tag' => 'test']), $result);
    }

    public function testSetExtraContext()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('setExtraContext');
        $method->setAccessible(true);
        $sentryTarget = $this->getSentryTarget();
        $method->invokeArgs($sentryTarget, []);

        $result = $this->getSentryScopeProperty('extra');

        $this->assertEquals(New Context(), $result);

        $sentryTarget = $this->getSentryTarget('', null, [], false, ['_SESSION']);
        $method->invokeArgs($sentryTarget, []);
        $result = $this->getSentryScopeProperty('extra');
        $this->assertEquals(New Context(['CONTEXT' => '$_SESSION = []']), $result);
    }

    public function testClearScope()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $sentryTarget = $this->getSentryTarget();
        $method = $class->getMethod('setScopeTags');
        $method->setAccessible(true);
        $method->invokeArgs($sentryTarget, [['tag' => 'test']]);

        $tags = $this->getSentryScopeProperty('tags');
        $this->assertNotEquals(New TagsContext(), $tags);

        $method = $class->getMethod('setScopeExtras');
        $method->setAccessible(true);
        $method->invokeArgs($sentryTarget, [['extra' => 'test']]);

        $extras = $this->getSentryScopeProperty('extra');
        $this->assertNotEquals(New Context(), $tags);

        $method = $class->getMethod('clearScope');
        $method->setAccessible(true);
        $method->invokeArgs($sentryTarget, []);

        $tags = $this->getSentryScopeProperty('tags');
        $this->assertEquals(New TagsContext(), $tags);
        $extras = $this->getSentryScopeProperty('extra');
        $this->assertEquals(New Context(), $extras);
    }

    public function testConvertMessage()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $sentryTarget = $this->getSentryTarget();
        $method = $class->getMethod('convertMessage');
        $method->setAccessible(true);

        $result = $method->invokeArgs($sentryTarget, [['msg' => 'Test message', 'tags' => ['tag' => 'test'], 'extra' => ['test' => 'data']]]);

        $this->assertEquals('Test message', $result);
        $this->assertEquals(new Context(['test' => 'data']), $this->getSentryScopeProperty('extra'));
        $this->assertEquals(new TagsContext(['tag' => 'test']), $this->getSentryScopeProperty('tags'));

        $result = $method->invokeArgs($sentryTarget, [['test' => 'value']]);

        $this->assertEquals(VarDumper::dumpAsString(['test' => 'value']), $result);
    }

    public function testRunExtraCallback()
    {
        $class = new ReflectionClass(SentryTarget::class);
        $method = $class->getMethod('runExtraCallBack');
        $method->setAccessible(true);

        $sentryTarget = $this->getSentryTarget('', null, [], false, false, function($message, $extra) {$extra['Callback'] = true; return $extra;});
        $result = $method->invokeArgs($sentryTarget, ['Message', []]);

        $this->assertEquals(['Callback' => true], $result);

        $sentryTarget = $this->getSentryTarget('', null, [], false, false, function($message, $extra) {return 'extra';});
        $result = $method->invokeArgs($sentryTarget, ['Message', []]);

        $this->assertEquals(['extra' => VarDumper::dumpAsString('extra')], $result);
    }

    /**
     * Returns configured SentryTarget object
     *
     * @param null $dsn
     * @param null $release
     * @param array $options
     * @param bool $collectUserAttributes
     * @param bool $collectContext
     * @param null $extraCallback
     * @return SentryTarget
     */
    protected function getSentryTarget($dsn = null, $release = null, $options = [], $collectUserAttributes = false, $collectContext = false, $extraCallback = null)
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
     * @throws \ReflectionException
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

class UserIdentity extends Component implements IdentityInterface
{
    private static $ids = [
        'user1' => ['username' => 'User First', 'email' => 'first@user.com'],
        'user2' => ['username' => 'Second First', 'email' => 'second@user.com', 'sex' => 'male'],
    ];

    private $_id;

    public $username;
    public $email;
    public $sex;

    /**
     * @param int|string $id
     * @return IdentityInterface|static|null
     */
    public static function findIdentity($id)
    {
        if (in_array($id, array_keys(static::$ids))) {
            $identity = new static(static::$ids[$id]);
            $identity->_id = $id;
            return $identity;
        }

        return null;
    }

    /**
     * @param mixed $token
     * @param null $type
     * @return void|IdentityInterface|null
     * @throws NotSupportedException
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException();
    }

    public function getId()
    {
        return $this->_id;
    }

    public function getAuthKey()
    {
        return 'ABCD1234';
    }

    public function validateAuthKey($authKey)
    {
        return $authKey === 'ABCD1234';
    }
}