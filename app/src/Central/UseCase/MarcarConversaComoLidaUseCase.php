<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Marca a conversa como lida até uma mensagem. O alvo é limitado à última mensagem que existe na conversa (um
 * id inventado lá na frente não pode "ler" o que ainda vai chegar) e o cursor nunca recua — garantido no banco
 * (`ParticipanteRepository::avancarLeitura`), não no objeto carregado, para valer com duas abas. Devolve o cursor.
 */
final class MarcarConversaComoLidaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly MensagemRepository $mensagens,
        private readonly ParticipanteRepository $participantes,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, int $ateId): int
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacao = $this->acesso->participacao($conversaId, $eu, $tenant);

        $teto = $this->mensagens->ultimoIdDa($participacao->getConversa(), $tenant);

        return $this->participantes->avancarLeitura($participacao, min($ateId, $teto));
    }
}
