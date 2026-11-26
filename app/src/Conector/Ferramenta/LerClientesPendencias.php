<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\CamposDoCadastro;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\SegredoNasListas;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Service\PermissionChecker;

/**
 * `pendencias` da `ler_clientes` (pedido M9 de 26/11/2026, Fase 1, item 1): o relatório de pendências do cadastro numa
 * chamada só, em vez de um `ler` por cliente. Só leitura.
 *
 * Visão `clientes` (a padrão): os clientes do escritório que a pessoa vê (recurso `cliente` de ver, com o escopo do
 * acesso restrito, a mesma regra do `ler` e do `buscar`) aos quais falta QUALQUER um dos campos pedidos em `faltando`
 * (sem o campo, CPF ou CNPJ). "Falta" é a mesma conta do `ler` ({@see CamposDoCadastro::fotografar()}, valor vazio):
 * o valor só decide se o cliente entra, nunca sai. Cada linha: id, nome (pela {@see ProtecaoDoTextoDoChat}), tipo,
 * quais dos campos pedidos faltam e as pastas vinculadas que a pessoa abre (id e número), sem as ligadas a processo em
 * segredo de justiça (contadas na linha). Pasta que a pessoa não abre (sem o recurso ou trancada pelo cadeado) não
 * aparece nem é contada, como no `ler`.
 *
 * Visão `pastas_sem_cliente` (a inversa): as pastas ativas do escritório sem nenhum cliente vinculado que a pessoa abre,
 * com o nome de cliente escrito na pasta, se houver. Exige também o módulo Expediente (a porta da `buscar_pastas`). A que
 * a pessoa não abre sai e conta em `omitidas_sem_acesso` (quem tem acesso restrito conta só as do seu escopo); a
 * em segredo de justiça sai e conta em `omitidas_em_segredo` (o segredo só é olhado nas que ela abre, para não revelá-lo).
 *
 * As duas visões são paginadas ({@see POR_PAGINA}, até {@see MAXIMO_POR_PAGINA}) e trazem o `total` do filtro, para
 * conferir antes de percorrer as páginas.
 */
final class LerClientesPendencias
{
    public const POR_PAGINA = 100;
    public const MAXIMO_POR_PAGINA = 200;
    public const VISOES = ['clientes', 'pastas_sem_cliente'];

    /** Sem `faltando`, o documento principal: CPF da pessoa física, CNPJ da jurídica. */
    public const FALTANDO_PADRAO = ['cpf', 'cnpj'];

    /** O grupo `endereco` falta quando falta qualquer um destes (o complemento é opcional). */
    public const CAMPOS_DO_ENDERECO = ['cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf'];

    /** As pastas mostradas por cliente; o resto conta em `pastas_a_mais`. */
    public const PASTAS_POR_CLIENTE = 10;

    private const TETO_DE_PAGINA = 10_000;
    private const TAMANHO_DO_NOME = 100;

    public function __construct(
        private readonly ClienteRepository $clientes,
        private readonly PastaRepository $pastas,
        private readonly CamposDoCadastro $campos,
        private readonly PermissionChecker $permissoes,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly SegredoNasListas $segredo,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly EscopoDePastas $escopo,
    ) {
    }

    /** @return list<string> as chaves aceitas em `faltando`: as do cadastro e o grupo `endereco` */
    public static function camposAceitos(): array
    {
        return [...array_keys(CamposDoCadastro::ROTULOS), 'endereco'];
    }

    /**
     * O pedido conferido antes da guarda (pedido inválido não depende de permissão). O valor digitado nunca entra na
     * mensagem: só o nome do campo.
     *
     * @param array<string, mixed> $argumentos sem `acao`
     *
     * @return array{visao: string, faltando: list<string>, pagina: int, por_pagina: int}
     *
     * @throws ErroDaFerramenta
     */
    public static function pedido(array $argumentos): array
    {
        $visao = $argumentos['visao'] ?? 'clientes';
        if (!\is_string($visao) || !\in_array($visao, self::VISOES, true)) {
            throw ErroDaFerramenta::filtroInvalido('visao');
        }
        if ($visao === 'pastas_sem_cliente' && \array_key_exists('faltando', $argumentos)) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return [
            'visao' => $visao,
            'faltando' => \array_key_exists('faltando', $argumentos) ? self::faltando($argumentos['faltando']) : self::FALTANDO_PADRAO,
            'pagina' => self::inteiro($argumentos, 'pagina', 1, self::TETO_DE_PAGINA, 1),
            'por_pagina' => self::inteiro($argumentos, 'por_pagina', 1, self::MAXIMO_POR_PAGINA, self::POR_PAGINA),
        ];
    }

