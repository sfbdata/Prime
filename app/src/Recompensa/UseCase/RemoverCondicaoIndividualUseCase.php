<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Recompensa\DTO\RemoverCondicaoIndividualInput;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * "Voltar à regra geral": tira a condição individual de um indicador, que passa a seguir a regra geral do programa.
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Dado de plataforma, sem tenant; a chave é o id do indicador.
 * Fluxo principal: zera a condição, se houver, no escopo de plataforma; a linha só some quando não sobra nada (bloqueio e
 * apagado da F3 continuam). Sem condição não há nada a fazer (idempotente).
 * Auditoria: CondicaoDeRecompensa é Auditavel; a remoção entra na trilha do Master.
 */
final class RemoverCondicaoIndividualUseCase
{
    public function __construct(
        private readonly CondicaoDeRecompensaRepository $condicoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(RemoverCondicaoIndividualInput $input): void
    {
        $condicao = $this->condicoes->buscarDoIndicador($input->userId);
        if ($condicao === null) {
            return;
        }

        $condicao->voltarParaARegraGeral();
        $this->escopoDePlataforma->executar(function () use ($condicao): void {
            $condicao->semEfeito() ? $this->condicoes->remover($condicao, flush: true) : $this->condicoes->salvar($condicao, flush: true);
        });
    }
}
