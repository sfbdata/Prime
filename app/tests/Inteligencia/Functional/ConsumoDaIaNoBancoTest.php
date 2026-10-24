<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A soma de tokens do mês contra o banco de verdade (orçamento em US$, PR C de ia-anthropic-ligar): tenant explícito,
 * agrupada por modelo, com as excluídas e as falhas (a Anthropic cobrou), sem o mês anterior nem outro escritório.
 */
#[CoversClass(AnaliseDeInteligenciaRepository::class)]
final class ConsumoDaIaNoBancoTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function repositorio(): AnaliseDeInteligenciaRepository
    {
        return static::getContainer()->get(AnaliseDeInteligenciaRepository::class);
    }

    private function inicioDoMes(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('first day of this month midnight');
    }

    /** Uma linha com tokens gravados à mão (status, modelo, exclusão e data), sem passar pela máquina de estados. */
    private function analise(Tenant $tenant, User $user, ?string $modelo, ?int $entrada, ?int $saida, string $status = 'concluida', bool $excluida = false, ?string $criadaEm = null, ?string $erro = null): void
    {
        $analise = $this->criarAnalisePendente($tenant, $user, $this->criarPasta($tenant));
        $this->em()->getConnection()->executeStatement(
            'UPDATE inteligencia_analise SET status = ?, modelo = ?, tokens_entrada = ?, tokens_saida = ?, excluida_em = ?, criada_em = COALESCE(?, criada_em), erro_motivo = ? WHERE id = ?',
            [$status, $modelo, $entrada, $saida, $excluida ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null, $criadaEm, $erro, (int) $analise->getId()],
        );
    }

    /**
     * @param list<array{modelo: ?string, tokensEntrada: int, tokensSaida: int}> $linhas
     *
     * @return array<string, array{int, int}>
     */
    private static function porModelo(array $linhas): array
    {
        $saida = [];
        foreach ($linhas as $l) {
            $saida[(string) $l['modelo']] = [$l['tokensEntrada'], $l['tokensSaida']];
        }
        ksort($saida);

        return $saida;
    }

    #[TestDox('soma por modelo do escritório, com excluída e falha; sem mês anterior, sem outro escritório, sem linha sem tokens')]
    public function testSomaDoMesComTenantExplicito(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$outroUser, $outro] = $this->criarAdmin();
        $haiku = ModeloDeIa::Haiku55->value;
        $sonnet = ModeloDeIa::Sonnet55->value;

        $this->analise($tenant, $user, $haiku, 1000, 200);
        $this->analise($tenant, $user, $haiku, 500, 100, excluida: true);
        $this->analise($tenant, $user, $sonnet, 300, 30, status: 'falhou', erro: 'resposta inválida');
        $this->analise($tenant, $user, null, null, null, status: 'pendente');
        $this->analise($tenant, $user, $haiku, 9_000_000, 9_000_000, criadaEm: $this->inicioDoMes()->modify('-1 second')->format('Y-m-d H:i:s'));
        $this->analise($outro, $outroUser, $haiku, 7_000_000, 7_000_000);

        $linhas = $this->repositorio()->somarTokensPorModeloDesde($tenant, $this->inicioDoMes());

        self::assertSame([$haiku => [1500, 300], $sonnet => [300, 30]], self::porModelo($linhas));
        self::assertSame([$haiku => [7_000_000, 7_000_000]], self::porModelo($this->repositorio()->somarTokensPorModeloDesde($outro, $this->inicioDoMes())));
    }

    #[TestDox('consumo de todos os escritórios numa consulta: análises da cota e tokens por escritório e modelo; cancelada antes do provedor não conta')]
    public function testConsumoDosEscritorios(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$outroUser, $outro] = $this->criarAdmin();
        $haiku = ModeloDeIa::Haiku55->value;

        $this->analise($tenant, $user, $haiku, 1000, 200);
        $this->analise($tenant, $user, $haiku, 500, 100, excluida: true);
        $this->analise($tenant, $user, null, null, null, status: 'indisponivel', erro: AnaliseDeInteligenciaRepository::MOTIVO_CANCELADA_ANTES_DO_PROVEDOR . 'desligada');
        $this->analise($tenant, $user, $haiku, 50, 50, criadaEm: $this->inicioDoMes()->modify('-1 day')->format('Y-m-d H:i:s'));
        $this->analise($outro, $outroUser, $haiku, 10, 20);

        $porEscritorio = [];
        foreach ($this->repositorio()->consumoDosEscritoriosDesde($this->inicioDoMes()) as $l) {
            if (\in_array($l['tenantId'], [(int) $tenant->getId(), (int) $outro->getId()], true)) {
                $porEscritorio[$l['tenantId']][(string) $l['modelo']] = [$l['analises'], $l['tokensEntrada'], $l['tokensSaida']];
            }
        }

        $doTenant = $porEscritorio[(int) $tenant->getId()];
        ksort($doTenant);
        self::assertSame(['' => [0, 0, 0], $haiku => [2, 1500, 300]], $doTenant, 'a cancelada antes do provedor fica na linha sem modelo, com zero análises');
        self::assertSame([$haiku => [1, 10, 20]], $porEscritorio[(int) $outro->getId()]);
    }
}
