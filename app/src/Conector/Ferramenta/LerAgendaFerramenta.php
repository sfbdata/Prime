<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Agenda\DTO\CompromissoOutput;
use App\Agenda\DTO\DataDoSistemaOutput;
use App\Agenda\DTO\LembreteOutput;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Agenda\UseCase\ListarCompromissosUseCase;
use App\Agenda\UseCase\ListarDatasDoSistemaUseCase;
use App\Agenda\UseCase\ListarLembretesUseCase;
use App\ChatIa\DTO\MetaDoPrazoOutput;
use App\ChatIa\Enum\PeriodoDaConsulta;
use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\ChatIa\UseCase\ConsultarPrazosEMetasUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\PedidoDaAgendaNoConector as Pedido;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PortaDoConectorNaConsultaDePrazos;
use App\Conector\Service\SegredoNasListas;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Tarefa\Enum\AbaMetas;
use Psr\Clock\ClockInterface;

/**
 * `ler_agenda` (`docs/specs/mcp-remoto-oauth.md`, 6.4, e as decisões 14.i e "Decidido em 16/11/2026 no PR 6", item 5).
 * Só leitura. Pessoa, escritório e lotação vêm da {@see ChamadaDoConector}, nunca de sessão.
 *
 * **`agenda`** (módulo `agenda`, nenhuma chave): o que a Agenda mostra no período, pelos mesmos UseCases da tela.
 *  - compromissos e prazos pelo {@see ListarCompromissosUseCase}, com `verPastas` ligado (para saber a pasta de cada item),
 *    `verPrazos` pela permissão "Ver prazos na agenda" e `administra` sempre falso (6.3);
 *  - os aniversários da equipe ficam fora (dado pessoal de colega, 6.4);
 *  - compromisso ligado a pasta trancada para a pessoa (cadeado, {@see AcessoAPasta::idsOcultosPara()}) some e é contado;
 *    os prazos dessas pastas já não vêm do UseCase, como na tela;
 *  - item ligado a pasta em segredo de justiça some e é contado ({@see SegredoNasListas}, 6.2, regra 3);
 *  - sem o módulo `pastas`, o `pasta_id` sai dos itens (e o número da pasta, dos compromissos), como na tela;
 *  - as datas do sistema pelo {@see ListarDatasDoSistemaUseCase}, com a lotação do vínculo da chamada;
 *  - os lembretes das próximas 24 horas pelo {@see ListarLembretesUseCase}, só quando pedidos, com o mesmo filtro;
 *  - a lista de itens é paginada ({@see ORCAMENTO_POR_PAGINA} caracteres ou {@see ITENS_POR_PAGINA} itens por página);
 *    datas do sistema e lembretes vão só na primeira página.
 *
 * **`minhas_metas`** (14.i; módulo `tarefas` e a chave `metas`): o {@see ConsultarPrazosEMetasUseCase} do chat com a porta
 * do conector ({@see PortaDoConectorNaConsultaDePrazos}): módulo Tarefas e liberação do conector, no lugar do aceite da
 * BlueJus I.A. Só as metas da própria pessoa, até 20, com o total e quantas saíram por segredo.
 *
 * Texto do banco sai pela {@see ProtecaoDoTextoDoChat} (6.2, regra 6): `rotulo` para valor de uma linha e `comoDado`
 * para texto de várias linhas.
 */
final class LerAgendaFerramenta implements FerramentaDoConector
{
    public const NOME = 'ler_agenda';

    /** O teto de caracteres da lista de itens numa página: folga para as datas e os lembretes, abaixo dos 50 mil (6.2, regra 7). */
    public const ORCAMENTO_POR_PAGINA = 30_000;
    public const ITENS_POR_PAGINA = 100;
    /** A descrição longa de um compromisso é cortada aqui, para um item sozinho caber na página. */
    public const MAXIMO_DA_DESCRICAO = 2_000;
    public const CORTADO = ' (texto cortado)';

