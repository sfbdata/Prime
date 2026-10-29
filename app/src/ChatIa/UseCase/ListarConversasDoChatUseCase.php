<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\ConversaNaListaOutput;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * A barra lateral: SÓ as conversas de quem pede, neste escritório, fixadas primeiro e depois as mais recentes, já com o
 * grupo de cada uma (Fixadas, Hoje, Últimos 7 dias, Mais antigas). `$busca` filtra por título ou texto das falas.
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 */
final class ListarConversasDoChatUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly ConversaDeChatRepository $conversas,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return list<ConversaNaListaOutput> */
    public function executar(User $eu, ?Tenant $tenant, ?string $busca = null): array
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $hoje = \DateTimeImmutable::createFromInterface($this->relogio->now())->setTime(0, 0);

        return array_map(
            fn (ConversaDeChat $c): ConversaNaListaOutput => new ConversaNaListaOutput(
                (int) $c->getId(),
                $c->getTitulo() ?? ConversaNaListaOutput::TITULO_PADRAO,
                $c->estaFixada(),
                $c->getAtualizadaEm()->format(\DATE_ATOM),
                self::grupoDe($c, $hoje),
            ),
            $this->conversas->listarDoDono($tenant, $eu, $busca),
        );
    }

    private static function grupoDe(ConversaDeChat $conversa, \DateTimeImmutable $hoje): string
    {
        if ($conversa->estaFixada()) {
            return ConversaNaListaOutput::GRUPO_FIXADAS;
        }
        $dia = $conversa->getAtualizadaEm()->setTimezone($hoje->getTimezone())->setTime(0, 0);
        $dias = (int) $dia->diff($hoje)->format('%r%a');

        return match (true) {
            $dias <= 0 => ConversaNaListaOutput::GRUPO_HOJE,
            $dias < 7 => ConversaNaListaOutput::GRUPO_ULTIMOS_7_DIAS,
            default => ConversaNaListaOutput::GRUPO_MAIS_ANTIGAS,
        };
    }
}
