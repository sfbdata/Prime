<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Exception\SemAceiteNoChatException;
use App\ChatIa\Exception\SemAcessoAoChatException;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Processo\Repository\ProcessoRepository;
use App\Tarefa\DTO\MinhasMetasOutput;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use App\Tarefa\Service\PastasSoAcompanhadasSemVer;

/**
 * O contexto das metas de quem pergunta, lido AGORA pelo servidor (o cliente só sinaliza `contexto: metas`, nunca manda
 * texto). É o mesmo recorte da tela Minhas Metas: só as metas da própria pessoa, sem as pastas ocultas pelo cadeado.
 *
 * Exige o que a tela exige: o módulo Tarefas ({@see AcessoAoModuloTarefas}, a guarda do `TarefaController::minhas`); sem
 * ele, o envio é recusado (403 `sem_acesso`) antes de qualquer leitura. Depois, o aceite de envio de dados (409 `sem_aceite`).
 *
 * Traz os números do painel (atrasadas, vencem em 7 dias, aguardando revisão, sem prazo), as atrasadas mais antigas, as que
 * vencem nos próximos 7 dias e as aguardando revisão, e "o que fazer primeiro" (a ordem do painel). Cada meta leva pasta,
 * cliente e dias para o prazo. Todo valor passa por {@see ProtecaoDoTextoDoChat::rotulo} (neutraliza e mascara conforme o
 * escritório) e o bloco usa o delimitador `metas`, que já é de dado não confiável.
 *
 * Antes de montar o bloco, cada lista perde (regra 4 da Inteligencia e o isolamento entre escritórios):
 * - a meta de outro escritório (guarda explícita, também sem o `TenantFilter`);
 * - a meta de pasta com ALGUM processo em segredo de justiça ({@see ProcessoRepository::idsDasPastasEmSegredoDeJustica}).
 *   Delas o modelo recebe só QUANTAS ficaram de fora, nunca título, pasta ou cliente.
 */
final class ContextoDasMetasNoChat
{
    private const TETO_POR_LISTA = 10;

    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly TarefaRepository $metas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PastasSoAcompanhadasSemVer $soAcompanhadasSemVer,
        private readonly AcessoAoModuloTarefas $moduloTarefas,
        private readonly ProcessoRepository $processos,
    ) {
    }

    /**
     * @return list<string> blocos de dado (um)
     *
     * @throws SemAcessoAoChatException
     * @throws SemAceiteNoChatException
     */
    public function ler(User $eu, Tenant $tenant): array
    {
        if (!$this->moduloTarefas->pode($eu, $tenant)) {
            throw new SemAcessoAoChatException('Você não tem acesso às metas (módulo Tarefas) neste escritório.');
        }
        if (!($this->configuracoes->findDoTenant($tenant)?->aceiteDeEnvioDado() ?? false)) {
            throw new SemAceiteNoChatException('O escritório precisa dar o aceite de envio de dados à I.A para ela ver as suas metas.');
        }

        $ocultas = $this->acessoAPasta->idsOcultosPara($eu, (int) $tenant->getId());
        $kpis = $this->metas->contarPainelMinhasMetas($eu, $ocultas);
        $primeiro = $this->doEscritorio($this->metas->findPrioridadesDoDia($eu, MinhasMetasOutput::TETO_PRIORIDADES, $ocultas), $tenant);
        // Auditoria 3.12: as atrasadas olham a aba Todas; a meta só acompanhada de pasta que a pessoa não vê fica fora.
        $atrasadas = $this->doEscritorio($this->metas->findAtrasadasParaAtencao($eu, self::TETO_POR_LISTA, $ocultas, $this->soAcompanhadasSemVer->ids($eu, $tenant)), $tenant);
        $revisao = $this->doEscritorio($this->metas->findAguardandoRevisao($eu, self::TETO_POR_LISTA, $ocultas), $tenant);

        // Regra 4 da Inteligencia: a meta de pasta com processo em segredo de justiça sai ANTES de o bloco ser montado.
        $emSegredo = $this->pastasEmSegredo([...$primeiro, ...$atrasadas, ...$revisao], $tenant);
        $omitidas = [];
        $primeiro = $this->semSegredo($primeiro, $emSegredo, $omitidas);
        $atrasadas = $this->semSegredo($atrasadas, $emSegredo, $omitidas);
        $revisao = $this->semSegredo($revisao, $emSegredo, $omitidas);

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
            ...$this->omitidasPorSegredo($omitidas),
        ];

        return ["METAS DA PESSOA (tela Minhas Metas; hoje é {$hoje->format('d/m/Y')}):\n<metas>\n" . implode("\n", $linhas) . "\n</metas>"];
    }

    /**
     * Das metas em segredo de justiça, o modelo recebe só QUANTAS saíram (nenhuma linha quando não saiu nenhuma).
     *
     * @param array<int, true> $omitidas id da meta como chave
     *
     * @return list<string>
     */
    private function omitidasPorSegredo(array $omitidas): array
    {
        if ($omitidas === []) {
            return [];
        }

        return [sprintf('• %d meta(s) ficaram de fora destas listas por serem de processo em segredo de justiça; a pessoa as vê na tela Minhas Metas.', \count($omitidas))];
    }

    /**
     * Só as metas do escritório da sessão: guarda explícita, que não depende do `TenantFilter`.
     *
     * @param Tarefa[] $metas
     *
     * @return list<Tarefa>
     */
    private function doEscritorio(array $metas, Tenant $tenant): array
    {
        return array_values(array_filter($metas, static fn (Tarefa $meta): bool => $meta->getTenant() === $tenant
            || ($tenant->getId() !== null && $meta->getTenant()?->getId() === $tenant->getId())));
    }

    /**
     * As pastas destas metas com algum processo em segredo de justiça (a regra da entidade, lida em lote).
     *
     * @param list<Tarefa> $metas
     *
     * @return array<int, int> id da pasta como chave (para o `isset`)
     */
    private function pastasEmSegredo(array $metas, Tenant $tenant): array
    {
        $pastas = array_values(array_unique(array_map(static fn (Tarefa $m): int => (int) $m->getPasta()->getId(), $metas)));

        return array_flip($this->processos->idsDasPastasEmSegredoDeJustica($pastas, $tenant));
    }

    /**
     * Tira as metas de pasta em segredo de justiça e anota quais saíram (a mesma meta pode estar em mais de uma lista).
     *
     * @param list<Tarefa>     $metas
     * @param array<int, int>  $emSegredo id da pasta como chave
     * @param array<int, true> $omitidas  id da meta como chave, acumulado entre as listas
     *
     * @return list<Tarefa>
     */
    private function semSegredo(array $metas, array $emSegredo, array &$omitidas): array
    {
        $liberadas = [];
        foreach ($metas as $meta) {
            if (isset($emSegredo[(int) $meta->getPasta()->getId()])) {
                $omitidas[(int) $meta->getId()] = true;

                continue;
            }
            $liberadas[] = $meta;
        }

        return $liberadas;
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
