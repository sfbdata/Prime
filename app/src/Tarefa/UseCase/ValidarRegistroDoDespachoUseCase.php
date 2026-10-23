<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\TarefaMensagemRepository;
use App\Tarefa\DTO\RegistroDoDespachoOutput;

/**
 * Validação do código do registro de um despacho (D1; spec `minhas-metas-esteira-2210.md`, C13). Só para quem é
 * do escritório: código de outro escritório, código inexistente, fora do formato ou de pasta trancada para quem
 * consulta dão o mesmo "não encontrado" (nulo), para não confirmar que o código existe em algum lugar.
 */
final class ValidarRegistroDoDespachoUseCase
{
    public function __construct(
        private readonly TarefaMensagemRepository $mensagens,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    public function executar(string $codigo, User $quem, Tenant $escritorio): ?RegistroDoDespachoOutput
    {
        if (preg_match('/^\d{4}\.\d{4}$/', $codigo) !== 1) {
            return null;
        }

        $despacho = $this->mensagens->doEscritorioPorCodigo($escritorio, $codigo);
        $pasta = $despacho?->getTarefa()?->getPasta();
        if ($despacho === null || $pasta === null || $despacho->getTenant()?->getId() !== $escritorio->getId()) {
            return null;
        }
        if ($this->acessoAPasta->nivel($quem, $pasta) === NivelDeAcesso::Oculto) {
            return null;
        }

        return RegistroDoDespachoOutput::fromEntity($despacho);
    }
}
