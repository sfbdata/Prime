<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\PreferenciasDoDashboardOutput;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Grava um ajuste do menu ⋮ da tabela Desempenho.
 *
 * Quem: o usuário logado, para ele mesmo, no escritório da sessão — usuário e escritório vêm da
 * sessão, nunca do corpo da requisição. O quê: uma chave da lista fechada do catálogo com um valor
 * que ela aceita. Por quê: o estilo segue a pessoa em qualquer computador (o desenho pede servidor,
 * não `localStorage`).
 *
 * Erros: chave fora da lista ou valor não permitido → {@see PreferenciaInvalidaException}, e nada
 * é gravado. Gravar o mesmo valor de novo é inofensivo (idempotente).
 *
 * Devolve o estilo completo depois da gravação, para a tela se alinhar ao que o servidor guardou.
 */
final class SalvarPreferenciaDoDashboardUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $repository,
    ) {
    }

    /**
     * @throws PreferenciaInvalidaException
     */
    public function executar(Tenant $tenant, User $usuario, string $chave, mixed $valor): PreferenciasDoDashboardOutput
    {
        $normalizado = CatalogoDePreferenciasDoDashboard::validar($chave, $valor);

        $this->repository->gravar($tenant, $usuario, $chave, $normalizado);

        return PreferenciasDoDashboardOutput::deValores(
            $this->repository->valoresDoUsuario($tenant, $usuario, CatalogoDePreferenciasDoDashboard::chaves()),
        );
    }
}
