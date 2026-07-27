<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\QualificacaoContato;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\EventoHistoricoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `EventoHistoricoRepository::ultimaQualificacaoDoCaso` contra o BANCO REAL.
 *
 * Esta consulta É a quarta condição de desfazer da spec §3.5 — a única que o evento sozinho não sabe
 * responder. Nos testes unitários do UseCase o repositório é mock, então filtro de tenant, filtro de
 * tipo e desempate por id não eram provados por nada: a DQL nunca rodava. Aqui roda.
 *
 * O que cada teste trava: um evento de OUTRO tipo no topo não pode ser confundido com a última
 * qualificação (senão a condição 4 recusaria o desfazer legítimo de quem qualificou e depois anotou);
 * qualificação de OUTRO caso ou de OUTRO tenant não pode vazar para cá (seria a condição 4 respondendo
 * sobre dado alheio); e dois cliques no MESMO instante precisam de vencedor determinístico, senão
 * "quem é a última" fica a critério do plano de execução do Postgres.
 */
#[CoversClass(EventoHistoricoRepository::class)]
final class UltimaQualificacaoRepositoryTest extends KernelTestCase
{
    use Factories;

    private EventoHistoricoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var EventoHistoricoRepository $repo */
        $repo = $em->getRepository(EventoHistorico::class);
        $this->repo = $repo;
    }

    #[Test]
    #[TestDox('Sem nenhuma qualificação, o caso devolve null — e nada é desfazível')]
    public function casoSemQualificacaoDevolveNull(): void
    {
        $caso = $this->caso();
        $this->evento($caso, TipoEventoHistorico::Anotacao, '2026-07-27 14:00:00');

        self::assertNull($this->repo->ultimaQualificacaoDoCaso($caso));
    }

    #[Test]
    #[TestDox('Evento mais novo de OUTRO tipo não passa por cima da última qualificação')]
    public function outroTipoNaoRoubaOTopo(): void
    {
        $caso = $this->caso();
        $qualificacao = $this->evento($caso, TipoEventoHistorico::QualificacaoContato, '2026-07-27 14:00:00');
        // Anotação registrada DEPOIS: sem o filtro de tipo ela seria "a mais recente" e o autor da
        // qualificação perderia o direito de desfazer o próprio clique.
        $this->evento($caso, TipoEventoHistorico::Anotacao, '2026-07-27 14:04:00');

        self::assertSame($qualificacao->getId(), $this->repo->ultimaQualificacaoDoCaso($caso)?->getId());
    }

    #[Test]
    #[TestDox('Entre duas qualificações, vence a de ocorridoEm mais recente')]
    public function venceMaisRecentePorData(): void
    {
        $caso = $this->caso();
        $this->evento($caso, TipoEventoHistorico::QualificacaoContato, '2026-07-27 14:00:00');
        $ultima = $this->evento($caso, TipoEventoHistorico::QualificacaoContato, '2026-07-27 14:03:00');

        self::assertSame($ultima->getId(), $this->repo->ultimaQualificacaoDoCaso($caso)?->getId());
    }

    #[Test]
    #[TestDox('Dois cliques no MESMO instante: vence o de maior id (desempate determinístico)')]
    public function desempateNoMesmoInstante(): void
    {
        $caso = $this->caso();
        $instante = '2026-07-27 14:00:00';
        $primeiro = $this->evento($caso, TipoEventoHistorico::QualificacaoContato, $instante);
        $segundo = $this->evento($caso, TipoEventoHistorico::QualificacaoContato, $instante);

        $topo = $this->repo->ultimaQualificacaoDoCaso($caso);

        self::assertNotNull($topo);
        self::assertGreaterThan((int) $primeiro->getId(), (int) $segundo->getId(), 'a segunda inserção tem id maior');
        self::assertSame($segundo->getId(), $topo->getId(), 'sem o addOrderBy(id) o vencedor seria arbitrário');
    }

    #[Test]
    #[TestDox('Qualificação de outro CASO do mesmo tenant não vaza')]
    public function outroCasoNaoVaza(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $caso = $this->caso($tenant);
        $vizinho = $this->caso($tenant);

        $minha = $this->evento($caso, TipoEventoHistorico::QualificacaoContato, '2026-07-27 14:00:00');
        // Mais nova que a minha: se o filtro de caso falhasse, ela é que apareceria no topo.
        $this->evento($vizinho, TipoEventoHistorico::QualificacaoContato, '2026-07-27 14:09:00');

        self::assertSame($minha->getId(), $this->repo->ultimaQualificacaoDoCaso($caso)?->getId());
    }

    #[Test]
    #[TestDox('Qualificação de outro ESCRITÓRIO não vaza nem por engano de dado')]
    public function outroTenantNaoVaza(): void
    {
        // Cenário de dado corrompido, de propósito: o evento aponta para o caso certo mas carrega o
        // tenant errado. É a única forma de exercitar o `e.tenant = :tenant` da consulta — com dado
        // íntegro o filtro de caso já bastaria, e o filtro de tenant passaria sem prova.
        $caso = $this->caso();
        $outroTenant = TenantFactory::createOne()->_real();
        $minha = $this->evento($caso, TipoEventoHistorico::QualificacaoContato, '2026-07-27 14:00:00');
        EventoHistoricoFactory::createOne([
            'tenant' => $outroTenant,
            'caso' => $caso,
            'tipo' => TipoEventoHistorico::QualificacaoContato,
            'descricao' => 'Recusa de pagamento',
            'dados' => ['qualificacao' => QualificacaoContato::RecusaPagamento->value],
            'ocorridoEm' => new \DateTimeImmutable('2026-07-27 14:09:00'),
        ]);

        self::assertSame($minha->getId(), $this->repo->ultimaQualificacaoDoCaso($caso)?->getId());
    }

    private function caso(?Tenant $tenant = null): CasoCobranca
    {
        $tenant ??= TenantFactory::createOne()->_real();

        return CasoCobrancaFactory::createOne(['tenant' => $tenant])->_real();
    }

    private function evento(CasoCobranca $caso, TipoEventoHistorico $tipo, string $ocorridoEm): EventoHistorico
    {
        return EventoHistoricoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'tipo' => $tipo,
            'descricao' => 'Recusa de pagamento',
            'dados' => ['qualificacao' => QualificacaoContato::RecusaPagamento->value],
            'ocorridoEm' => new \DateTimeImmutable($ocorridoEm),
        ])->_real();
    }
}
