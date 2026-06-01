<?php

declare(strict_types=1);

namespace App\Tests\Factory\Tarefa;

use App\Entity\Tarefa\Tarefa;
use App\Tests\Factory\Pasta\PastaFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/** @extends PersistentProxyObjectFactory<Tarefa> */
final class TarefaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Tarefa::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'titulo'    => self::faker()->sentence(4),
            'descricao' => self::faker()->paragraph(),
            'pasta'     => PastaFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
