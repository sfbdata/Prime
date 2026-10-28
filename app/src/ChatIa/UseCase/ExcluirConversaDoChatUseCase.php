<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\Armazenamento\ChavesDeChatIa;
use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\RemocaoAposTransacao;

/**
 * Exclui a conversa, com as mensagens e os anexos (o banco apaga em cascata). Os ARQUIVOS dos anexos só saem do disco
 * depois do COMMIT (skill `armazenamento-de-arquivos`): as chaves são montadas antes. Conversa que não é de quem pede: 404.
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 * @throws \App\ChatIa\Exception\ConversaDeChatNaoEncontradaException
 */
final class ExcluirConversaDoChatUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly ConversaDeChatRepository $conversas,
        private readonly AnexoDeChatRepository $anexos,
        private readonly TransacaoDoChat $transacao,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $conversaId): void
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $conversa = $this->acesso->conversaDoDono($conversaId, $eu, $tenant);
        $chaves = array_map(
            static fn (AnexoDeChat $a) => ChavesDeChatIa::anexo($a),
            $this->anexos->daConversa($conversa, $tenant),
        );

        $this->transacao->executar(function () use ($conversa): void {
            $this->conversas->remover($conversa);
        });

        $this->remocao->remover($chaves, 'chat_ia_conversa_excluida');
    }
}
