<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Marca a conversa como lida até uma mensagem. O alvo é limitado à última mensagem que existe na conversa (um
 * id inventado lá na frente não pode "ler" o que ainda vai chegar) e o cursor nunca recua — garantido no banco
 * (`ParticipanteRepository::avancarLeitura`), não no objeto carregado, para valer com duas abas. Devolve o cursor.
 *
 * A2: ler também desliga o "marcar como não lida" e lê as @menções desta conversa no sino de quem leu — mesmo
 * quando o cursor não precisa andar.
 */
final class MarcarConversaComoLidaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly MensagemRepository $mensagens,
        private readonly ParticipanteRepository $participantes,
        private readonly AvisoDeMencaoNaCentral $aviso,
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

        $conversa = $participacao->getConversa();
        $teto = $this->mensagens->ultimoIdDa($conversa, $tenant);

        $this->participantes->desmarcarNaoLida($participacao);
        $this->aviso->marcarComoLidas($conversa, $eu, $tenant);

        return $this->participantes->avancarLeitura($participacao, min($ateId, $teto));
    }
}
