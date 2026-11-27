<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use App\Contribua\UseCase\ListarContasPagantesUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O filtro "Pagantes" do Painel do Master: quem tem assinatura paga valendo hoje, com plano, valor por mês, até
 * quando vale e se está em carência. Carência configurada de 7 dias; "hoje" é 27/11/2026 12:00. A regra sem banco
 * (`pagantes`); a leitura de todos os escritórios pela VisaoDoMaster se prova no ContasPagantesNoPainelTest.
 */
#[CoversClass(ListarContasPagantesUseCase::class)]
final class ListarContasPagantesUseCaseTest extends TestCase
{
    private const HOJE = '2026-11-27 12:00';

    #[TestDox('Ativa e no período pago: entra com plano, GB, valor por mês e "vale até", sem carência')]
    public function testAtivaNoPeriodo(): void
    {
        $a = $this->assinatura(10, $this->essencial(), SituacaoDaAssinatura::Ativa, '2026-12-20');

        self::assertSame(
            [10 => ['plano' => 'Essencial', 'gb' => 20, 'valorCentavos' => 1990, 'valeAte' => '2026-12-20', 'carencia' => false]],
            $this->listar([$a]),
        );
    }

    #[TestDox('Cobrança recusada (Inadimplente) dentro da carência entra marcada como carência')]
    public function testInadimplenteEmCarencia(): void
    {
        $a = $this->assinatura(11, $this->essencial(), SituacaoDaAssinatura::Ativa, '2026-12-20');
        $a->aplicar(new SituacaoNoGateway('sub_1', SituacaoDaAssinatura::Inadimplente, null));

        $lista = $this->listar([$a]);

        self::assertTrue($lista[11]['carencia']);
        self::assertSame('2026-12-20', $lista[11]['valeAte']);
    }

    #[TestDox('Ativa com o período pago vencido há poucos dias (renovação atrasada) entra marcada como carência')]
    public function testAtivaVencidaDentroDaCarencia(): void
    {
        $a = $this->assinatura(12, $this->essencial(), SituacaoDaAssinatura::Ativa, '2026-11-24');

        self::assertTrue($this->listar([$a])[12]['carencia']);
    }

    #[TestDox('Ativa vencida além da carência, cancelada e aguardando confirmação não entram')]
    public function testForaDosPagantes(): void
    {
        $vencida = $this->assinatura(13, $this->essencial(), SituacaoDaAssinatura::Ativa, '2026-11-10');
        $cancelada = $this->assinatura(14, $this->essencial(), SituacaoDaAssinatura::Ativa, '2026-12-20');
        $cancelada->cancelar();
        $aguardando = new AssinaturaDeEspaco($this->tenant(15), $this->essencial(), 'falso', 1990, null);

        self::assertSame([], $this->listar([$vencida, $cancelada, $aguardando]));
    }

    #[TestDox('Duas valendo na mesma conta: fica a de mais GB (já multiplicado); o multiplicador aparece no plano')]
    public function testMaiorDaConta(): void
    {
        $tenant = $this->tenant(16);
        $menor = $this->assinatura(16, $this->essencial(), SituacaoDaAssinatura::Ativa, '2026-12-20', $tenant);
        $maior = new AssinaturaDeEspaco($tenant, $this->essencial(), 'falso', 3980, null, 2);
        $maior->aplicar(new SituacaoNoGateway('sub_2', SituacaoDaAssinatura::Ativa, new \DateTimeImmutable('2026-12-15')));

        $lista = $this->listar([$menor, $maior]);

        self::assertSame(['plano' => 'Essencial ×2', 'gb' => 40, 'valorCentavos' => 3980, 'valeAte' => '2026-12-15', 'carencia' => false], $lista[16]);
    }

    #[TestDox('Sem assinatura paga: lista vazia')]
    public function testSemPagante(): void
    {
        self::assertSame([], $this->listar([]));
    }

    /**
     * @param list<AssinaturaDeEspaco> $assinaturas
     *
     * @return array<int, array<string, mixed>>
     */
    private function listar(array $assinaturas): array
    {
        return ListarContasPagantesUseCase::pagantes($assinaturas, new \DateTimeImmutable(self::HOJE), 7);
    }

    private function assinatura(int $tenantId, Plano $plano, SituacaoDaAssinatura $situacao, string $pagaAte, ?Tenant $tenant = null): AssinaturaDeEspaco
    {
        $a = new AssinaturaDeEspaco($tenant ?? $this->tenant($tenantId), $plano, 'falso', $plano->getPrecoCentavos(), null);
        $a->aplicar(new SituacaoNoGateway('sub_' . $tenantId, $situacao, new \DateTimeImmutable($pagaAte . ' 10:00')));

        return $a;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function essencial(): Plano
    {
        return new Plano('essencial', 'Essencial', 20, 1990, 'Para o escritório que cresce', 2, 8);
    }
}
