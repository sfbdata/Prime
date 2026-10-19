<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Repository\PainelMasterRepository;
use App\Contribua\DTO\PlanoDoCatalogo;
use App\Contribua\Service\CatalogoDePlanos;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Master\Service\RotuloDeAuditoria;
use App\Master\Service\RotuloDeAuditoriaDoEspaco;
use Psr\Clock\ClockInterface;

/**
 * "Segurança e auditoria" do Painel do Master: a trilha administrativa do `audit_log` (contas,
 * usuários, vínculos, perfis, permissões, convites e purgas), traduzida para rótulo humano.
 *
 * O `changes` nunca sai cru: só o rótulo da ação e os NOMES dos campos alterados
 * ({@see RotuloDeAuditoria}). Entrada no sistema (login) não é auditada como evento próprio.
 */
final class ConsultarAuditoriaUseCase
{
    public const LIMITE = 1000;

    public const MESES = 12;

    /** @var array<int, array{cotaGb: int, limiteUsuarios: ?int}|null> plano de hoje de cada conta, nesta consulta */
    private array $planos = [];

    /** @var array<string, PlanoDoCatalogo>|null catálogo por código, lido uma vez por consulta */
    private ?array $catalogoPorCodigo = null;

    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly SituacaoDoEspaco $situacaoDoEspaco,
        private readonly CatalogoDePlanos $catalogo,
    ) {
    }

    /**
     * @return array{registros: list<array{quando: string, quem: string, acao: string, conta: string, contaId: ?int, detalhe: string}>, limite: int, meses: int, truncado: bool}
     */
    public function executar(): array
    {
        [$this->planos, $this->catalogoPorCodigo] = [[], null];
        $linhas = $this->repositorio->trilhaAdministrativa(self::LIMITE, $this->relogio->now()->modify(sprintf('-%d months', self::MESES)));

        $registros = [];
        foreach ($linhas as $l) {
            $plano = RotuloDeAuditoriaDoEspaco::ehLimiteDaConta($l['entidade']) && $l['tenantId'] !== null ? $this->planoDaConta($l['tenantId']) : null;
            $rotulo = RotuloDeAuditoria::traduzir($l['acao'], $l['entidade'], $l['mudancas'], $plano);
            $registros[] = [
                'quando'  => (new \DateTimeImmutable($l['criadoEm']))->format(\DATE_ATOM),
                'quem'    => self::responsavel($l['responsavel'], $l['responsavelId']),
                'acao'    => $rotulo['acao'],
                'conta'   => self::conta($l),
                'contaId' => $l['tenantId'],
                'detalhe' => $rotulo['detalhe'],
            ];
        }

        return ['registros' => $registros, 'limite' => self::LIMITE, 'meses' => self::MESES, 'truncado' => count($linhas) >= self::LIMITE];
    }

    /**
     * Cota e limite de usuários de HOJE do plano da conta: o "anterior" da primeira mudança de limite (o desenho
     * mostra "10 GB → 12 GB"). Leitura pública da Contribua: o plano pelo retrato, os valores pelo catálogo.
     *
     * @return array{cotaGb: int, limiteUsuarios: ?int}|null
     */
    private function planoDaConta(int $tenantId): ?array
    {
        if (!array_key_exists($tenantId, $this->planos)) {
            $this->catalogoPorCodigo ??= array_column(array_map(static fn (PlanoDoCatalogo $p): array => ['codigo' => $p->codigo, 'plano' => $p], $this->catalogo->todos()), 'plano', 'codigo');
            $plano = $this->catalogoPorCodigo[$this->situacaoDoEspaco->retratar($tenantId, 0)->planoCodigo] ?? null;
            $this->planos[$tenantId] = $plano === null ? null : ['cotaGb' => $plano->cotaGb, 'limiteUsuarios' => $plano->limiteUsuarios];
        }

        return $this->planos[$tenantId];
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
