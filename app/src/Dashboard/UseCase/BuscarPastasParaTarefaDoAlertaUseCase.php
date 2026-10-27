<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;

/**
 * Pastas que a pessoa pode escolher ao criar uma tarefa a partir de um alerta da Inteligência.
 *
 * Toda tarefa pertence a uma pasta (não existe tarefa avulsa), então o botão "Criar tarefa" pede a
 * pasta primeiro, como o Push faz ao vincular. A busca é a mesma do Push: só o escritório da sessão,
 * sem pasta excluída e só as que a pessoa EDITA no cadeado (a oculta e a só visualizada não aparecem).
 */
final class BuscarPastasParaTarefaDoAlertaUseCase
{
    public const LIMITE = 8;

    public function __construct(
        private readonly PastaRepository $pastas,
    ) {
    }

    /** @return list<array{id: int, texto: string}> */
    public function executar(Tenant $tenant, User $usuario, string $termo): array
    {
        return $this->pastas->buscarParaVinculo(mb_substr(trim($termo), 0, 100), $tenant, self::LIMITE, [], $usuario);
    }
}