    /** @param array{visao: string, faltando: list<string>, pagina: int, por_pagina: int} $pedido */
    public function ler(array $pedido, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        return $pedido['visao'] === 'pastas_sem_cliente'
            ? $this->pastasSemCliente($pedido['pagina'], $pedido['por_pagina'], $chamada)
            : $this->clientesComPendencia($pedido['faltando'], $pedido['pagina'], $pedido['por_pagina'], $chamada);
    }

    /** @param list<string> $faltando */
    private function clientesComPendencia(array $faltando, int $pagina, int $porPagina, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $achados = [];
        foreach ($this->clientesQueAPessoaVe($chamada) as $cliente) {
            $faltam = $this->quaisFaltam($cliente, $faltando);
            if ($faltam !== []) {
                $achados[] = [$cliente, $faltam];
            }
        }
        usort($achados, static fn (array $a, array $b): int => [mb_strtoupper($a[0]->getNomeExibicao()), (int) $a[0]->getId()] <=> [mb_strtoupper($b[0]->getNomeExibicao()), (int) $b[0]->getId()]);
        $total = \count($achados);
        $daPagina = \array_slice($achados, ($pagina - 1) * $porPagina, $porPagina);
        $pastas = $this->pastasQueAPessoaAbre(array_map(static fn (array $a): int => (int) $a[0]->getId(), $daPagina), $chamada);

        $linhas = [];
        $emSegredo = 0;
        $pastaIds = [];
        foreach ($daPagina as [$cliente, $faltam]) {
            $doCliente = $pastas[(int) $cliente->getId()] ?? ['pastas' => [], 'em_segredo' => 0];
            $mostradas = \array_slice($doCliente['pastas'], 0, self::PASTAS_POR_CLIENTE);
            $linha = [
                'id' => (int) $cliente->getId(),
                'nome' => $this->nome($escritorio, $cliente->getNomeExibicao(), 'sem nome'),
                'tipo' => $cliente instanceof ClientePJ ? 'PJ' : 'PF',
                'campos_que_faltam' => $faltam,
                'pastas' => array_map(fn (Pasta $p): array => [
                    'id' => (int) $p->getId(),
                    'numero' => $this->protecao->rotulo($escritorio, (string) $p->getNup(), 'sem número'),
                ], $mostradas),
            ];
            if (\count($doCliente['pastas']) > \count($mostradas)) {
                $linha['pastas_a_mais'] = \count($doCliente['pastas']) - \count($mostradas);
            }
            if ($doCliente['em_segredo'] > 0) {
                $linha['pastas_em_segredo'] = $doCliente['em_segredo'];
            }
            $emSegredo += $doCliente['em_segredo'];
            array_push($pastaIds, ...array_map(static fn (Pasta $p): int => (int) $p->getId(), $mostradas));
            $linhas[] = $linha;
        }

        return new RespostaDaFerramenta(
            [
                'visao' => 'clientes',
                'faltando' => $faltando,
                'clientes' => $linhas,
                'total' => $total,
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'pastas_omitidas_em_segredo' => $emSegredo,
            ],
            proximaPagina: $pagina * $porPagina < $total ? $pagina + 1 : null,
            contagens: ['clientes' => \count($linhas), 'total' => $total, 'em_segredo' => $emSegredo],
            ids: ['cliente' => array_column($linhas, 'id'), 'pasta' => array_values(array_unique($pastaIds))],
        );
    }

