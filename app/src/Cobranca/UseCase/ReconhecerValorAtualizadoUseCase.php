<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ReconhecerValorAtualizadoInput;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Reconhece manualmente os encargos (juros/multa/correção) de uma Obrigação (SPEC §10).
 *
 * História: o gestor confere o valor atualizado da dívida e registra os encargos que reconhece,
 * em CENTAVOS. O valor ORIGINAL é SEMPRE preservado (invariável 20): não há recálculo automático
 * nem criação de obrigação nova — só se atualizam os encargos reconhecidos da própria obrigação, e
 * o movimento fica no histórico do caso. A obrigação é resolvida por id + tenant (guarda
 * multi-tenant); inexistente ou de outro escritório interrompe a operação
 * (ObrigacaoNaoEncontradaException).
 */
final class ReconhecerValorAtualizadoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(ReconhecerValorAtualizadoInput $input, Tenant $tenant, User $usuario): Obrigacao
    {
        // Guarda multi-tenant: só uma obrigação do próprio escritório pode ser atualizada.
        $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant((int) $input->obrigacaoId, $tenant);

        if ($obrigacao === null) {
            throw new ObrigacaoNaoEncontradaException((int) $input->obrigacaoId);
        }

        $caso = $obrigacao->getCaso();

        // Caso encerrado não aceita novos lançamentos/movimentos (SPEC §17): fim do ciclo do Caso.
        // Reconhecer encargos num caso encerrado criaria "encerrado com saldo" (fere a invariável 17,
        // que exige saldo exigível 0 para encerrar). Nova inadimplência deve abrir um novo Caso.
        if ($caso !== null && $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Preserva o valor original (SPEC §10, invariável 20): só ajusta os encargos reconhecidos.
        $obrigacao->reconhecerEncargos($input->encargosReconhecidos);

        // A obrigação é managed: o flush do evento commita, na mesma transação, a alteração + o evento.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ValorAtualizadoReconhecido,
            $usuario,
            'Valor atualizado reconhecido.',
            [
                'obrigacaoId' => $obrigacao->getId(),
                'encargosReconhecidos' => $input->encargosReconhecidos,
                'motivo' => $input->motivo,
            ],
            flush: true,
        );

        return $obrigacao;
    }
}
