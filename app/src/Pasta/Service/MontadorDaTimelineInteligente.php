<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\DTO\EventoDaTimelineOutput;
use App\Pasta\DTO\FiltroDaTimelineInput;
use App\Pasta\DTO\TimelineInteligenteOutput;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Repository\PastaPagamentoRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Monta a Timeline inteligente da pasta (item 14 da Trilha B; desenho 1.2.3, `bluejus-central.js`
 * `tlEventos`) a partir das fontes REAIS, todas presas ao escritório e à pasta:
 *
 *  1. mensagens da pasta + audit_log (documentos, metas, alterações da pasta, processo) — pelo
 *     `PastaTimelineAssembler`, o mesmo do Histórico; nada é montado duas vezes;
 *  2. publicações do Push dos processos DESTA pasta (`listarItensPorNumerosDoTenant`);
 *  3. metas da pasta (prazo aberto e "todas concluídas");
 *  4. pagamentos da pasta (lançado, quitado, vencido), só para quem vê o financeiro.
 *
 * Uma consulta por fonte, cada uma com o MESMO teto: o top-N da fusão é exato (cada fonte trouxe
 * os seus N mais novos). O que a fonte não diz não vira evento — ver o relatório da L18 para o que
 * o desenho mostra e ficou de fora por falta de lastro.
 */
final class MontadorDaTimelineInteligente
{
    public function __construct(
        private readonly PastaTimelineAssembler $assembler,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly PastaPagamentoRepository $pagamentoRepository,
        private readonly VisibilidadeDoFinanceiroDaPasta $visibilidadeDoFinanceiro,
        private readonly RegrasDaTimelineInteligente $regras,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function montar(Pasta $pasta, Tenant $tenant, User $usuario, FiltroDaTimelineInput $filtro): TimelineInteligenteOutput
    {
        $agora    = $this->clock->now();
        $limite   = $filtro->limite;
        $truncado = false;

        // 1. Mensagens + audit_log.
        $itens = $this->assembler->montar(
            $pasta,
            $tenant,
            (int) $tenant->getId(),
            $pasta->getProcessoPrincipal()?->getId(),
            $limite,
        );
        if (count($itens) >= $limite) {
            $truncado = true;
        }
        $eventos = $this->eventosDoHistorico($itens);

        // 2. Push dos processos da pasta.
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = (string) $vinculo->getProcesso()->getNumeroProcesso();
        }
        $publicacoes  = $numeros !== [] ? $this->publicacaoRepository->listarItensPorNumerosDoTenant($tenant, $numeros, $limite) : [];
        $pushCompleto = count($publicacoes) < $limite;
        if (!$pushCompleto) {
            $truncado = true;
        }
        foreach ($publicacoes as $pub) {
            $evento = $this->eventoDaPublicacao($pasta, $pub);
            if ($evento !== null) {
                $eventos[] = $evento;
            }
        }

        // 3. Metas.
        $hoje  = $agora->setTime(0, 0);
        $metas = [];
        foreach ($pasta->getTarefas() as $tarefa) {
            // O TenantFilter já restringe a coleção; a conferência aqui é a segunda trava.
            if ($tarefa->getTenant()?->getId() !== $tenant->getId()) {
                continue;
            }
            $concluida = $tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA;
            $metas[]   = ['concluida' => $concluida, 'concluidaEm' => $tarefa->getDataConclusao()];
            if (!$concluida && $tarefa->getPrazo() !== null) {
                $eventos[] = $this->eventoDoPrazoDaMeta($tarefa, $hoje);
            }
        }
        $todasConcluidasEm = $this->regras->dataDeTodasAsMetasConcluidas($metas);
        if ($todasConcluidasEm !== null) {
            $eventos[] = (new EventoDaTimelineOutput(
                id: 'mc',
                tipo: 'metas_concluidas',
                categoria: 'meta',
                quando: $todasConcluidasEm,
                soData: false,
                titulo: 'Todas as metas da pasta concluídas',
                texto: sprintf('%d meta(s) concluída(s).', count($metas)),
                fonte: 'Metas',
                prioridade: 'ok',
            ))->comMarco(RegrasDaTimelineInteligente::MARCO_METAS_CONCLUIDAS);
        }

        // 4. Financeiro.
        $financeiroQuitado = false;
        if ($this->visibilidadeDoFinanceiro->podeVer($usuario, $tenant, $pasta)) {
            $pagamentos        = $this->pagamentoRepository->findByPasta($pasta, $tenant);
            $financeiroQuitado = $pagamentos !== [];
            foreach ($pagamentos as $pagamento) {
                $financeiroQuitado = $financeiroQuitado && $pagamento->estaPago();
                foreach ($this->eventosDoPagamento($pagamento, $hoje) as $evento) {
                    $eventos[] = $evento;
                }
            }
        }

        // Marco de criação: o "Pasta criada" do audit_log; sem ele (pasta anterior à auditoria ou
        // fora do teto), a data de abertura do cadastro — dita como tal, só o dia.
        if (!$this->temTipo($eventos, 'pasta_criada')) {
            $eventos[] = new EventoDaTimelineOutput(
                id: 'pa',
                tipo: 'pasta_criada',
                categoria: 'cadastro',
                quando: $pasta->getDataAbertura()->setTime(0, 0),
                soData: true,
                titulo: 'Data de abertura da pasta',
                texto: 'Data informada no cadastro da pasta.',
                fonte: 'Cadastro da pasta',
            );
        }

        $eventos = $this->regras->ordenar($this->regras->aplicarMarcos($eventos, $pushCompleto, $financeiroQuitado));

        $filtrados = $this->regras->filtrar($eventos, $filtro, $agora);
        $novos     = $filtro->desde !== null
            ? $this->regras->novosDesde($eventos, $filtro->desde, $agora, $usuario->getFullName())
            : null;

        return new TimelineInteligenteOutput(
            eventos: array_slice($filtrados, 0, $limite),
            totalFiltrado: count($filtrados),
            truncado: $truncado || count($filtrados) > $limite,
            limite: $limite,
            contagens: $this->regras->contagens($eventos),
            pessoas: $this->regras->pessoas($eventos),
            atencao: $this->regras->atencao($eventos),
            resumo: $this->regras->resumir($filtrados),
            novos: $novos,
            resumoDesde: $novos !== null ? $this->regras->resumir($novos) : null,
        );
    }