    private function pastasSemCliente(int $pagina, int $porPagina, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $todas = $this->pastas->ativasSemClienteVinculado($escritorio);
        $abertas = $this->soAsQueAPessoaAbre($todas, $chamada);
        $foraDoSegredo = $this->segredo->tirarDe($abertas, static fn (Pasta $p): int => (int) $p->getId(), $escritorio);
        $total = \count($foraDoSegredo['itens']);
        // Quem tem acesso restrito só conta o que está no seu escopo: as pastas de fora nem existem para ele, e
        // contá-las revelaria quantas pastas sem cliente o escritório tem.
        $noEscopo = $this->escopo->idsNoEscopo($chamada->contexto->pessoa, $escritorio);
        $doEscopo = $noEscopo === null ? null : array_flip($noEscopo);
        $contaveis = $doEscopo === null ? $todas : array_values(array_filter($todas, static fn (Pasta $p): bool => isset($doEscopo[(int) $p->getId()])));
        $semAcesso = max(0, \count($contaveis) - \count($abertas));
        $daPagina = \array_slice($foraDoSegredo['itens'], ($pagina - 1) * $porPagina, $porPagina);

        $linhas = array_map(function (Pasta $p) use ($escritorio): array {
            $escrito = trim((string) $p->getNomeCliente());

            return [
                'id' => (int) $p->getId(),
                'numero' => $this->protecao->rotulo($escritorio, (string) $p->getNup(), 'sem número'),
                'nome_cliente_escrito' => $escrito === '' ? null : $this->nome($escritorio, $escrito, ''),
            ];
        }, $daPagina);

        return new RespostaDaFerramenta(
            [
                'visao' => 'pastas_sem_cliente',
                'pastas' => $linhas,
                'total' => $total,
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'omitidas_em_segredo' => $foraDoSegredo['em_segredo'],
                'omitidas_sem_acesso' => $semAcesso,
            ],
            proximaPagina: $pagina * $porPagina < $total ? $pagina + 1 : null,
            contagens: ['pastas' => \count($linhas), 'total' => $total, 'em_segredo' => $foraDoSegredo['em_segredo'], 'sem_acesso' => $semAcesso],
            ids: ['pasta' => array_column($linhas, 'id')],
        );
    }

    /**
     * Os clientes do escritório que a pessoa vê: a consulta pelo escritório e o recurso `cliente` de ver em lote (o
     * mesmo teto do `canAccessResource`, com o escopo do acesso restrito).
     *
     * @return list<Cliente>
     */
    private function clientesQueAPessoaVe(ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        $doEscritorio = [];
        foreach ($this->clientes->findBy(['tenant' => $contexto->escritorio]) as $cliente) {
            // Defesa em profundidade: a consulta já filtra o escritório.
            if ($cliente->getTenant()?->getId() === $contexto->escritorio->getId()) {
                $doEscritorio[(int) $cliente->getId()] = $cliente;
            }
        }
        $permitidos = $this->permissoes->idsPermitidosComVinculo($contexto->pessoa, $contexto->vinculo, AccessRequest::RESOURCE_CLIENTE, array_keys($doEscritorio), AccessRequest::ACTION_VIEW);

        return array_values(array_intersect_key($doEscritorio, array_flip($permitidos)));
    }

    /**
     * Dos campos pedidos, os que faltam no cliente; campo de outro tipo de cadastro (CPF numa PJ) não conta.
     *
     * @param list<string> $faltando
     *
     * @return list<string>
     */
    private function quaisFaltam(Cliente $cliente, array $faltando): array
    {
        $vazios = [];
        foreach (array_intersect_key($this->campos->fotografar($cliente), CamposDoCadastro::ROTULOS) as $chave => $valor) {
            $vazios[$chave] = trim($valor) === '';
        }
        $faltam = [];
        foreach ($faltando as $campo) {
            $doCampo = $campo === 'endereco' ? self::CAMPOS_DO_ENDERECO : [$campo];
            foreach ($doCampo as $chave) {
                if ($vazios[$chave] ?? false) {
                    $faltam[] = $campo;
                    break;
                }
            }
        }

        return $faltam;
    }

