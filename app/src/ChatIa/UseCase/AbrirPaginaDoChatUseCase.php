<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\PaginaDoChatOutput;
use App\ChatIa\Service\AcessoAoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\ProvedorDeLinguagem;

/**
 * Os dados da página `/chat-ia` (e `/chat-ia/{id}`). A página SEMPRE abre para quem tem vínculo ativo e o módulo da I.A:
 * sem chave do provedor, `semChave` é verdadeiro e a tela mostra "A IA ainda não está conectada" (o envio devolve 503).
 * Com chave mas sem disponibilidade (escritório sem liberação, cota), `aviso` traz o motivo. Com `$conversaId`, a conversa
 * precisa ser DE QUEM PEDE (senão 404).
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 * @throws \App\ChatIa\Exception\ConversaDeChatNaoEncontradaException
 */
final class AbrirPaginaDoChatUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly ProvedorDeLinguagem $provedor,
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, ?int $conversaId = null): PaginaDoChatOutput
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        if ($conversaId !== null) {
            $this->acesso->conversaDoDono($conversaId, $eu, $tenant);
        }

        $nome = self::primeiroNome($eu);
        if (!$this->provedor->estaConfigurado()) {
            return new PaginaDoChatOutput(true, null, $nome, $conversaId);
        }

        // Instalação com chave mas com a I.A desligada na plataforma (IA_HABILITADA, chave geral do Master): para a pessoa é o mesmo "não conectada".
        $motivo = $this->disponibilidade->para($eu, $tenant);
        if ($motivo === Disponibilidade::NaoConfiguradaNaPlataforma) {
            return new PaginaDoChatOutput(true, null, $nome, $conversaId);
        }

        return new PaginaDoChatOutput(false, $motivo->estaDisponivel() ? null : $motivo->mensagem(), $nome, $conversaId);
    }

    private static function primeiroNome(User $eu): string
    {
        $nome = trim((string) $eu->getFullName());

        return $nome === '' ? '' : explode(' ', $nome)[0];
    }
}
