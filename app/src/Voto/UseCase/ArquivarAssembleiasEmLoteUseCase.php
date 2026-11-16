<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDoLote;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;

/**
 * Arquiva as assembleias marcadas com UM motivo (seleção em lote do desenho 18, A6). Cada uma é arquivada pelo
 * {@see ArquivarAssembleiaUseCase}, na sua própria transação; a que não se encaixa (rascunho, com votação aberta ou já
 * arquivada) fica como está e entra na contagem das "mantidas". Antes de mexer em qualquer uma, confere que TODAS são
 * do escritório: uma de outro escritório recusa o lote inteiro (AssembleiaNaoEncontradaException, 404), sem arquivar nada.
 */
final class ArquivarAssembleiasEmLoteUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly ArquivarAssembleiaUseCase $arquivar,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @throws OperacaoInvalidaNaAssembleiaException motivo curto (vale para todas, nada é arquivado)
     */
    public function executar(Tenant $tenant, array $ids, string $motivo, string $por, ?\DateTimeImmutable $agora = null): ResultadoDoLote
    {
        foreach ($ids as $id) {
            if (null === $this->assembleias->buscarDoEscritorio($id, $tenant)) {
                throw new AssembleiaNaoEncontradaException();
            }
        }
        if (mb_strlen(trim($motivo)) < PautaDaAssembleia::MOTIVO_MINIMO) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe o motivo do arquivamento.');
        }
        $feitas = 0;
        $mantidas = 0;
        foreach ($ids as $id) {
            try {
                $this->arquivar->executar($tenant, $id, $motivo, $por, $agora);
                ++$feitas;
            } catch (OperacaoInvalidaNaAssembleiaException) {
                ++$mantidas;
            }
        }

        return new ResultadoDoLote($feitas, $mantidas);
    }
}
