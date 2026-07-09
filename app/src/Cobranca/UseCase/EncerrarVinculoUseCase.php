<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EncerrarVinculoInput;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Exception\VinculoJaEncerradoException;
use App\Cobranca\Exception\VinculoNaoEncontradoException;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Encerra um vínculo Pessoa↔Objeto por um evento real (venda, substituição, saída, fim de locação...).
 *
 * História: quando a relação temporal termina, o gestor registra a data final e o motivo do
 * encerramento (obrigatório — SPEC §7). O registro NUNCA é apagado: encerrar preserva o histórico
 * (invariável 11), e um vínculo já encerrado não é reaberto nem sobrescrito — tentar encerrá-lo de
 * novo é erro de fluxo (VinculoJaEncerradoException). O vínculo é resolvido por id + tenant (guarda
 * multi-tenant); inexistente ou de outro escritório vira null e interrompe a operação. Data final
 * omitida assume hoje.
 */
final class EncerrarVinculoUseCase
{
    public function __construct(
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
    ) {
    }

    public function executar(EncerrarVinculoInput $input, Tenant $tenant): VinculoPessoaObjeto
    {
        // Guarda multi-tenant: só um vínculo do próprio escritório pode ser encerrado.
        $vinculo = $this->vinculoRepository->findOneByIdDoTenant((int) $input->vinculoId, $tenant);

        if ($vinculo === null) {
            throw new VinculoNaoEncontradoException((int) $input->vinculoId);
        }

        // Não sobrescreve encerramento anterior — preserva o histórico (invariável 11).
        if (!$vinculo->estaAberto()) {
            throw new VinculoJaEncerradoException((int) $input->vinculoId);
        }

        $vinculo->setDataFim($input->dataFim ?? new \DateTimeImmutable());
        $vinculo->setMotivoEncerramento(trim((string) $input->motivoEncerramento));
        $vinculo->setObservacao($this->normalizar($input->observacao));

        $this->vinculoRepository->salvar($vinculo, true);

        return $vinculo;
    }

    private function normalizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);

        return $valor !== '' ? $valor : null;
    }
}
