<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Enum\MotivoFalhaDjen;
use App\Djen\Exception\ConsultaDjenException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SincronizarDjenCommandTest extends KernelTestCase
{
    #[Test]
    public function sincronizaCapturaPublicacaoNovaDoEscritorio(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $tenantId = $this->criarEscritorioComOab($em, '67228', 'PR');
        $this->injetarClientDjen($container, 777001, '50636766220224047000');

        $tester = $this->rodar(['--tenant' => (string) $tenantId]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em->clear();
        $publicacao = $container->get(PublicacaoDjenRepository::class)->findOneBy(['djenId' => '777001']);
        self::assertNotNull($publicacao, 'a publicação deveria ter sido persistida');
        self::assertSame($tenantId, $publicacao->getTenant()?->getId());
        self::assertSame('50636766220224047000', $publicacao->getNumeroProcesso());
    }

    #[Test]
    public function dryRunNaoPersisteNada(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $tenantId = $this->criarEscritorioComOab($em, '67228', 'PR');
        $this->injetarClientDjen($container, 777002, '50636766220224047001');

        $tester = $this->rodar(['--tenant' => (string) $tenantId, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em->clear();
        $publicacao = $container->get(PublicacaoDjenRepository::class)->findOneBy(['djenId' => '777002']);
        self::assertNull($publicacao, 'dry-run não pode gravar');
    }

    #[Test]
    public function falhaNaoTransitoriaResultaEmExitFailure(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $tenantId = $this->criarEscritorioComOab($em, '67228', 'PR');

        // NaoConfigurado é falha permanente (não transitória) → o comando deve alarmar (FAILURE).
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willThrowException(
            new ConsultaDjenException(MotivoFalhaDjen::NaoConfigurado)
        );
        $container->set(DjenClient::class, $mock);

        $tester = $this->rodar(['--tenant' => (string) $tenantId]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    private function criarEscritorioComOab(EntityManagerInterface $em, string $numero, string $uf): int
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório DJEN ' . uniqid());
        $em->persist($tenant);

        $oab = new OabMonitorada();
        $oab->setTenant($tenant);
        $oab->setNumero($numero);
        $oab->setUf($uf);
        $em->persist($oab);

        $em->flush();

        return (int) $tenant->getId();
    }

    private function injetarClientDjen(object $container, int $djenId, string $numeroProcesso): void
    {
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturn(new RespostaComunicacoes(1, [[
            'id' => $djenId,
            'numero_processo' => $numeroProcesso,
            'siglaTribunal' => 'CJF',
            'tipoComunicacao' => 'Intimação',
            'data_disponibilizacao' => '2026-07-06',
        ]]));

        // Sob o id concreto (para onde o alias da interface é resolvido): o get da interface cai nele.
        $container->set(DjenClient::class, $mock);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function rodar(array $args): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:djen:sincronizar'));
        $tester->execute($args);

        return $tester;
    }
}
