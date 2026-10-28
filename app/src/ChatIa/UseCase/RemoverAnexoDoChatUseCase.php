<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\Armazenamento\ChavesDeChatIa;
use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\RemocaoAposTransacao;

/**
 * Descarta um anexo que ainda não foi enviado numa pergunta (o "x" do clipe). Só o dono, só anexo solto: id alheio ou já
 * usado é o mesmo {@see AnexoDoChatInvalidoException}. O arquivo sai do disco depois do COMMIT.
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 * @throws AnexoDoChatInvalidoException
 */
final class RemoverAnexoDoChatUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly AnexoDeChatRepository $anexos,
        private readonly TransacaoDoChat $transacao,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $anexoId): void
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $anexo = ($anexoId > 0 ? $this->anexos->soltoDoDono($anexoId, $tenant, $eu) : null)
            ?? throw new AnexoDoChatInvalidoException('Anexo não encontrado.');
        $chave = ChavesDeChatIa::anexo($anexo);

        $this->transacao->executar(function () use ($anexo): void {
            $this->anexos->remover($anexo);
        });

        $this->remocao->remover([$chave], 'chat_ia_anexo_descartado');
    }
}
