<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Central\Service\AvisosDaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * ⋮ "Marcar todas como lidas" (F3, L6262): todos os avisos da Caixa e todas as conversas ativas da pessoa NESTE
 * escritório. Cada conversa segue a mesma regra de "ler a conversa" (MarcarConversaComoLidaUseCase): desmarca o "não
 * lida", lê as menções dela e leva o cursor até a última mensagem. Nada de outra pessoa ou de outro escritório.
 */
final class MarcarTudoComoLidoUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ParticipanteRepository $participantes,
        private readonly MensagemRepository $mensagens,
        private readonly AvisoDeMencaoNaCentral $mencoes,
        private readonly AvisosDaCentral $avisos,
    ) {
    }

    /** @throws SemAcessoACentralException */
    public function executar(User $eu, ?Tenant $tenant): void
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        foreach ($this->participantes->doUsuario($eu, $tenant) as $participacao) {
            $conversa = $participacao->getConversa();
            $this->participantes->desmarcarNaoLida($participacao);
            $this->mencoes->marcarComoLidas($conversa, $eu, $tenant);
            $this->participantes->avancarLeitura($participacao, $this->mensagens->ultimoIdDa($conversa, $tenant));
        }
        $this->avisos->marcarTodosComoLidos($eu, $tenant);
    }
}
