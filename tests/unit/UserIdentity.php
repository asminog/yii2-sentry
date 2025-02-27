<?php

namespace asminog\yii2sentry\tests\unit;


use yii\base\Component;
use yii\base\NotSupportedException;
use yii\web\IdentityInterface;

/**
 *
 * @property-read string $authKey
 * @property-read mixed $id
 */
class UserIdentity extends Component implements IdentityInterface
{
    private static array $ids = [
        'user1' => ['username' => 'User First', 'email' => 'first@user.com'],
        'user2' => ['username' => 'Second First', 'email' => 'second@user.com', 'sex' => 'male'],
    ];

    private $_id;

    public string $username;
    public string $email;
    public ?string $sex = null;

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

    public function getAuthKey(): string
    {
        return 'ABCD1234';
    }

    public function validateAuthKey($authKey): bool
    {
        return $authKey === 'ABCD1234';
    }
}