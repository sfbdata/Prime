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
 * B: grupos entram na mesma lista (participação ativa), fixadas primeiro; as arquivadas vêm marcadas e a tela decide
 * mostrar ou não ("Ver conversas arquivadas").
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
        $pessoasPorConversa = $this->participantes->ativosPorConversa($ids, $tenant);

        $lista = [];
        foreach ($participacoes as $participacao) {
            $conversa = $participacao->getConversa();
            $id = (int) $conversa->getId();
            $outro = $conversa->isGrupo() ? null : $conversa->outroAlemDe($eu);
            $lista[] = new ConversaResumoOutput(
                id: $id,
                outro: $outro !== null ? new ContatoOutput((int) $outro->getId(), (string) $outro->getFullName()) : null,
                ultima: isset($ultimas[$id]) ? MensagemOutput::fromEntity($ultimas[$id], $eu) : null,
                naoLidas: $naoLidas[$id] ?? 0,
                outroAtivo: $outro === null || isset($ativos[(int) $outro->getId()]),
                marcadaNaoLida: $participacao->isMarcadaNaoLida(),
                tipo: $conversa->getTipo()->value,
                nome: $conversa->getNome(),
                participantes: $pessoasPorConversa[$id] ?? 0,
                souAdministrador: $conversa->isAdministradaPor($eu),
                fixada: $participacao->isFixada(),
                silenciada: $participacao->isSilenciada(),
                arquivada: $participacao->isArquivada(),
            );
        }

        return $lista;
    }
}