    /**
     * @param TimelineItemDTO[] $itens
     * @return list<EventoDaTimelineOutput>
     */
    private function eventosDoHistorico(array $itens): array
    {
        $eventos = [];
        $n       = 0;
        foreach ($itens as $item) {
            if ($item->tipo === TimelineItemType::MENSAGEM) {
                $eventos[] = $this->eventoDaMensagem($item);
                // As respostas vivem penduradas na raiz; na linha do tempo cada uma é um acontecimento.
                foreach ($item->respostas as $resposta) {
                    $eventos[] = $this->eventoDaMensagem($resposta);
                }
                continue;
            }

            ++$n;
            $tipo = match (true) {
                $item->origem === 'pasta' && $item->titulo === 'Pasta criada' => 'pasta_criada',
                $item->origem === 'meta' && $item->titulo === 'Meta concluída' => 'meta_concluida',
                default                                                         => 'auditoria',
            };
            $texto = $item->metaTitulo !== null
                ? '«' . $item->metaTitulo . '»' . ($item->detalhe !== null && $item->detalhe !== '' ? ' · ' . $item->detalhe : '')
                : $item->detalhe;

            $eventos[] = new EventoDaTimelineOutput(
                id: 'a' . $n,
                tipo: $tipo,
                categoria: match ($item->origem) {
                    'documento' => 'documento',
                    'meta'      => 'meta',
                    'processo'  => 'processo',
                    default     => 'cadastro',
                },
                quando: $item->dataHora,
                soData: false,
                titulo: $item->titulo,
                texto: $texto,
                autor: $item->autorNome ?? $item->autorEmail,
                fonte: 'Histórico do sistema',
                prioridade: $tipo === 'meta_concluida' ? 'ok' : null,
                link: $item->metaId !== null ? $this->urlGenerator->generate('tarefa_show', ['id' => $item->metaId]) : null,
                rotuloLink: $item->metaId !== null ? 'Ver meta' : null,
            );
        }

        return $eventos;
    }

    private function eventoDaMensagem(TimelineItemDTO $item): EventoDaTimelineOutput
    {
        return new EventoDaTimelineOutput(
            id: 'm' . ($item->mensagemId ?? spl_object_id($item)),
            tipo: 'registro',
            categoria: 'registro',
            quando: $item->dataHora,
            soData: false,
            titulo: $item->ehResposta ? 'Resposta em Dados da pasta' : 'Registro em Dados da pasta',
            texto: $this->textoPuro($item->detalhe),
            autor: $item->autorNome ?? $item->autorEmail,
            fonte: 'Dados da pasta',
            editado: $item->editadoEm !== null,
        );
    }

