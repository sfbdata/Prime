<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\VisaoDoMaster;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A porta do Master para todos os escritórios: dentro do callback vê o outro escritório; depois, o filtro
 * volta ligado COM o parâmetro (a sessão continua isolada), inclusive quando o callback lança.
 */
#[CoversClass(VisaoDoMaster::class)]
final class VisaoDoMasterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $daSessao;
    private Tenant $outro;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->daSessao = $this->tenant('sessão');
        $this->outro = $this->tenant('outro');
        $this->em->persist(new Sugestao($this->outro, null, 'B', TipoDeSugestao::Sugestao, 'Sugestão do outro escritório'));
        $this->em->flush();
        $this->em->clear();
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $this->daSessao->getId(), Types::INTEGER);
    }

    #[TestDox('Dentro vê o outro escritório; depois o filtro volta e isola de novo')]
    public function testRestauraOFiltro(): void
    {
        $visao = static::getContainer()->get(VisaoDoMaster::class);
        $sugestoes = static::getContainer()->get(SugestaoRepository::class);

        $dentro = $visao->deTodosOsEscritorios(fn (): int => \count($sugestoes->doEscritorio((int) $this->outro->getId())));

        self::assertSame(1, $dentro);
        self::assertTrue($this->em->getFilters()->isEnabled('tenant'));
        self::assertSame([], $sugestoes->doEscritorio((int) $this->outro->getId()), 'fora da porta o filtro da sessão esconde o outro escritório');
    }

    #[TestDox('Exceção no callback: o filtro volta mesmo assim')]
    public function testRestauraComExcecao(): void
    {
        $visao = static::getContainer()->get(VisaoDoMaster::class);
        try {
            $visao->deTodosOsEscritorios(static fn () => throw new \RuntimeException('falhou'));
        } catch (\RuntimeException) {
        }

        self::assertSame([], static::getContainer()->get(SugestaoRepository::class)->doEscritorio((int) $this->outro->getId()));
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = (new Tenant())->setName('Visão ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }
}
