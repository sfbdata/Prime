<?php

declare(strict_types=1);

namespace App\Tests\Factory\Tarefa;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Enum\PrioridadeDaMeta;
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
            // A factory não herda da pasta (isso é do GravarMetaNovaUseCase): o teste diz a prioridade.
            'prioridade' => PrioridadeDaMeta::Normal,
        ];
    }

    protected function initialize(): static
    {
        // Tarefa é TenantAware (coluna tenant NOT NULL). Quando o teste não informa um tenant
        // explícito, derivamos o da pasta dona — garante tarefa.tenant == pasta.tenant.
        return $this->afterInstantiate(function (Tarefa $tarefa): void {
            if ($tarefa->getTenant() === null) {
                $tarefa->setTenant($tarefa->getPasta()->getTenant());
            }
        });
    }
}