    private function eventoDaPublicacao(Pasta $pasta, PublicacaoDjenListaItem $pub): ?EventoDaTimelineOutput
    {
        $data = $pub->dataDisponibilizacao !== null
            ? \DateTimeImmutable::createFromFormat('!d/m/Y', $pub->dataDisponibilizacao)
            : false;
        if ($data === false) {
            return null;
        }

        return new EventoDaTimelineOutput(
            id: 'p' . $pub->id,
            tipo: 'publicacao',
            categoria: 'processo',
            quando: $data,
            soData: true,
            titulo: ($pub->tipoComunicacao ?? 'Movimentação') . ' no processo',
            texto: implode(' · ', array_filter([$pub->nomeOrgao, $pub->numeroProcessoExibicao], static fn (?string $v) => $v !== null && $v !== '')) ?: null,
            fonte: 'Push Processual' . ($pub->siglaTribunal !== '' ? ' · ' . $pub->siglaTribunal : ''),
            prioridade: $this->regras->prioridadeDaPublicacao($pub->tipoComunicacao),
            pendente: !$pub->lida,
            link: $this->urlGenerator->generate('pasta_show', ['id' => $pasta->getId()]) . '#push',
            rotuloLink: 'Ver no Push',
        );
    }

    private function eventoDoPrazoDaMeta(Tarefa $tarefa, \DateTimeImmutable $hoje): EventoDaTimelineOutput
    {
        $prazo  = $tarefa->getPrazo()?->setTime(0, 0) ?? $hoje;
        $atraso = -(int) $hoje->diff($prazo)->format('%r%a');

        return new EventoDaTimelineOutput(
            id: 'mp' . $tarefa->getId(),
            tipo: 'prazo_meta',
            categoria: 'meta',
            quando: $prazo,
            soData: true,
            titulo: $atraso > 0 ? 'Prazo vencido da meta' : 'Prazo da meta',
            texto: '«' . $tarefa->getTitulo() . '»' . ($atraso > 0 ? sprintf(' · vencida há %d dia(s)', $atraso) : ''),
            fonte: 'Metas',
            prioridade: $this->regras->prioridadeDoPrazoDaMeta($atraso),
            link: $this->urlGenerator->generate('tarefa_show', ['id' => $tarefa->getId()]),
            rotuloLink: 'Ver meta',
        );
    }

    /** @return list<EventoDaTimelineOutput> */
    private function eventosDoPagamento(PastaPagamento $pagamento, \DateTimeImmutable $hoje): array
    {
        $id     = (int) $pagamento->getId();
        $resumo = sprintf(
            '%s · R$ %s · vencimento %s',
            $pagamento->getDescricao() !== '' ? $pagamento->getDescricao() : 'Pagamento',
            number_format((float) $pagamento->getValor(), 2, ',', '.'),
            $pagamento->getVencimento()->format('d/m/Y'),
        );

        $eventos = [new EventoDaTimelineOutput(
            id: 'pl' . $id,
            tipo: 'pagamento_lancado',
            categoria: 'financeiro',
            quando: $pagamento->getCriadoEm(),
            soData: false,
            titulo: 'Pagamento lançado',
            texto: $resumo,
            autor: $pagamento->getAutor()?->getFullName(),
            fonte: 'Financeiro',
        )];

        if ($pagamento->getPagoEm() !== null) {
            $eventos[] = new EventoDaTimelineOutput(
                id: 'pq' . $id,
                tipo: 'pagamento_quitado',
                categoria: 'financeiro',
                quando: $pagamento->getPagoEm()->setTime(0, 0),
                soData: true,
                titulo: 'Pagamento quitado',
                texto: $resumo,
                fonte: 'Financeiro',
                prioridade: 'ok',
            );
        } elseif ($pagamento->estaVencido($hoje)) {
            $eventos[] = new EventoDaTimelineOutput(
                id: 'pv' . $id,
                tipo: 'pagamento_vencido',
                categoria: 'financeiro',
                quando: $pagamento->getVencimento()->setTime(0, 0),
                soData: true,
                titulo: 'Pagamento vencido',
                texto: $resumo,
                fonte: 'Financeiro',
                prioridade: 'importante',
                pendente: true,
            );
        }

        return $eventos;
    }

    /** @param list<EventoDaTimelineOutput> $eventos */
    private function temTipo(array $eventos, string $tipo): bool
    {
        foreach ($eventos as $e) {
            if ($e->tipo === $tipo) {
                return true;
            }
        }

        return false;
    }

    /** O registro é HTML do editor rico; a timeline mostra texto corrido. */
    private function textoPuro(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $texto = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', ' ', $html) ?? $html;
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        return $texto !== '' ? $texto : null;
    }
}
