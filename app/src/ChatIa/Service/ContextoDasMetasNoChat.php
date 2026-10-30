<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Exception\SemAceiteNoChatException;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Tarefa\DTO\MinhasMetasOutput;
use App\Tarefa\Repository\TarefaRepository;

/**
 * O contexto das metas de quem pergunta, lido AGORA pelo servidor (o cliente só sinaliza `contexto: metas`, nunca manda
 * texto). É o mesmo recorte da tela Minhas Metas: só as metas da própria pessoa, sem as pastas ocultas pelo cadeado.
 *
 * Traz os números do painel (atrasadas, vencem em 7 dias, aguardando revisão, sem prazo), as atrasadas mais antigas, as que
 * vencem nos próximos 7 dias e as aguardando revisão, e "o que fazer primeiro" (a ordem do painel). Cada meta leva pasta,
 * cliente e dias para o prazo. Todo valor passa por {@see ProtecaoDoTextoDoChat::rotulo} (neutraliza e mascara conforme o
 * escritório) e o bloco usa o delimitador `metas`, que já é de dado não confiável.
 */
final class ContextoDasMetasNoChat
{
    private const TETO_POR_LISTA = 10;

    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly TarefaRepository $metas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ProtecaoDoTextoDoChat $protecao,
    ) {
    }

    /**
     * @return list<string> blocos de dado (um)
     *
     * @throws SemAceiteNoChatException
     */
    public function ler(User $eu, Tenant $tenant): array
    {
        if (!($this->configuracoes->findDoTenant($tenant)?->aceiteDeEnvioDado() ?? false)) {
            throw new SemAceiteNoChatException('O escritório precisa dar o aceite de envio de dados à I.A para ela ver as suas metas.');
        }

        $ocultas = $this->acessoAPasta->idsOcultosPara($eu, (int) $tenant->getId());
        $kpis = $this->metas->contarPainelMinhasMetas($eu, $ocultas);
        $primeiro = $this->metas->findPrioridadesDoDia($eu, MinhasMetasOutput::TETO_PRIORIDADES, $ocultas);
        $atrasadas = $this->metas->findAtrasadasParaAtencao($eu, self::TETO_POR_LISTA, $ocultas);
        $revisao = $this->metas->findAguardandoRevisao($eu, self::TETO_POR_LISTA, $ocultas);

        $hoje = new \DateTimeImmutable('today');
        $linhas = [
            sprintf(
                '• Números: %d atrasadas · %d vencem em 7 dias · %d aguardando a sua revisão · %d sem prazo',
                $kpis['atrasadas'],
                $kpis['proximas'],
                $kpis['aguardando_revisao'],
                $kpis['sem_prazo'],
            ),
            ...$this->secao($tenant, 'O QUE FAZER PRIMEIRO (ordem do painel)', $primeiro, $hoje),
            ...$this->secao($tenant, 'ATRASADAS (as mais antigas)', $atrasadas, $hoje),
            ...$this->secao($tenant, 'AGUARDANDO A REVISÃO DA PESSOA (delegou e espera retorno)', $revisao, $hoje),
        ];

        return ["METAS DA PESSOA (tela Minhas Metas; hoje é {$hoje->format('d/m/Y')}):\n<metas>\n" . implode("\n", $linhas) . "\n</metas>"];
    }

    /**
     * @param Tarefa[] $metas
     *
     * @return list<string>
     */
    private function secao(Tenant $tenant, string $titulo, array $metas, \DateTimeImmutable $hoje): array
    {
        $linhas = [$titulo . ':'];
        if ($metas === []) {
            $linhas[] = '• nenhuma';

            return $linhas;
        }
        foreach ($metas as $meta) {
            $linhas[] = $this->linha($tenant, $meta, $hoje);
        }

        return $linhas;
    }

    private function linha(Tenant $tenant, Tarefa $meta, \DateTimeImmutable $hoje): string
    {
        $pasta = $meta->getPasta();
        $prazo = $meta->getPrazo();
        $dias = $prazo === null ? null : (int) $hoje->diff($prazo->setTime(0, 0))->format('%r%a');
        $quando = match (true) {
            $dias === null => 'sem prazo',
            $dias < 0 => sprintf('atrasada há %d dia(s) (prazo %s)', -$dias, $prazo->format('d/m/Y')),
            $dias === 0 => sprintf('vence hoje (%s)', $prazo->format('d/m/Y')),
            default => sprintf('vence em %d dia(s) (%s)', $dias, $prazo->format('d/m/Y')),
        };

        return sprintf(
            '• %s · pasta %s · cliente %s · %s · prioridade %s',
            $this->protecao->rotulo($tenant, $meta->getTitulo(), 'sem título'),
            $this->protecao->rotulo($tenant, (string) $pasta->getNup(), 'sem número'),
            $this->protecao->rotulo($tenant, (string) $pasta->getNomeCliente(), 'não informado'),
            $quando,
            $this->protecao->rotulo($tenant, $meta->getPrioridade()->value, 'normal'),
        );
    }
}
