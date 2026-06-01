<?php

declare(strict_types=1);

namespace App\Tests\Factory\Auth;

use App\Entity\Auth\User;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/** @extends PersistentProxyObjectFactory<User> */
final class UserFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'email'    => self::faker()->unique()->safeEmail(),
            'fullName' => self::faker()->name(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
