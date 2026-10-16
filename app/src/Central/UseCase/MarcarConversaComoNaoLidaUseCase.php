<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Marcar como não lida" (`docs/specs/central-comunicacao-a2.md` §4): o participante deixa um sinal para voltar à
 * conversa. É só dele, e o cursor de leitura NÃO recua — a invariante da A1 continua (duas abas não "desleem").
 * Ler a conversa desliga o sinal. Conversa sem mensagem nenhuma não tem o que "não ler": 422.
 */
final class MarcarConversaComoNaoLidaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly MensagemRepository $mensagens,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId): void
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacao = $this->acesso->participacao($conversaId, $eu, $tenant);

        if ($this->mensagens->ultimoIdDa($participacao->getConversa(), $tenant) === 0) {
            throw new PedidoInvalidoNaCentralException('A conversa ainda não tem mensagens.');
        }

        $participacao->marcarComoNaoLida();
        $this->entityManager->flush();
    }
}
