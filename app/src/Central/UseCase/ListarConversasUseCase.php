<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\ContatoOutput;
use App\Central\DTO\ConversaResumoOutput;
use App\Central\DTO\MensagemOutput;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\LeituraDasMensagens;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * As conversas de quem pede, da mais recente para a mais antiga, com a última mensagem e as não lidas.
 * Quatro consultas para a lista inteira (participações, últimas, contagem, quem segue no escritório), nenhuma
 * por conversa.
 * `outroAtivo = false` quando o colega saiu do escritório: a conversa segue legível, sem envio.
 * B: grupos entram na mesma lista (participação ativa), fixadas primeiro; as arquivadas vêm marcadas e a tela decide
 * mostrar ou não ("Ver conversas arquivadas").
 * Conversa limpa ("Excluir", `LimparConversaUseCase`) fica fora até chegar mensagem depois da limpeza.
 * F3: a conversa com o BlueJus IA é sempre devolvida (`tipo = 'ia'`, `ia = true`, sem `outro`); se ainda não existe,
 * nasce aqui (`AbrirConversaComAIaUseCase`, idempotente). Nunca some por "Excluir" (limpar não a esconde). Fixá-la no
 * topo é da tela.
 * A prévia da última mensagem sai por {@see LeituraDasMensagens} (despacho de pasta que eu não vejo não vaza na lista).
 */
final class ListarConversasUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ParticipanteRepository $participantes,
        private readonly MensagemRepository $mensagens,
        private readonly LeituraDasMensagens $leitura,
        private readonly AbrirConversaComAIaUseCase $abrirConversaComAIa,
    ) {
    }

    /**
     * @return list<ConversaResumoOutput>
     *
     * @throws SemAcessoACentralException
     * @throws ConversaCriadaAoMesmoTempoException duas abas criando a conversa IA no mesmo instante: o flush que perde a
     *                                             corrida fecha o EntityManager, então não dá para repetir a busca na mesma
     *                                             requisição; a rota responde 409 e a tela repete (a conversa já existe)
     */
    public function executar(User $eu, ?Tenant $tenant): array
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacoes = $this->participantes->doUsuario($eu, $tenant);
        if (!$this->temConversaIa($participacoes)) {
            $this->abrirConversaComAIa->executar($eu, $tenant);
            $participacoes = $this->participantes->doUsuario($eu, $tenant);
        }

        $ids = array_map(static fn ($p): int => (int) $p->getConversa()->getId(), $participacoes);
        $ultimas = $this->mensagens->ultimaDeCada($ids, $tenant);
        $naoLidas = $this->mensagens->naoLidasPorConversa($eu, $tenant);
        $ativos = $this->acesso->idsComVinculoAtivo($tenant);
        $pessoasPorConversa = $this->participantes->ativosPorConversa($ids, $tenant);

        $memoria = [];
        $lista = [];
        foreach ($participacoes as $participacao) {
            $resumo = $this->resumo($participacao, $eu, $ultimas, $naoLidas, $ativos, $pessoasPorConversa, $memoria);
            if ($resumo !== null) {
                $lista[] = $resumo;
            }
        }

        return $lista;
    }

    /**
     * @param array<int, Mensagem>  $ultimas
     * @param array<int, int>       $naoLidas
     * @param array<int, mixed>     $ativos
     * @param array<int, int>       $pessoasPorConversa
     * @param array<string, mixed>  $memoria
     */
    private function resumo(Participante $participacao, User $eu, array $ultimas, array $naoLidas, array $ativos, array $pessoasPorConversa, array &$memoria): ?ConversaResumoOutput
    {
        $conversa = $participacao->getConversa();
        $id = (int) $conversa->getId();
        $ia = $conversa->isIa();
        if (!$ia && $participacao->getLimpaAteId() > 0 && (int) (($ultimas[$id] ?? null)?->getId() ?? 0) <= $participacao->getLimpaAteId()) {
            return null;
        }
        $outro = $conversa->isGrupo() || $ia ? null : $conversa->outroAlemDe($eu);

        return new ConversaResumoOutput(
            id: $id,
            outro: $outro !== null ? new ContatoOutput((int) $outro->getId(), (string) $outro->getFullName()) : null,
            ultima: isset($ultimas[$id]) ? $this->leitura->saida($ultimas[$id], $eu, $memoria) : null,
            naoLidas: $naoLidas[$id] ?? 0,
            outroAtivo: $outro === null || isset($ativos[(int) $outro->getId()]),
            marcadaNaoLida: $participacao->isMarcadaNaoLida(),
            tipo: $conversa->getTipo()->value,
            nome: $ia ? MensagemOutput::NOME_DA_IA : $conversa->getNome(),
            participantes: $pessoasPorConversa[$id] ?? 0,
            souAdministrador: $conversa->isAdministradaPor($eu),
            fixada: $participacao->isFixada(),
            silenciada: $participacao->isSilenciada(),
            arquivada: $participacao->isArquivada(),
            ia: $ia,
        );
    }

    /** @param list<Participante> $participacoes */
    private function temConversaIa(array $participacoes): bool
    {
        foreach ($participacoes as $participacao) {
            if ($participacao->getConversa()->isIa()) {
                return true;
            }
        }

        return false;
    }
}
