<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A busca do Master (visão de plataforma) alcança a liberação de outro escritório mesmo com o filtro de tenant ligado
 * no escritório da sessão, e devolve o filtro como estava. Sem isso, o "nasce se não existir" do UseCase criaria uma
 * segunda linha e bateria no índice único `uniq_voto_liberacao_tenant`.
 */
#[CoversClass(LiberacaoDoVotoRepository::class)]
final class LiberacaoDoVotoRepositoryTest extends KernelTestCase
{
    #[TestDox('findDoTenantParaOMaster acha a linha de outro escritório com o filtro ligado e restaura o filtro')]
    public function testMasterAlcancaOutroEscritorioComFiltroLigado(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $daSessao = $this->escritorio($em, 'Escritório da sessão ' . uniqid());
        $outro = $this->escritorio($em, 'Outro escritório ' . uniqid());
        $liberacao = new LiberacaoDoVoto($outro);
        $em->persist($liberacao);
        $em->flush();
        $em->clear();

        $filtros = $em->getFilters();
        $filtros->enable('tenant')->setParameter('tenant', (int) $daSessao->getId(), Types::INTEGER);
        try {
            $repositorio = static::getContainer()->get(LiberacaoDoVotoRepository::class);
            self::assertNull($repositorio->findOneBy(['tenant' => $outro->getId()]), 'a busca comum respeita o filtro');

            $achada = $repositorio->findDoTenantParaOMaster($em->getReference(Tenant::class, $outro->getId()));

            self::assertSame($liberacao->getId(), $achada?->getId());
            self::assertTrue($filtros->isEnabled('tenant'), 'o filtro volta ligado depois da busca');
        } finally {
            $filtros->disable('tenant');
        }
    }

    private function escritorio(EntityManagerInterface $em, string $nome): Tenant
    {
        $tenant = (new Tenant())->setName($nome);
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }
}
