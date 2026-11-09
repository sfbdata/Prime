<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\OpcaoDaPautaFactory;
use App\Tests\Factory\Voto\ParticipacaoNaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\TokenDaUnidade;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * As consultas dos repositórios da assembleia de condomínio contra o banco (spec voto-assembleia-condominio, seções
 * 2 a 4): escritório escrito em toda consulta, a busca pelo token sem depender do filtro de tenant, o contador por SQL
 * direto e as somas de peso em decimal.
 */
#[CoversClass(AssembleiaRepository::class)]
#[CoversClass(PautaDaAssembleiaRepository::class)]
#[CoversClass(OpcaoDaPautaRepository::class)]
#[CoversClass(UnidadeDaAssembleiaRepository::class)]
#[CoversClass(ParticipacaoNaPautaRepository::class)]
final class AssembleiaRepositoriosTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('As 5 factories persistem, e a participação padrão junta unidade e pauta da mesma assembleia')]
    public function testFactoriesPersistem(): void
    {
        $opcao = OpcaoDaPautaFactory::createOne();
        $participacao = ParticipacaoNaPautaFactory::createOne();

        self::assertNotNull($opcao->getId());
        self::assertSame($participacao->getUnidade()->getAssembleia(), $participacao->getPauta()->getAssembleia());
        self::assertSame($participacao->getUnidade()->getTenant(), $participacao->getTenant());
    }

    #[TestDox('Lista só as assembleias do escritório, mais recentes primeiro; a de outro escritório não é achada pelo id')]
    public function testAssembleiasDoEscritorio(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $antiga = AssembleiaFactory::createOne(['tenant' => $tenant])->_real();
        $nova = AssembleiaFactory::createOne(['tenant' => $tenant])->_real();
        $alheia = AssembleiaFactory::createOne()->_real();
        $repositorio = static::getContainer()->get(AssembleiaRepository::class);

        $lista = $repositorio->listarDoEscritorio($tenant);

        self::assertSame([$nova->getId(), $antiga->getId()], array_map(static fn ($a) => $a->getId(), $lista));
        self::assertNull($repositorio->buscarDoEscritorio((int) $alheia->getId(), $tenant));
        self::assertSame($antiga, $repositorio->buscarDoEscritorio((int) $antiga->getId(), $tenant));
    }

    #[TestDox('A unidade é achada pelo hash do token com o filtro de tenant de OUTRO escritório ligado, e o filtro volta')]
    public function testBuscaPeloTokenSemDependerDoFiltro(): void
    {
        $unidade = UnidadeDaAssembleiaFactory::createOne()->_real();
        $outro = TenantFactory::createOne()->_real();
        $token = new TokenDaUnidade((string) static::getContainer()->getParameter('kernel.secret'));
        $hash = $token->hash($token->token($unidade->getSemente()));
        self::assertSame($unidade->getTokenHash(), $hash, 'a factory calcula o hash com o segredo do kernel');
        self::assertSame($token->token($unidade->getSemente()), UnidadeDaAssembleiaFactory::tokenDe($unidade));
        $this->em->clear();
        $repositorio = static::getContainer()->get(UnidadeDaAssembleiaRepository::class);

        $filtros = $this->em->getFilters();
        $filtros->enable('tenant')->setParameter('tenant', (int) $outro->getId(), Types::INTEGER);
        try {
            $achada = $repositorio->findOneByTokenHash($hash);

            self::assertSame($unidade->getId(), $achada?->getId());
            self::assertTrue($filtros->isEnabled('tenant'), 'o filtro volta ligado');
        } finally {
            $filtros->disable('tenant');
        }
        self::assertNull($repositorio->findOneByTokenHash(str_repeat('0', 64)));
        self::assertNull($repositorio->findOneByTokenHash('nao-e-hash'));
    }

    #[TestDox('O voto soma no contador da opção por SQL direto, e só na opção da pauta e do escritório')]
    public function testSomarVoto(): void
    {
        $pauta = PautaDaAssembleiaFactory::createOne()->_real();
        $sim = new OpcaoDaPauta($pauta, 1, 'Sim');
        $nao = new OpcaoDaPauta($pauta, 2, 'Não');
        $this->em->flush();
        $repositorio = static::getContainer()->get(OpcaoDaPautaRepository::class);

        $repositorio->somarVoto($sim, '0.025000');
        $repositorio->somarVoto($sim, '0.0310');
        $this->em->refresh($sim);
        $this->em->refresh($nao);

        self::assertSame(2, $sim->getTotalUnidades());
        self::assertSame('0.056000', $sim->getTotalPeso());
        self::assertSame(0, $nao->getTotalUnidades());
        $this->expectException(\InvalidArgumentException::class);
        $repositorio->somarVoto($nao, '-1');
    }

    #[TestDox('Totais aptos: soma das frações das aptas (fração ideal) ou número de aptas (por unidade); inapta fica fora')]
    public function testTotaisAptos(): void
    {
        $porFracao = AssembleiaFactory::new()->porFracao()->create()->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $porFracao, 'fracao' => '0.025000']);
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $porFracao, 'fracao' => '0.031000']);
        UnidadeDaAssembleiaFactory::new()->inapta()->create(['assembleia' => $porFracao, 'fracao' => '0.500000']);
        $porUnidade = AssembleiaFactory::createOne()->_real();
        UnidadeDaAssembleiaFactory::createMany(3, ['assembleia' => $porUnidade]);
        UnidadeDaAssembleiaFactory::new()->inapta()->create(['assembleia' => $porUnidade]);
        $repositorio = static::getContainer()->get(UnidadeDaAssembleiaRepository::class);

        self::assertSame(['pesoApto' => '0.056000', 'unidadesAptas' => 2], $repositorio->totaisAptos($porFracao));
        self::assertSame(['pesoApto' => '3.000000', 'unidadesAptas' => 3], $repositorio->totaisAptos($porUnidade));
    }

    #[TestDox('Envio dos links: só quem tem e-mail e ainda não recebeu; "faltam N" bate com a lista')]
    public function testParaEnvioDoLink(): void
    {
        $assembleia = AssembleiaFactory::createOne()->_real();
        $semEnvio = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia])->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'email' => null]);
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'linkEnviadoEm' => new \DateTimeImmutable()]);
        $repositorio = static::getContainer()->get(UnidadeDaAssembleiaRepository::class);

        self::assertSame([$semEnvio], $repositorio->listarParaEnvioDoLink($assembleia));
        self::assertSame(1, $repositorio->contarParaEnvioDoLink($assembleia));
    }

    #[TestDox('Participação: contagem e peso por pauta (inclusive a sem voto), lista nominal, já votou e horários')]
    public function testParticipacoes(): void
    {
        $assembleia = AssembleiaFactory::new()->porFracao()->publicada()->create()->_real();
        $contas = PautaDaAssembleiaFactory::new()->aberta()->create(['assembleia' => $assembleia, 'ordem' => 1])->_real();
        $sindico = PautaDaAssembleiaFactory::new()->aberta()->create(['assembleia' => $assembleia, 'ordem' => 2])->_real();
        $b101 = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'bloco' => 'B', 'identificacao' => '101', 'fracao' => '0.031000'])->_real();
        $a101 = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'bloco' => 'A', 'identificacao' => '101', 'fracao' => '0.025000'])->_real();
        $quando = new \DateTimeImmutable('2026-11-10 19:05:00');
        $this->em->persist(new ParticipacaoNaPauta($b101, $contas, $quando));
        $this->em->persist(new ParticipacaoNaPauta($a101, $contas, $quando));
        $this->em->flush();
        $repositorio = static::getContainer()->get(ParticipacaoNaPautaRepository::class);

        $contagem = $repositorio->contarPorPauta($assembleia);

        self::assertSame(['unidades' => 2, 'peso' => '0.056000'], $contagem[$contas->getId()]);
        self::assertSame(['unidades' => 0, 'peso' => '0.000000'], $contagem[$sindico->getId()]);
        self::assertSame([$a101, $b101], array_map(static fn (ParticipacaoNaPauta $p) => $p->getUnidade(), $repositorio->listarNominalDaPauta($contas)));
        self::assertTrue($repositorio->jaVotou($a101, $contas));
        self::assertFalse($repositorio->jaVotou($a101, $sindico));
        self::assertEquals([$contas->getId() => $quando], $repositorio->horariosDaUnidade($a101));
    }

    #[TestDox('A pauta travada para o voto é relida do banco dentro da transação e só se for da assembleia')]
    public function testPautaTravada(): void
    {
        $pauta = PautaDaAssembleiaFactory::new()->aberta()->create()->_real();
        $outraAssembleia = AssembleiaFactory::createOne(['tenant' => $pauta->getTenant()])->_real();
        $repositorio = static::getContainer()->get(PautaDaAssembleiaRepository::class);

        $travada = $this->em->wrapInTransaction(static fn () => $repositorio->buscarTravadaDaAssembleia((int) $pauta->getId(), $pauta->getAssembleia()));
        $deOutra = $this->em->wrapInTransaction(static fn () => $repositorio->buscarTravadaDaAssembleia((int) $pauta->getId(), $outraAssembleia));

        self::assertSame($pauta, $travada);
        self::assertNull($deOutra);
        self::assertNull($repositorio->buscarDaAssembleia((int) $pauta->getId(), $outraAssembleia));
    }
}
