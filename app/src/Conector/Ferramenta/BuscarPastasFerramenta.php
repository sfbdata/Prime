<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\SegredoNasListas;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\ResultadoDaBuscaRapidaOutput;
use App\Expediente\UseCase\BuscarRapidoUseCase;
use App\Inteligencia\DTO\FiltroDoAgenteInput;
use App\Inteligencia\DTO\PastaDoAgenteOutput;
use App\Inteligencia\Exception\FiltroDoAgenteInvalidoException;
use App\Inteligencia\UseCase\BuscarPastasDoAgenteUseCase;
use App\Inteligencia\UseCase\ContarPastasDoAgenteUseCase;
use App\Inteligencia\UseCase\ListarOpcoesDoAgenteUseCase;
use App\Pasta\DTO\GrupoDaLixeiraDoEscritorio;
use App\Pasta\DTO\OcorrenciaDaBuscaRapida;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\UseCase\ListarLixeiraDoEscritorioUseCase;
use App\Pasta\UseCase\ListarMinhasDemandasUseCase;
use App\Service\PermissionChecker;

/**
 * `buscar_pastas` (`docs/specs/mcp-remoto-oauth.md`, 6.4, e as decisões do PR 6 na seção 14): a Lista do Expediente
 * pelo conector de I.A., com as ações da {@see TabelaDeAbaEChave}:
 *  - `buscar`: a busca do Agente de Pastas ({@see BuscarPastasDoAgenteUseCase}, a mesma consulta da Lista), 50 por
 *    página, na ordem do número;
 *  - `contar`: o total do mesmo filtro ({@see ContarPastasDoAgenteUseCase}), como a Lista conta;
 *  - `opcoes`: responsáveis, marcadores, situações e prioridades ({@see ListarOpcoesDoAgenteUseCase});
 *  - `lixeira`: a lixeira do escritório agrupada por pasta ({@see ListarLixeiraDoEscritorioUseCase}), sem conteúdo;
 *  - `numero`: a busca rápida por número da pasta, cliente ou processo ({@see BuscarRapidoUseCase}), presa ao escopo da
 *    pessoa e sem pasta na lixeira;
 *  - `minhas_demandas`: as pastas em que a pessoa é a responsável ({@see ListarMinhasDemandasUseCase}).
 *
 * Regras (todas sobre a pessoa e o escritório da {@see ChamadaDoConector}, nunca de sessão):
 *  - a {@see GuardaDaAcaoDoConector} confere a ação, a chave do Master e o módulo antes de tudo;
 *  - pasta trancada pelo cadeado para quem busca segue a Lista: aparece só com número, cliente e `trancada`, e entra no
 *    total (decisão 1 do PR 6);
 *  - pasta que a pessoa não pode ver por outro motivo (sem o recurso de ver a pasta) não aparece; a resposta conta
 *    quantas saíram da página (`omitidas_sem_acesso`);
 *  - pasta em segredo de justiça sai e é contada ({@see SegredoNasListas}, 6.2, regra 3: `omitidas_em_segredo`);
 *  - filtro que o repositório ignoraria vira "filtro inválido" com o nome do campo, nunca o valor (6.2, regra 10);
 *  - campo que a ação não usa é recusado (6.2, regra 10); a página seguinte vai em `proxima_pagina`;
 *  - todo texto do banco sai pelo {@see ProtecaoDoTextoDoChat::rotulo()} (neutralizado e, no conector, mascarado).
 */
final class BuscarPastasFerramenta implements FerramentaDoConector
{
    public const NOME = 'buscar_pastas';

    /** As ações e os campos que cada uma aceita, além de `acao` (6.2, regra 10: o resto é recusado). */
    private const CAMPOS_DA_ACAO = [
        'buscar' => ['busca', 'responsavel_id', 'marcador_id', 'situacao', 'prioridade', 'desde', 'ate', 'pagina'],
        'contar' => ['busca', 'responsavel_id', 'marcador_id', 'situacao', 'prioridade', 'desde', 'ate'],
        'opcoes' => [],
        'lixeira' => ['pagina'],
        'numero' => ['numero'],
        'minhas_demandas' => ['busca', 'situacao', 'prioridade', 'desde', 'ate', 'pagina'],
    ];

