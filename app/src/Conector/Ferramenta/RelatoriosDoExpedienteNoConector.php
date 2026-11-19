<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\SegredoNasListas;
use App\Entity\Permission\AccessRequest;
use App\Pasta\DTO\AnaliseSemProcessoDaPasta;
use App\Pasta\DTO\PastaParada;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\DTO\ResumoSemProcesso;
use App\Pasta\UseCase\GerarRelatorioPastasParadasUseCase;
use App\Pasta\UseCase\GerarRelatorioSemProcessoUseCase;
use App\Service\PermissionChecker;

/**
 * Os dois relatórios do Expediente que a `buscar_pastas` entrega (`docs/specs/mcp-remoto-oauth.md`, 6.4, PR 14):
 * `pastas_paradas` ({@see GerarRelatorioPastasParadasUseCase}) e `pastas_sem_processo`
 * ({@see GerarRelatorioSemProcessoUseCase}), os mesmos UseCases das folhas da tela.
 *
 * Os UseCases já decidem quem vê o quê: o cadeado (pasta trancada nem entra no relatório), as pastas que a pessoa pode ver
 * e o financeiro de cada pasta (só para quem o vê). Aqui entram só as regras do conector: de novo o recurso de ver a
 * pasta pelo vínculo da conexão (o que sai é contado em `omitidas_sem_acesso`), o segredo de justiça (fora e contado
 * em `omitidas_em_segredo`, 6.2, regra 3), a página de {@see POR_PAGINA} e todo texto do banco pelo
 * {@see ProtecaoDoTextoDoChat::rotulo()}, cortado para a resposta caber no teto de 50 mil caracteres.
 */
final class RelatoriosDoExpedienteNoConector
{
    public const POR_PAGINA = 20;
    public const TETO_DE_PAGINA = 10_000;

    private const TAMANHO_DA_FRASE = 160;
    private const TAMANHO_DO_RESUMO = 300;
    private const FRASES_POR_PASTA = 5;
    private const METAS_POR_PASTA = 3;

