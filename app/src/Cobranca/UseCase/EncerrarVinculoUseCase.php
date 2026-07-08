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
 * Encerra um vínculo temporal entre Pessoa e Objeto de Cobrança (SPEC §7).
 *
 * História: quando ocorre um evento real (venda, saída, fim da locação, substituição...), o gestor
 * encerra o vínculo registrando a data de fim e o motivo. O vínculo (e a pessoa) NUNCA é apagado — só
 * marcamos o fim; o histórico temporal permanece disponível (invariável 11). O vínculo é resolvido por
 * id + tenant do usuário (guarda multi-tenant): vínculo inexistente ou de outro escritório é rejeitado
 * (VinculoNaoEncontradoException). Reencerrar um vínculo já fechado é rejeitado
 * (VinculoJaEncerradoException): não se reescreve histórico já consolidado.
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

        if (!$vinculo->estaAberto()) {
            throw new VinculoJaEncerradoException((int) $input->vinculoId);
        }

        $vinculo->setDataFim($input->dataFim ?? new \DateTimeImmutable());
        $vinculo->setMotivoEncerramento(trim((string) $input->motivoEncerramento));

        $observacao = $this->normalizar($input->observacao);

        if ($observacao !== null) {
            $vinculo->setObservacao($observacao);
        }

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