    private const JSON = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly ListarCompromissosUseCase $compromissos,
        private readonly ListarDatasDoSistemaUseCase $datasDoSistema,
        private readonly ListarLembretesUseCase $lembretes,
        private readonly ConsultarPrazosEMetasUseCase $metas,
        private readonly PortaDoConectorNaConsultaDePrazos $portaDasMetas,
        private readonly PermissoesDaAgenda $permissoesDaAgenda,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly SegredoNasListas $segredo,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Ler a agenda';
    }

    public function descricao(): string
    {
        return 'Lê a agenda do BlueJus. Ação "agenda" (padrão): compromissos, prazos e audiências das pastas, feriados e datas dos tribunais de um período (de e ate em AAAA-MM-DD, no máximo 31 dias; sem datas, os próximos 7 dias), só os da pessoa (so_minha, padrão) ou também os da equipe; "incluir" escolhe as partes e "lembretes" traz os avisos das próximas 24 horas. Ação "minhas_metas": as metas da própria pessoa com prazo, por período (hoje, proximos_7_dias ou atrasadas) e papel (responsavel, criei, acompanhando ou todas). Itens ligados a pasta em segredo de justiça ou trancada não saem: só a contagem aparece. Quando a lista for longa, peça a página seguinte em "pagina".';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'acao' => [
                    'type' => 'string',
                    'enum' => Pedido::ACOES,
                    'description' => 'agenda (padrão): a agenda do período. minhas_metas: as metas da pessoa com prazo.',
                ],
                'de' => ['type' => 'string', 'format' => 'date', 'description' => 'Só na agenda. Primeiro dia, AAAA-MM-DD. Padrão: hoje.'],
                'ate' => ['type' => 'string', 'format' => 'date', 'description' => 'Só na agenda. Último dia, AAAA-MM-DD, no máximo 31 dias contando o primeiro. Padrão: 7 dias a partir de "de".'],
                'so_minha' => ['type' => 'boolean', 'description' => 'Só na agenda. true (padrão): só os compromissos da pessoa. false: também os da equipe que ela pode ver.'],
                'incluir' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => Pedido::INCLUIR],
                    'description' => 'Só na agenda. As partes: compromissos, prazos, datas_do_sistema (padrão: as três) e lembretes (próximas 24 horas).',
                ],
                'pagina' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Só na agenda. A página da lista de itens; use o proxima_pagina da resposta anterior.'],
                'periodo' => [
                    'type' => 'string',
                    'enum' => array_map(static fn (PeriodoDaConsulta $p): string => $p->value, PeriodoDaConsulta::cases()),
                    'description' => 'Obrigatório em minhas_metas. hoje, proximos_7_dias ou atrasadas.',
                ],
                'papel' => [
                    'type' => 'string',
                    'enum' => array_map(static fn (AbaMetas $a): string => $a->value, AbaMetas::cases()),
                    'description' => 'Só em minhas_metas. responsavel (padrão), criei, acompanhando ou todas.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $acao = Pedido::acao($argumentos);
        $this->guarda->exigir($chamada, self::NOME, $acao);

        return $acao === Pedido::MINHAS_METAS
            ? $this->minhasMetas($argumentos, $chamada)
            : $this->agenda(Pedido::daAgenda($argumentos, \DateTimeImmutable::createFromInterface($this->relogio->now())), $chamada);
    }

    private function agenda(Pedido $pedido, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $verPastas = $this->permissoesDaAgenda->verPastas($chamada->contexto->pessoa, $escritorio);
        $trancadas = array_fill_keys($this->acessoAPasta->idsOcultosPara($chamada->contexto->pessoa, (int) $escritorio->getId()), true);
        $lista = $this->itensDaAgenda($pedido, $chamada, $trancadas);
        $pastaIds = array_values(array_unique(array_filter(array_map(static fn (CompromissoOutput $c): ?int => $c->pastaId, $lista['itens']))));

        $todos = array_map(fn (CompromissoOutput $c): array => $this->item($c, $escritorio, $verPastas), $lista['itens']);
        [$daPagina, $proxima] = self::pagina($todos, $pedido->pagina);

        $dados = [
            'acao' => Pedido::AGENDA,
            'de' => $pedido->de->format('Y-m-d'),
            'ate' => $pedido->ate->format('Y-m-d'),
            'so_minha' => $pedido->soMinha,
            'pagina' => $pedido->pagina,
            'total_de_itens' => \count($todos),
            'itens' => $daPagina,
        ];
        if ($pedido->pagina === 1 && $pedido->inclui(Pedido::DATAS_DO_SISTEMA)) {
            $dados['datas_do_sistema'] = $this->datas($pedido, $chamada);
        }
        if ($pedido->pagina === 1 && $pedido->inclui(Pedido::LEMBRETES)) {
            $lembretes = $this->lembretesDa($chamada, $verPastas, $trancadas);
            $dados['lembretes'] = $lembretes['lembretes'];
            $lista['trancados'] += $lembretes['trancados'];
            $lista['em_segredo'] += $lembretes['em_segredo'];
        }
        $dados['omitidos_por_segredo_de_justica'] = $lista['em_segredo'];
        $dados['omitidos_por_pasta_trancada'] = $lista['trancados'];

        return new RespostaDaFerramenta(
            $dados,
            $proxima,
            ['itens' => \count($todos), 'em_segredo' => $lista['em_segredo'], 'trancados' => $lista['trancados']],
            $pastaIds === [] ? [] : ['pasta' => $pastaIds],
        );
    }

    /**
     * Os compromissos e prazos do período, sem aniversário, só das partes pedidas, sem os de pasta trancada e sem os de
     * pasta em segredo, com quantos saíram por cada motivo. O `pasta_id` ainda vem em todos (sai depois, no item).
     *
     * @param array<int, true> $trancadas
     *
     * @return array{itens: list<CompromissoOutput>, trancados: int, em_segredo: int}
     */
    private function itensDaAgenda(Pedido $pedido, ChamadaDoConector $chamada, array $trancadas): array
    {
        $pessoa = $chamada->contexto->pessoa;
        $escritorio = $chamada->contexto->escritorio;
        $verPrazos = $pedido->inclui(Pedido::PRAZOS) && $this->permissoesDaAgenda->verPrazos($pessoa, $escritorio);

        $itens = array_values(array_filter(
            $this->compromissos->executar($escritorio, $pessoa, $pedido->de, $pedido->ate, $pedido->soMinha, true, $verPrazos, false),
            static fn (CompromissoOutput $c): bool => match ($c->origem) {
                CompromissoOutput::ORIGEM_AGENDA => $pedido->inclui(Pedido::COMPROMISSOS),
                CompromissoOutput::ORIGEM_PASTA => $verPrazos,
                default => false,
            },
        ));
        $semTrancadas = array_values(array_filter($itens, static fn (CompromissoOutput $c): bool => $c->pastaId === null || !isset($trancadas[$c->pastaId])));
        $semSegredo = $this->segredo->tirarDe($semTrancadas, static fn (CompromissoOutput $c): ?int => $c->pastaId, $escritorio);

        return [
            'itens' => $semSegredo['itens'],
            'trancados' => \count($itens) - \count($semTrancadas),
            'em_segredo' => $semSegredo['em_segredo'],
        ];
    }

    /** @return array<string, mixed> */
    private function item(CompromissoOutput $c, Tenant $escritorio, bool $verPastas): array
    {
        $doCompromisso = $c->origem === CompromissoOutput::ORIGEM_AGENDA;
        $item = [
            'tipo' => $doCompromisso ? 'compromisso' : ($c->calendario === CompromissoOutput::CAL_AUD ? 'audiencia' : 'prazo'),
            'data' => $c->data,
            'dia_inteiro' => $c->dia,
            'inicio' => $c->h1 === '' ? null : $c->h1,
            'fim' => $c->h2 === '' ? null : $c->h2,
            'titulo' => $this->protecao->rotulo($escritorio, $c->titulo, 'Sem título'),
            'status' => $c->status,
            'pasta_id' => $verPastas ? $c->pastaId : null,
            // O número da pasta do prazo já sai no título, como na tela; o do compromisso, só com o módulo Pastas.
            'pasta_numero' => $doCompromisso && !$verPastas ? null : $c->pastaNup,
        ];
        if (!$doCompromisso) {
            return $item + [
                'prazo_id' => (int) substr($c->id, \strlen('pz-')),
                'observacao' => $this->texto($escritorio, $c->observacao),
            ];
        }

        return $item + [
            'evento_id' => $c->eventoId,
            'tipo_do_compromisso' => $c->tipo,
            'meu' => $c->meu,
            'dono' => $c->donoNome === null ? null : $this->protecao->rotulo($escritorio, $c->donoNome, 'Sem nome'),
            'privado' => $c->privado,
            'recorrente' => $c->recorrente,
            'local' => $c->local === null ? null : $this->protecao->rotulo($escritorio, $c->local, ''),
            'descricao' => $this->texto($escritorio, $c->descricao),
        ];
    }

    private function texto(Tenant $escritorio, ?string $texto): ?string
    {
        if ($texto === null || trim($texto) === '') {
            return null;
        }
        $cortado = mb_strlen($texto) > self::MAXIMO_DA_DESCRICAO ? mb_substr($texto, 0, self::MAXIMO_DA_DESCRICAO) . self::CORTADO : $texto;
        $protegido = $this->protecao->comoDado($escritorio, $cortado);

        return $protegido === '' ? null : $protegido;
    }

    /** @return list<array<string, mixed>> */
    private function datas(Pedido $pedido, ChamadaDoConector $chamada): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $lotacao = $chamada->contexto->vinculo->getLotacao();
        $resultado = $this->datasDoSistema->executar($escritorio, $lotacao?->getNome(), $pedido->de, $pedido->ate, $lotacao?->getId());

        return array_map(fn (DataDoSistemaOutput $d): array => [
            'data' => $d->data,
            'titulo' => $this->protecao->rotulo($escritorio, $d->titulo, 'Sem título'),
            'tipo' => $d->tipo,
            'uf' => $d->uf,
            'conta_no_ponto' => $d->contaNoPonto,
        ], $resultado['datas']);
    }

    /**
     * Os lembretes das próximas 24 horas, com o mesmo filtro dos itens: pasta trancada e segredo somem e são contados.
     *
     * @param array<int, true> $trancadas
     *
     * @return array{lembretes: list<array<string, mixed>>, trancados: int, em_segredo: int}
     */
    private function lembretesDa(ChamadaDoConector $chamada, bool $verPastas, array $trancadas): array
    {
        $pessoa = $chamada->contexto->pessoa;
        $escritorio = $chamada->contexto->escritorio;
        $verPrazos = $this->permissoesDaAgenda->verPrazos($pessoa, $escritorio);
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());

        $todos = $this->lembretes->executar($escritorio, $pessoa, $agora, true, $verPrazos);
        $semTrancadas = array_values(array_filter($todos, static fn (LembreteOutput $l): bool => $l->pastaId === null || !isset($trancadas[$l->pastaId])));
        $semSegredo = $this->segredo->tirarDe($semTrancadas, static fn (LembreteOutput $l): ?int => $l->pastaId, $escritorio);

        return [
            'lembretes' => array_map(fn (LembreteOutput $l): array => [
                'tipo' => $l->tipo,
                'titulo' => $this->protecao->rotulo($escritorio, $l->titulo, 'Sem título'),
                'quando' => $l->quando,
                'aviso' => $l->aviso,
                'evento_id' => $l->eventoId,
                'pasta_id' => $verPastas ? $l->pastaId : null,
                'observacao' => $this->texto($escritorio, $l->observacao),
            ], $semSegredo['itens']),
            'trancados' => \count($todos) - \count($semTrancadas),
            'em_segredo' => $semSegredo['em_segredo'],
        ];
    }

    /**
     * A página pedida da lista de itens, cortada pelo tamanho em JSON e pelo número de itens. O corte é sempre o mesmo
     * para a mesma lista, então a página N se acha percorrendo do começo.
     *
     * @param list<array<string, mixed>> $itens
     *
     * @return array{0: list<array<string, mixed>>, 1: ?int} os itens da página e a página seguinte, se houver
     */
    private static function pagina(array $itens, int $pedida): array
    {
        $paginas = [[]];
        $tamanho = 0;
        foreach ($itens as $item) {
            $doItem = mb_strlen(json_encode($item, self::JSON)) + 1;
            $atual = \count($paginas) - 1;
            if ($paginas[$atual] !== [] && ($tamanho + $doItem > self::ORCAMENTO_POR_PAGINA || \count($paginas[$atual]) >= self::ITENS_POR_PAGINA)) {
                $paginas[] = [];
                ++$atual;
                $tamanho = 0;
            }
            $paginas[$atual][] = $item;
            $tamanho += $doItem;
        }

        return [$paginas[$pedida - 1] ?? [], $pedida < \count($paginas) ? $pedida + 1 : null];
    }

    /** @param array<string, mixed> $argumentos */
    private function minhasMetas(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $consulta = Pedido::daConsultaDeMetas($argumentos);
        $pessoa = $chamada->contexto->pessoa;
        $escritorio = $chamada->contexto->escritorio;

        $saida = $this->metas->executar($pessoa, $escritorio, $consulta, $this->portaDasMetas);

        return new RespostaDaFerramenta(
            [
                'acao' => Pedido::MINHAS_METAS,
                'periodo' => $saida->periodo->value,
                'papel' => $saida->papel->value,
                'hoje' => $saida->hoje->format('Y-m-d'),
                'total' => $saida->total,
                'mostradas' => \count($saida->metas),
                'omitidas_por_segredo_de_justica' => $saida->omitidasPorSegredo,
                'metas' => array_map(fn (MetaDoPrazoOutput $m): array => [
                    'numero' => $m->numero,
                    'titulo' => $this->protecao->rotulo($escritorio, $m->titulo, 'Sem título'),
                    'pasta_numero' => $m->pasta,
                    'cliente' => $this->protecao->rotulo($escritorio, $m->cliente, 'Sem nome'),
                    'prazo' => $m->prazo->format('Y-m-d'),
                    'dias' => $m->dias,
                    'prioridade' => $m->prioridade,
                    'quem' => array_map(fn (string $nome): string => $this->protecao->rotulo($escritorio, $nome, 'Sem nome'), $m->quem),
                ], $saida->metas),
            ],
            contagens: ['metas' => $saida->total, 'em_segredo' => $saida->omitidasPorSegredo],
        );
    }
}
