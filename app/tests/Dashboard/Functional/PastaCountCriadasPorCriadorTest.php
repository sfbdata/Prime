<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Pasta\Repository\PastaRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Coluna "Pastas Criadas" do Dashboard: conta pasta por QUEM A CRIOU (`criadoPor`), e não
 * por quem responde por ela. As duas medidas divergem de verdade — em produção, 614 das
 * 1.083 pastas têm criador diferente do responsável (medido em 31/08/2026).
 */
#[CoversClass(PastaRepository::class)]
#[Group('dashboard')]
final class PastaCountCriadasPorCriadorTest extends KernelTestCase
{
    use Factories;

    private PastaRepository $pastaRepo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->pastaRepo = static::getContainer()->get(PastaRepository::class);
        $this->em        = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Conta pastas por criador, agrupando por userId')]
    public function testContaPorCriador(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $bruno  = UserFactory::createOne()->_real();

        PastaFactory::createMany(3, ['tenant' => $tenant, 'criadoPor' => $ana]);
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $bruno]);

        $mapa = $this->pastaRepo->countCriadasPorCriador($tenant, []);

        self::assertSame(3, $mapa[$ana->getId()]   ?? 0);
        self::assertSame(1, $mapa[$bruno->getId()] ?? 0);
    }

    #[TestDox('O criador é quem conta, não o responsável — as duas colunas medem coisas diferentes')]
    public function testCriadorNaoSeConfundeComResponsavel(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $bruno  = UserFactory::createOne()->_real();

        // Ana abriu a pasta; quem responde por ela é o Bruno.
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $bruno]);

        $criadas = $this->pastaRepo->countCriadasPorCriador($tenant, []);
        $porResp = $this->pastaRepo->countPorResponsavel($tenant, []);

        self::assertSame(1, $criadas[$ana->getId()]   ?? 0);
        self::assertSame(0, $criadas[$bruno->getId()] ?? 0);
        self::assertSame(0, $porResp[$ana->getId()]   ?? 0);
        self::assertSame(1, $porResp[$bruno->getId()] ?? 0);
    }

    #[TestDox('Pasta sem criador (legado) não entra na contagem de ninguém')]
    public function testPastaSemCriadorNaoContaParaNinguem(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();

        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $ana]);
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => null]);

        $mapa = $this->pastaRepo->countCriadasPorCriador($tenant, []);

        self::assertSame(1, $mapa[$ana->getId()] ?? 0);
        self::assertSame(1, array_sum($mapa), 'a pasta sem criador não pode aparecer sob nenhuma chave');
    }

    #[TestDox('Pasta excluída (lápide) não conta como pasta criada')]
    public function testLapideNaoConta(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();

        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $ana]);
        $excluida = PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $ana])->_real();
        $excluida->marcarExcluida($ana, new \DateTimeImmutable());
        $this->em->flush();

        $mapa = $this->pastaRepo->countCriadasPorCriador($tenant, []);

        self::assertSame(1, $mapa[$ana->getId()] ?? 0);
    }

    #[TestDox('Período do painel restringe a contagem')]
    public function testPeriodoRestringe(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();

        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $ana]);

        $ontem  = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $amanha = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        self::assertSame(1, $this->pastaRepo->countCriadasPorCriador($tenant, ['data_de' => $ontem, 'data_ate' => $amanha])[$ana->getId()] ?? 0);
        self::assertSame(0, $this->pastaRepo->countCriadasPorCriador($tenant, ['data_de' => $amanha])[$ana->getId()] ?? 0);
        self::assertSame(0, $this->pastaRepo->countCriadasPorCriador($tenant, ['data_ate' => $ontem])[$ana->getId()] ?? 0);
    }

    #[TestDox('Pasta de OUTRO escritório não conta, mesmo com o mesmo criador')]
    public function testNaoVazaEntreEscritorios(): void
    {
        $escritorioA = TenantFactory::createOne()->_real();
        $escritorioB = TenantFactory::createOne()->_real();
        $ana         = UserFactory::createOne()->_real();

        PastaFactory::createOne(['tenant' => $escritorioA, 'criadoPor' => $ana]);
        PastaFactory::createMany(2, ['tenant' => $escritorioB, 'criadoPor' => $ana]);

        self::assertSame(1, $this->pastaRepo->countCriadasPorCriador($escritorioA, [])[$ana->getId()] ?? 0);
        self::assertSame(2, $this->pastaRepo->countCriadasPorCriador($escritorioB, [])[$ana->getId()] ?? 0);
    }
}
