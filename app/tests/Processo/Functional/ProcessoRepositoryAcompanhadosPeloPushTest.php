<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Quais processos cadastrados o Push Processual consulta pelo número: os do escritório, sem baixa.
 *
 * KernelTestCase sem request: o TenantFilter NÃO está ligado (é um listener de kernel.request),
 * exatamente como no comando de captação. O isolamento provado aqui é o `andWhere` explícito.
 */
#[CoversClass(ProcessoRepository::class)]
#[Group('djen')]
final class ProcessoRepositoryAcompanhadosPeloPushTest extends KernelTestCase
{
    use Factories;

    private ProcessoRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(ProcessoRepository::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Lista (do mais recente) e conta só os processos do escritório que ainda não deram baixa')]
    public function testSoDoEscritorioESemBaixa(): void
    {
        $meu = TenantFactory::createOne()->_real();
        $outro = TenantFactory::createOne()->_real();

        $this->criarProcesso($meu, '07011111111111111111');
        $this->criarProcesso($meu, '07022222222222222222');
        $this->criarProcesso($meu, '07033333333333333333', new \DateTime('2026-01-10'));
        $this->criarProcesso($outro, '07044444444444444444');

        self::assertSame(
            ['07022222222222222222', '07011111111111111111'],
            $this->repo->listarNumerosAcompanhadosDoTenant($meu),
            'do mais recente ao mais antigo',
        );
        self::assertSame(2, $this->repo->contarAcompanhadosDoTenant($meu));
        self::assertSame(['07044444444444444444'], $this->repo->listarNumerosAcompanhadosDoTenant($outro));
    }

    #[TestDox('Escritório sem processo cadastrado não consulta nada')]
    public function testEscritorioSemProcesso(): void
    {
        $vazio = TenantFactory::createOne()->_real();

        self::assertSame([], $this->repo->listarNumerosAcompanhadosDoTenant($vazio));
        self::assertSame(0, $this->repo->contarAcompanhadosDoTenant($vazio));
    }

    private function criarProcesso(Tenant $tenant, string $numero, ?\DateTime $baixa = null): void
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual('Procedimento Comum');
        if ($baixa !== null) {
            $processo->setDataBaixa($baixa);
        }
        $this->em->persist($processo);
        $this->em->flush();
    }
}
