<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Repository\PainelMasterRepository;
use App\Contribua\Service\CatalogoDePlanos;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Master\Service\RotuloDeAuditoria;
use App\Master\Service\RotuloDeAuditoriaDoEspaco;
use Psr\Clock\ClockInterface;

/**
 * "Segurança e auditoria" do Painel do Master: a trilha administrativa do `audit_log` (contas,
 * usuários, vínculos, perfis, permissões, convites, purgas e, de plataforma, telas e recursos e as seccionais
 * da OAB), traduzida para rótulo humano.
 *
 * O `changes` nunca sai cru: só o rótulo da ação e os NOMES dos campos alterados
 * ({@see RotuloDeAuditoria}). Entrada no sistema (login) não é auditada como evento próprio.
 *
 * Paginação no servidor: o filtro de ação é o RÓTULO traduzido (não existe no banco), então a janela
 * (até {@see self::LIMITE} linhas, últimos {@see self::MESES} meses) é traduzida inteira, filtrada por ação e conta
 * e só a página pedida sai na resposta. Os totais, a lista de ações e as pílulas do resumo valem para o filtro
 * inteiro, não só para a página. `todas` devolve o filtro inteiro (o PDF).
 */
final class ConsultarAuditoriaUseCase
{
    public const LIMITE = 1000;

    public const MESES = 12;

    public const POR_PAGINA_PADRAO = 50;

    /** Opções do "por página" da tela; valor fora da lista cai no padrão. */
    public const POR_PAGINA_OPCOES = [20, 50, 100];

    /** Pílulas do resumo: chave => padrão do rótulo da ação (os mesmos que a tela usava). */
    private const RESUMO = [
        'limites' => '/Limite/u',
        'contas'  => '/Conta/u',
        'masters' => '/Master|primário/iu',
        'alertas' => '/Alerta/u',
    ];

    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly SituacaoDoEspaco $situacaoDoEspaco,
        private readonly CatalogoDePlanos $catalogo,
    ) {
    }

    /**
     * @param string   $tipo  rótulo exato da ação ('' = todas as ações)
     * @param int|null $conta id do escritório (null = todas as contas)
     * @param bool     $todas true devolve o filtro inteiro, sem paginar (PDF)
     *
     * @return array{registros: list<array{quando: string, quem: string, acao: string, conta: string, contaId: ?int, detalhe: string}>, total: int, totalGeral: int, pagina: int, porPagina: int, paginas: int, tipos: list<string>, resumo: array<string, int>, limite: int, meses: int, truncado: bool}
     */
    public function executar(int $pagina = 1, int $porPagina = self::POR_PAGINA_PADRAO, string $tipo = '', ?int $conta = null, bool $todas = false): array
    {
        $linhas = $this->repositorio->trilhaAdministrativa(self::LIMITE, $this->relogio->now()->modify(sprintf('-%d months', self::MESES)));
        $planos = $this->planosDasContas($linhas);

        $registros = [];
        foreach ($linhas as $l) {
            $plano = RotuloDeAuditoriaDoEspaco::ehLimiteDaConta($l['entidade']) && $l['tenantId'] !== null ? ($planos[$l['tenantId']] ?? null) : null;
            $rotulo = RotuloDeAuditoria::traduzir($l['acao'], $l['entidade'], $l['mudancas'], $plano, $l['recurso']);
            $registros[] = [
                'quando'  => (new \DateTimeImmutable($l['criadoEm']))->format(\DATE_ATOM),
                'quem'    => self::responsavel($l['responsavel'], $l['responsavelId']),
                'acao'    => $rotulo['acao'],
                'conta'   => self::conta($l),
                'contaId' => $l['tenantId'],
                'detalhe' => $rotulo['detalhe'],
            ];
        }

        $tipos = array_values(array_unique(array_column($registros, 'acao')));
        (new \Collator('pt_BR'))->sort($tipos);

        $filtrados = array_values(array_filter(
            $registros,
            static fn (array $r): bool => ($conta === null || $r['contaId'] === $conta) && ($tipo === '' || $r['acao'] === $tipo),
        ));
        $resumo = [];
        foreach (self::RESUMO as $chave => $padrao) {
            $resumo[$chave] = count(array_filter($filtrados, static fn (array $r): bool => preg_match($padrao, $r['acao']) === 1));
        }

        $total = count($filtrados);
        $porPagina = in_array($porPagina, self::POR_PAGINA_OPCOES, true) ? $porPagina : self::POR_PAGINA_PADRAO;
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $paginas);

        return [
            'registros'  => $todas ? $filtrados : array_slice($filtrados, ($pagina - 1) * $porPagina, $porPagina),
            'total'      => $total,
            'totalGeral' => count($registros),
            'pagina'     => $todas ? 1 : $pagina,
            'porPagina'  => $porPagina,
            'paginas'    => $todas ? 1 : $paginas,
            'tipos'      => $tipos,
            'resumo'     => $resumo,
            'limite'     => self::LIMITE,
            'meses'      => self::MESES,
            'truncado'   => count($linhas) >= self::LIMITE,
        ];
    }

    /**
     * Cota e limite de usuários de HOJE do plano de cada conta com linha de limite na trilha: o "anterior" da
     * primeira mudança (o desenho mostra "10 GB → 12 GB"). Leitura pública da Contribua, em lote: os planos
     * pelos retratos (`retratarVarios`, sem medir o uso) e os valores pelo catálogo, cada um lido uma vez.
     *
     * @param list<array{entidade: string, tenantId: ?int}> $linhas
     *
     * @return array<int, array{cotaGb: int, limiteUsuarios: ?int}>
     */
    private function planosDasContas(array $linhas): array
    {
        $contas = [];
        foreach ($linhas as $l) {
            if ($l['tenantId'] !== null && RotuloDeAuditoriaDoEspaco::ehLimiteDaConta($l['entidade'])) {
                $contas[$l['tenantId']] = 0;
            }
        }
        if ($contas === []) {
            return [];
        }

        $catalogo = [];
        foreach ($this->catalogo->todos() as $p) {
            $catalogo[$p->codigo] = ['cotaGb' => $p->cotaGb, 'limiteUsuarios' => $p->limiteUsuarios];
        }
        $planos = [];
        foreach ($this->situacaoDoEspaco->retratarVarios($contas) as $tenantId => $retrato) {
            if (isset($catalogo[$retrato->planoCodigo])) {
                $planos[$tenantId] = $catalogo[$retrato->planoCodigo];
            }
        }

        return $planos;
    }

    /** Nome do responsável; sem nome, o número do usuário; sem ator, "Sistema". Nunca o e-mail. */
    private static function responsavel(?string $nome, ?int $id): string
    {
        if ($nome !== null) {
            return $nome;
        }

        return $id !== null ? sprintf('Usuário sem nome (nº %d)', $id) : 'Sistema';
    }

    /**
     * @param array{acao: string, mudancas: ?string, tenantId: ?int, conta: ?string} $linha
     */
    private static function conta(array $linha): string
    {
        if ($linha['conta'] !== null) {
            return $linha['conta'];
        }

        if ($linha['acao'] === 'purge' && $linha['mudancas'] !== null) {
            $dados = json_decode($linha['mudancas'], true);
            if (is_array($dados) && is_string($dados['nome'] ?? null)) {
                return $dados['nome'];
            }
        }

        return $linha['tenantId'] !== null ? 'Conta removida' : 'Plataforma (sem conta)';
    }
}