    public function __construct(
        private readonly GerarRelatorioPastasParadasUseCase $paradas,
        private readonly GerarRelatorioSemProcessoUseCase $semProcesso,
        private readonly SegredoNasListas $segredo,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /**
     * As categorias que o `pastas_sem_processo` aceita como filtro.
     *
     * @return list<string>
     */
    public static function categorias(): array
    {
        return [
            AnaliseSemProcessoDaPasta::INCONSISTENTE, AnaliseSemProcessoDaPasta::COM_PROCESSO, AnaliseSemProcessoDaPasta::REVER,
            AnaliseSemProcessoDaPasta::ADMINISTRATIVA, AnaliseSemProcessoDaPasta::DEVERIA,
            AnaliseSemProcessoDaPasta::SUGERE_ADMINISTRATIVA, AnaliseSemProcessoDaPasta::CLASSIFICAR,
        ];
    }

    /**
     * A página do pedido: inteiro ou texto de dígitos, de 1 a {@see TETO_DE_PAGINA}; ausente, a primeira.
     *
     * @throws ErroDaFerramenta
     */
    public static function pagina(mixed $valor): int
    {
        if ($valor === null) {
            return 1;
        }
        $numero = \is_string($valor) && ctype_digit($valor) && \strlen($valor) <= 9 ? (int) $valor : $valor;
        if (!\is_int($numero) || $numero < 1 || $numero > self::TETO_DE_PAGINA) {
            throw ErroDaFerramenta::filtroInvalido('pagina');
        }

        return $numero;
    }

    public function pastasParadas(ChamadaDoConector $chamada, int $pagina): RespostaDaFerramenta
    {
        $relatorio = $this->paradas->executar($chamada->contexto->escritorio, $chamada->contexto->pessoa);
        $lista = $this->visiveis($relatorio->pastas, static fn (PastaParada $p): int => $p->pastaId, $chamada);
        $itens = $lista['itens'];
        $daPagina = \array_slice($itens, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        return new RespostaDaFerramenta(
            [
                'titulo' => RelatorioPastasParadasOutput::TITULO,
                'resumo' => [
                    'pastas_paradas' => \count($itens),
                    'mais_de_90_dias' => \count(array_filter($itens, static fn (PastaParada $p): bool => $p->critica())),
                    'sem_contrato' => \count(array_filter($itens, static fn (PastaParada $p): bool => $p->semContrato)),
                    'sem_processo' => \count(array_filter($itens, static fn (PastaParada $p): bool => $p->semProcesso())),
                ],
                'pastas' => array_map(fn (PastaParada $p): array => $this->linhaParada($p, $chamada), $daPagina),
                'total' => \count($itens),
                'pagina' => $pagina,
                'por_pagina' => self::POR_PAGINA,
                'omitidas_em_segredo' => $lista['em_segredo'],
                'omitidas_sem_acesso' => $lista['sem_acesso'],
            ],
            proximaPagina: $pagina * self::POR_PAGINA < \count($itens) ? $pagina + 1 : null,
            contagens: ['pastas' => \count($daPagina), 'total' => \count($itens), 'em_segredo' => $lista['em_segredo'], 'sem_acesso' => $lista['sem_acesso']],
            ids: ['pasta' => array_map(static fn (PastaParada $p): int => $p->pastaId, $daPagina)],
        );
    }

    public function pastasSemProcesso(ChamadaDoConector $chamada, int $pagina, ?string $categoria): RespostaDaFerramenta
    {
        $relatorio = $this->semProcesso->executar($chamada->contexto->escritorio, $chamada->contexto->pessoa);
        $lista = $this->visiveis($relatorio->analises, static fn (AnaliseSemProcessoDaPasta $a): int => $a->pastaId, $chamada);
        $resumo = ResumoSemProcesso::deAnalises($lista['itens']);
        $filtradas = $categoria === null
            ? $lista['itens']
            : array_values(array_filter($lista['itens'], static fn (AnaliseSemProcessoDaPasta $a): bool => $a->categoria === $categoria));
        $daPagina = \array_slice($filtradas, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        return new RespostaDaFerramenta(
            [
                'titulo' => 'Pastas sem processo',
                'resumo' => ['sem_processo' => $resumo->sem, 'deveriam_ter' => $resumo->deveria, 'rever_cadastro' => $resumo->rever, 'sem_classificacao' => $resumo->classificar, 'administrativas' => $resumo->admin],
                'pastas' => array_map(fn (AnaliseSemProcessoDaPasta $a): array => $this->linhaSemProcesso($a, $chamada), $daPagina),
                'total' => \count($filtradas),
                'pagina' => $pagina,
                'por_pagina' => self::POR_PAGINA,
                'omitidas_em_segredo' => $lista['em_segredo'],
                'omitidas_sem_acesso' => $lista['sem_acesso'],
            ],
            proximaPagina: $pagina * self::POR_PAGINA < \count($filtradas) ? $pagina + 1 : null,
            contagens: ['pastas' => \count($daPagina), 'total' => \count($filtradas), 'em_segredo' => $lista['em_segredo'], 'sem_acesso' => $lista['sem_acesso']],
            ids: ['pasta' => array_map(static fn (AnaliseSemProcessoDaPasta $a): int => $a->pastaId, $daPagina)],
        );
    }

    /**
     * Só o que a pessoa pode ver pelo vínculo da conexão e fora do segredo de justiça, na ordem do relatório. O que sai
     * sem acesso é contado à parte do segredo, para não revelar o segredo de uma pasta que a pessoa nem vê.
     *
     * @template T
     *
     * @param list<T>          $itens
     * @param \Closure(T): int $pastaId
     *
     * @return array{itens: list<T>, em_segredo: int, sem_acesso: int}
     */
    private function visiveis(array $itens, \Closure $pastaId, ChamadaDoConector $chamada): array
    {
        $permitidas = array_flip($this->permissoes->idsPermitidosComVinculo(
            $chamada->contexto->pessoa,
            $chamada->contexto->vinculo,
            AccessRequest::RESOURCE_PASTA,
            array_map($pastaId, $itens),
            AccessRequest::ACTION_VIEW,
        ));
        $comAcesso = array_values(array_filter($itens, static fn (mixed $i): bool => isset($permitidas[$pastaId($i)])));
        $foraDoSegredo = $this->segredo->tirarDe($comAcesso, $pastaId, $chamada->contexto->escritorio);

        return ['itens' => $foraDoSegredo['itens'], 'em_segredo' => $foraDoSegredo['em_segredo'], 'sem_acesso' => \count($itens) - \count($comAcesso)];
    }

    /** @return array<string, mixed> */
    private function linhaParada(PastaParada $p, ChamadaDoConector $chamada): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $frase = fn (string $t): string => self::cortar($this->protecao->rotulo($escritorio, $t, ''), self::TAMANHO_DA_FRASE);

        return [
            'pasta_id' => $p->pastaId,
            'numero' => $this->protecao->rotulo($escritorio, $p->numero, 'sem número'),
            'cliente' => $this->protecao->rotulo($escritorio, $p->cliente, 'sem nome'),
            'responsavel' => ['id' => $p->responsavelId, 'nome' => $this->protecao->rotulo($escritorio, $p->responsavel, 'sem responsável')],
            'processo' => $this->processo($p->processoNumero, $p->processoTribunal, $chamada),
            'dias_parada' => $p->dias,
            'ultima_movimentacao' => $p->ultima?->format('Y-m-d'),
            'nivel' => $p->nivel,
            'sem_contrato' => $p->semContrato,
            'pontos' => array_map($frase, \array_slice($p->pontos, 0, self::FRASES_POR_PASTA)),
            'sugestoes' => array_map($frase, \array_slice($p->sugestoes, 0, self::FRASES_POR_PASTA)),
        ];
    }

    /** @return array<string, mixed> */
    private function linhaSemProcesso(AnaliseSemProcessoDaPasta $a, ChamadaDoConector $chamada): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $texto = fn (string $t, int $tamanho): string => self::cortar($this->protecao->rotulo($escritorio, $t, ''), $tamanho);
        $metas = array_map(static fn (array $m): array => [
            'titulo' => $texto($m['titulo'], 120),
            'responsavel' => $texto($m['responsavel'], 60),
            'prazo' => $texto($m['prazo'], 20),
        ], \array_slice($a->metas, 0, self::METAS_POR_PASTA));

        return [
            'pasta_id' => $a->pastaId,
            'numero' => $this->protecao->rotulo($escritorio, $a->numero, 'sem número'),
            'cliente' => $this->protecao->rotulo($escritorio, $a->cliente, 'sem nome'),
            'responsavel' => ['id' => $a->responsavelId, 'nome' => $this->protecao->rotulo($escritorio, $a->responsavel, 'sem responsável')],
            'processo' => $this->processo($a->processoNumero, $a->processoTribunal, $chamada),
            'marcada_administrativa' => $a->marcada,
            'categoria' => $a->categoria,
            'confianca' => $a->confianca,
            'sugestao' => $texto($a->sugestao, self::TAMANHO_DA_FRASE),
            'resumo' => $texto($a->resumo, self::TAMANHO_DO_RESUMO),
            'proximo_passo' => $texto($a->proximoPasso, self::TAMANHO_DA_FRASE),
            'metas' => $metas,
        ];
    }

    /** @return array{numero: string, tribunal: string}|null */
    private function processo(?string $numero, ?string $tribunal, ChamadaDoConector $chamada): ?array
    {
        if ($numero === null) {
            return null;
        }
        $escritorio = $chamada->contexto->escritorio;

        return ['numero' => $this->protecao->rotulo($escritorio, $numero, ''), 'tribunal' => $this->protecao->rotulo($escritorio, (string) $tribunal, '')];
    }

    private static function cortar(string $texto, int $tamanho): string
    {
        return mb_strlen($texto) > $tamanho ? mb_substr($texto, 0, $tamanho) . '…' : $texto;
    }
}