    /**
     * As pastas vivas de cada cliente que a pessoa abre (recurso `pasta` de ver, com o cadeado), sem as em segredo de
     * justiça, que só contam.
     *
     * @param list<int> $clienteIds
     *
     * @return array<int, array{pastas: list<Pasta>, em_segredo: int}>
     */
    private function pastasQueAPessoaAbre(array $clienteIds, ChamadaDoConector $chamada): array
    {
        $porCliente = $this->pastas->pastasVivasDosClientes($clienteIds, $chamada->contexto->escritorio);
        $todas = [];
        foreach ($porCliente as $pastas) {
            foreach ($pastas as $pasta) {
                $todas[(int) $pasta->getId()] = $pasta;
            }
        }
        $abertas = array_flip(array_map(static fn (Pasta $p): int => (int) $p->getId(), $this->soAsQueAPessoaAbre(array_values($todas), $chamada)));
        $foraDoSegredo = array_flip($this->segredo->tirar(array_keys($abertas), $chamada->contexto->escritorio)['ids']);

        $resultado = [];
        foreach ($porCliente as $clienteId => $pastas) {
            $visiveis = array_values(array_filter($pastas, static fn (Pasta $p): bool => isset($abertas[(int) $p->getId()])));
            $ficam = array_values(array_filter($visiveis, static fn (Pasta $p): bool => isset($foraDoSegredo[(int) $p->getId()])));
            $resultado[$clienteId] = ['pastas' => $ficam, 'em_segredo' => \count($visiveis) - \count($ficam)];
        }

        return $resultado;
    }

    /**
     * As pastas que a pessoa abre: o cadeado do lote numa consulta ({@see AcessoAPasta::niveisEmLote()}) e o recurso
     * `pasta` de ver em lote, a mesma regra que abre a pasta.
     *
     * @param list<Pasta> $pastas
     *
     * @return list<Pasta>
     */
    private function soAsQueAPessoaAbre(array $pastas, ChamadaDoConector $chamada): array
    {
        if ($pastas === []) {
            return [];
        }
        $contexto = $chamada->contexto;
        $this->acessoAPasta->niveisEmLote($contexto->pessoa, $pastas);
        $permitidas = array_flip($this->permissoes->idsPermitidosComVinculo(
            $contexto->pessoa,
            $contexto->vinculo,
            AccessRequest::RESOURCE_PASTA,
            array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas),
            AccessRequest::ACTION_VIEW,
        ));

        return array_values(array_filter($pastas, static fn (Pasta $p): bool => isset($permitidas[(int) $p->getId()])));
    }

    /** Um nome de uma linha, pela proteção do texto e cortado. */
    private function nome(Tenant $escritorio, string $texto, string $vazio): string
    {
        $valor = $this->protecao->rotulo($escritorio, $texto, $vazio);

        return mb_strlen($valor) > self::TAMANHO_DO_NOME ? mb_substr($valor, 0, self::TAMANHO_DO_NOME) . '…' : $valor;
    }

    /**
     * @return list<string>
     *
     * @throws ErroDaFerramenta
     */
    private static function faltando(mixed $valor): array
    {
        $aceitos = self::camposAceitos();
        if (!\is_array($valor) || $valor === [] || !array_is_list($valor) || \count($valor) > \count($aceitos)) {
            throw ErroDaFerramenta::filtroInvalido('faltando');
        }
        foreach ($valor as $campo) {
            if (!\is_string($campo) || !\in_array($campo, $aceitos, true)) {
                throw ErroDaFerramenta::filtroInvalido('faltando');
            }
        }

        return array_values(array_unique($valor));
    }

    /**
     * O inteiro do campo (número ou texto só de dígitos) entre o mínimo e o máximo; ausente, o padrão.
     *
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    private static function inteiro(array $argumentos, string $campo, int $minimo, int $maximo, int $padrao): int
    {
        if (!\array_key_exists($campo, $argumentos)) {
            return $padrao;
        }
        $valor = $argumentos[$campo];
        if (\is_string($valor) && ctype_digit($valor) && \strlen($valor) <= 9) {
            $valor = (int) $valor;
        }
        if (!\is_int($valor) || $valor < $minimo || $valor > $maximo) {
            throw ErroDaFerramenta::filtroInvalido($campo);
        }

        return $valor;
    }
}
