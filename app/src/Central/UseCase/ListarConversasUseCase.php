<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\ContatoOutput;
use App\Central\DTO\ConversaResumoOutput;
use App\Central\DTO\MensagemOutput;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * As conversas de quem pede, da mais recente para a mais antiga, com a última mensagem e as não lidas.
 * Quatro consultas para a lista inteira (participações, últimas, contagem, quem segue no escritório), nenhuma
 * por conversa.
 * `outroAtivo = false` quando o colega saiu do escritório: a conversa segue legível, sem envio.
 */
final class ListarConversasUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ParticipanteRepository $participantes,
        private readonly MensagemRepository $mensagens,
    ) {
    }

    /**
     * @return list<ConversaResumoOutput>
     *
     * @throws SemAcessoACentralException
     */
    public function executar(User $eu, ?Tenant $tenant): array
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacoes = $this->participantes->doUsuario($eu, $tenant);

        $ids = array_map(static fn ($p): int => (int) $p->getConversa()->getId(), $participacoes);
        $ultimas = $this->mensagens->ultimaDeCada($ids, $tenant);
        $naoLidas = $this->mensagens->naoLidasPorConversa($eu, $tenant);
        $ativos = $this->acesso->idsComVinculoAtivo($tenant);

        $lista = [];
        foreach ($participacoes as $participacao) {
            $conversa = $participacao->getConversa();
            $id = (int) $conversa->getId();
            $outro = $conversa->outroAlemDe($eu);
            $lista[] = new ConversaResumoOutput(
                id: $id,
                outro: new ContatoOutput((int) $outro->getId(), (string) $outro->getFullName()),
                ultima: isset($ultimas[$id]) ? MensagemOutput::fromEntity($ultimas[$id], $eu) : null,
                naoLidas: $naoLidas[$id] ?? 0,
                outroAtivo: isset($ativos[(int) $outro->getId()]),
            );
        }

        return $lista;
    }
}