    /** Campo do esquema → chave do filtro do Agente ({@see FiltroDoAgenteInput::daQuery()}). */
    private const CHAVE_DO_FILTRO = [
        'busca' => 'busca', 'responsavel_id' => 'responsavel', 'marcador_id' => 'marcador', 'situacao' => 'situacao',
        'prioridade' => 'prioridade', 'desde' => 'desde', 'ate' => 'ate', 'pagina' => 'pagina',
    ];

    private const CAMPOS_DE_ID = ['responsavel_id', 'marcador_id', 'pagina'];

    /** O cursor da lixeira ("data|id"), o mesmo formato que o {@see ListarLixeiraDoEscritorioUseCase} devolve. */
    private const CURSOR_DA_LIXEIRA = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\|[1-9]\d{0,17}$/';

    private const TAMANHO_MAXIMO_DO_NUMERO = 100;

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly SegredoNasListas $segredo,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PermissionChecker $permissoes,
        private readonly ResolvedorIdentificadorDaPasta $identificadores,
        private readonly BuscarPastasDoAgenteUseCase $buscarPastas,
        private readonly ContarPastasDoAgenteUseCase $contarPastas,
        private readonly ListarOpcoesDoAgenteUseCase $listarOpcoes,
        private readonly ListarMinhasDemandasUseCase $minhasDemandas,
        private readonly BuscarRapidoUseCase $buscaRapida,
        private readonly ListarLixeiraDoEscritorioUseCase $lixeira,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Buscar pastas';
    }

    public function descricao(): string
    {
        return 'Busca as pastas do escritório como a Lista do Expediente. Ações: buscar (busca livre e filtros, 50 por página), '
            . 'contar (o total do mesmo filtro, contando também as pastas trancadas e as em segredo de justiça, que nunca aparecem por inteiro), '
            . 'opcoes (responsáveis, marcadores, situações e prioridades para montar o filtro), lixeira (o que foi excluído nas pastas, sem conteúdo), '
            . 'numero (procura pelo número da pasta, do processo ou pelo cliente) e minhas_demandas (as pastas em que a pessoa é a responsável). '
            . 'Pasta trancada aparece só com número, cliente e trancada: true. Pasta em segredo de justiça não aparece: a resposta diz quantas foram omitidas. '
            . 'Para continuar a lista, mande em pagina o valor de proxima_pagina.';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => array_keys(self::CAMPOS_DA_ACAO), 'description' => 'O que fazer.'],
                'busca' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Busca livre da Lista: número, cliente, ação, processo.'],
                'responsavel_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id do responsável (veja acao opcoes).'],
                'marcador_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id do marcador (veja acao opcoes).'],
                'situacao' => ['type' => 'string', 'enum' => [Pasta::SITUACAO_ATIVA, Pasta::SITUACAO_ARQUIVADA]],
                'prioridade' => ['type' => 'string', 'enum' => array_map(static fn (PrioridadePasta $p): string => $p->value, PrioridadePasta::cases())],
                'desde' => ['type' => 'string', 'format' => 'date', 'description' => 'Aberta a partir de (AAAA-MM-DD).'],
                'ate' => ['type' => 'string', 'format' => 'date', 'description' => 'Aberta até (AAAA-MM-DD).'],
                'numero' => ['type' => 'string', 'maxLength' => self::TAMANHO_MAXIMO_DO_NUMERO, 'description' => 'Na acao numero: o número da pasta, do processo ou o nome do cliente.'],
                'pagina' => ['type' => ['integer', 'string'], 'description' => 'A página seguinte: o valor de proxima_pagina da resposta anterior.'],
            ],
            'required' => ['acao'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !isset(self::CAMPOS_DA_ACAO[$acao])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao']);
        if (array_diff(array_keys($argumentos), self::CAMPOS_DA_ACAO[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $this->guarda->exigir($chamada, self::NOME, $acao);

        try {
            return match ($acao) {
                'buscar' => $this->buscar($argumentos, $chamada),
                'contar' => $this->contar($argumentos, $chamada),
                'opcoes' => $this->opcoes($chamada->contexto->escritorio),
                'lixeira' => $this->lixeiraDoEscritorio($argumentos, $chamada),
                'numero' => $this->porNumero($argumentos, $chamada),
                default => $this->minhas($argumentos, $chamada),
            };
        } catch (FiltroDoAgenteInvalidoException $e) {
            throw ErroDaFerramenta::filtroInvalido(array_flip(self::CHAVE_DO_FILTRO)[$e->campo] ?? '');
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function buscar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $filtro = FiltroDoAgenteInput::daQuery(self::paraFiltro($argumentos));
        $saida = $this->buscarPastas->executar($filtro, $escritorio, $chamada->contexto->pessoa);

        return $this->paginaDePastas($saida->pastas, $saida->total, $saida->pagina, $saida->porPagina, $chamada);
    }

    /** @param array<string, mixed> $argumentos */
    private function contar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $filtro = FiltroDoAgenteInput::daQuery(self::paraFiltro($argumentos));
        $total = $this->contarPastas->executar($filtro, $chamada->contexto->escritorio, $chamada->contexto->pessoa);

        return new RespostaDaFerramenta(['total' => $total], contagens: ['total' => $total]);
    }

    /** @param array<string, mixed> $argumentos */
    private function minhas(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $pessoa = $chamada->contexto->pessoa;
        $escritorio = $chamada->contexto->escritorio;
        $filtro = FiltroDoAgenteInput::daQuery(self::paraFiltro($argumentos));
        $porPagina = BuscarPastasDoAgenteUseCase::POR_PAGINA;
        $saida = $this->minhasDemandas->executar($pessoa, $escritorio, $filtro->paraRepositorio(), $filtro->pagina, $porPagina, 'prioridade', 'desc');
        $pastas = array_values($saida['pastas']);
        $this->identificadores->primeParaPastas($pastas);
        $linhas = array_map(fn (Pasta $p): PastaDoAgenteOutput => PastaDoAgenteOutput::fromEntity($p, $this->identificadores->para($p)), $pastas);

        return $this->paginaDePastas($linhas, $saida['total'], $saida['pagina'], $porPagina, $chamada);
    }

    /**
     * A página como sai para o conector: trancada só com número e cliente; sem acesso, fora e contada; em segredo, fora
     * e contada.
     *
     * @param list<PastaDoAgenteOutput> $linhas
     */
    private function paginaDePastas(array $linhas, int $total, int $pagina, int $porPagina, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $abertas = array_values(array_filter($linhas, static fn (PastaDoAgenteOutput $l): bool => !$l->oculta));
        $permitidas = array_flip($this->permissoes->idsPermitidosComVinculo(
            $chamada->contexto->pessoa,
            $chamada->contexto->vinculo,
            AccessRequest::RESOURCE_PASTA,
            array_map(static fn (PastaDoAgenteOutput $l): int => $l->id, $abertas),
            AccessRequest::ACTION_VIEW,
        ));
        $visiveis = array_values(array_filter($linhas, static fn (PastaDoAgenteOutput $l): bool => $l->oculta || isset($permitidas[$l->id])));
        $semAcesso = \count($linhas) - \count($visiveis);
        $foraDoSegredo = $this->segredo->tirarDe($visiveis, static fn (PastaDoAgenteOutput $l): int => $l->id, $escritorio);

        $pastas = array_map(fn (PastaDoAgenteOutput $l): array => $this->linhaDaPasta($l, $escritorio), $foraDoSegredo['itens']);
        $trancadas = \count(array_filter($foraDoSegredo['itens'], static fn (PastaDoAgenteOutput $l): bool => $l->oculta));

        return new RespostaDaFerramenta(
            [
                'pastas' => $pastas,
                'total' => $total,
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'omitidas_em_segredo' => $foraDoSegredo['em_segredo'],
                'omitidas_sem_acesso' => $semAcesso,
            ],
            proximaPagina: $pagina * $porPagina < $total ? $pagina + 1 : null,
            contagens: ['pastas' => \count($pastas), 'total' => $total, 'trancadas' => $trancadas, 'em_segredo' => $foraDoSegredo['em_segredo'], 'sem_acesso' => $semAcesso],
            ids: ['pasta' => array_map(static fn (PastaDoAgenteOutput $l): int => $l->id, $foraDoSegredo['itens'])],
        );
    }

    /** @return array<string, mixed> */
    private function linhaDaPasta(PastaDoAgenteOutput $l, Tenant $escritorio): array
    {
        $linha = [
            'id' => $l->id,
            'numero' => $this->protecao->rotulo($escritorio, (string) $l->nup, 'sem número'),
            'identificador' => $l->identificador !== null ? $this->protecao->rotulo($escritorio, $l->identificador, '') : null,
            'clientes' => array_map(fn (string $c): string => $this->protecao->rotulo($escritorio, $c, 'sem nome'), $l->clientes),
        ];
        if ($l->oculta) {
            return $linha + ['trancada' => true];
        }

        return $linha + [
            'acao' => $l->acao !== null ? $this->protecao->rotulo($escritorio, $l->acao, '') : null,
            'responsavel' => $l->responsavel !== null ? ['id' => $l->responsavel['id'], 'nome' => $this->protecao->rotulo($escritorio, $l->responsavel['nome'], 'sem nome')] : null,
            'marcadores' => array_map(fn (array $m): array => ['id' => $m['id'], 'nome' => $this->protecao->rotulo($escritorio, $m['nome'], 'sem nome')], $l->marcadores),
            'situacao' => $l->situacao,
            'prioridade' => $l->prioridade,
            'aberta_em' => $l->abertaEm,
            'na_lixeira' => $l->excluida,
            'trancada' => false,
        ];
    }

    private function opcoes(Tenant $escritorio): RespostaDaFerramenta
    {
        $saida = $this->listarOpcoes->executar($escritorio);
        $nome = fn (string $texto): string => $this->protecao->rotulo($escritorio, $texto, 'sem nome');

        return new RespostaDaFerramenta(
            [
                'responsaveis' => array_map(static fn (array $r): array => ['id' => $r['id'], 'nome' => $nome($r['nome'])], $saida->responsaveis),
                'marcadores' => array_map(static fn (array $m): array => ['id' => $m['id'], 'nome' => $nome($m['nome']), 'pai_id' => $m['paiId']], $saida->marcadores),
                'situacoes' => $saida->situacoes,
                'prioridades' => $saida->prioridades,
            ],
            contagens: ['responsaveis' => \count($saida->responsaveis), 'marcadores' => \count($saida->marcadores)],
        );
    }

    /** @param array<string, mixed> $argumentos */
    private function lixeiraDoEscritorio(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $apos = $argumentos['pagina'] ?? null;
        if ($apos !== null && (!\is_string($apos) || preg_match(self::CURSOR_DA_LIXEIRA, $apos) !== 1)) {
            throw ErroDaFerramenta::filtroInvalido('pagina');
        }
        $saida = $this->lixeira->executar($chamada->contexto->pessoa, $escritorio, $apos);
        $foraDoSegredo = $this->segredo->tirarDe($saida->grupos, static fn (GrupoDaLixeiraDoEscritorio $g): int => $g->pastaId, $escritorio);
        $grupos = array_map(fn (GrupoDaLixeiraDoEscritorio $g): array => $this->grupoDaLixeira($g, $escritorio), $foraDoSegredo['itens']);

        return new RespostaDaFerramenta(
            ['pastas' => $grupos, 'omitidas_em_segredo' => $foraDoSegredo['em_segredo']],
            proximaPagina: $saida->proximo,
            contagens: ['pastas' => \count($grupos), 'em_segredo' => $foraDoSegredo['em_segredo']],
            ids: ['pasta' => array_map(static fn (GrupoDaLixeiraDoEscritorio $g): int => $g->pastaId, $foraDoSegredo['itens'])],
        );
    }

    /** @return array<string, mixed> */
    private function grupoDaLixeira(GrupoDaLixeiraDoEscritorio $g, Tenant $escritorio): array
    {
        return [
            'pasta_id' => $g->pastaId,
            'numero' => $this->protecao->rotulo($escritorio, $g->nup, 'sem número'),
            'nome' => $g->nome !== null ? $this->protecao->rotulo($escritorio, $g->nome, '') : null,
            'pasta_excluida' => $g->pastaExcluida,
            'documentos' => $g->documentos,
            'subpastas' => $g->subpastas,
            'excluido_mais_recente_em' => $g->maisRecente->format(\DateTimeInterface::ATOM),
            'excluido_mais_antigo_em' => $g->maisAntiga->format(\DateTimeInterface::ATOM),
            'excluido_por' => array_map(fn (string $n): string => $this->protecao->rotulo($escritorio, $n, 'sem nome'), $g->excluidoPor),
            'autor_sem_registro' => $g->autorSemRegistro,
        ];
    }

    /** @param array<string, mixed> $argumentos */
    private function porNumero(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $numero = $argumentos['numero'] ?? null;
        if (!\is_string($numero) || trim($numero) === '' || mb_strlen($numero) > self::TAMANHO_MAXIMO_DO_NUMERO) {
            throw ErroDaFerramenta::filtroInvalido('numero');
        }
        $saida = $this->buscaRapida->executar($numero, $chamada->contexto->pessoa, $escritorio);
        $foraDoSegredo = $this->segredo->tirarDe($saida->resultados, static fn (ResultadoDaBuscaRapidaOutput $r): int => $r->pastaId, $escritorio);
        $resultados = array_map(fn (ResultadoDaBuscaRapidaOutput $r): array => $this->resultadoPorNumero($r, $escritorio), $foraDoSegredo['itens']);

        return new RespostaDaFerramenta(
            ['resultados' => $resultados, 'omitidas_em_segredo' => $foraDoSegredo['em_segredo']],
            contagens: ['resultados' => \count($resultados), 'em_segredo' => $foraDoSegredo['em_segredo']],
            ids: ['pasta' => array_values(array_unique(array_map(static fn (ResultadoDaBuscaRapidaOutput $r): int => $r->pastaId, $foraDoSegredo['itens'])))],
        );
    }

    /**
     * Um resultado da busca rápida. No do cliente, o subtítulo da tela traz o CPF ou CNPJ: sai só "Pasta N" (6.2, regra
     * 4: do cliente, só o nome).
     *
     * @return array<string, mixed>
     */
    private function resultadoPorNumero(ResultadoDaBuscaRapidaOutput $r, Tenant $escritorio): array
    {
        $subtitulo = $r->grupo === OcorrenciaDaBuscaRapida::GRUPO_CLIENTE ? 'Pasta ' . $r->nup : $r->subtitulo;

        return [
            'grupo' => mb_strtolower($r->grupo),
            'titulo' => $this->protecao->rotulo($escritorio, $r->titulo, ''),
            'subtitulo' => $this->protecao->rotulo($escritorio, $subtitulo, ''),
            'pasta_id' => $r->pastaId,
            'numero' => $this->protecao->rotulo($escritorio, $r->nup, 'sem número'),
            'trancada' => $r->restrito,
        ];
    }

    /**
     * Os campos do esquema na forma do filtro do Agente. Tipo errado (lista, objeto, número com fração) é filtro
     * inválido com o nome do campo, nunca o valor.
     *
     * @param array<string, mixed> $argumentos
     *
     * @return array<string, string>
     *
     * @throws ErroDaFerramenta
     */
    private static function paraFiltro(array $argumentos): array
    {
        $filtro = [];
        foreach ($argumentos as $campo => $valor) {
            $ehId = \in_array($campo, self::CAMPOS_DE_ID, true);
            $aceito = $ehId ? \is_int($valor) || (\is_string($valor) && ctype_digit($valor)) : \is_string($valor);
            if (!$aceito || !isset(self::CHAVE_DO_FILTRO[$campo])) {
                throw ErroDaFerramenta::filtroInvalido((string) $campo);
            }
            $filtro[self::CHAVE_DO_FILTRO[$campo]] = (string) $valor;
        }

        return $filtro;
    }
}
