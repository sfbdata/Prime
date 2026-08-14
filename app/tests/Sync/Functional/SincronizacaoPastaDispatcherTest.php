<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Message\SincronizarPastaNoDrive;
use App\Sync\Service\CifradorDeSegredo;
use App\Sync\Service\SincronizacaoPastaDispatcher;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(SincronizacaoPastaDispatcher::class)]
final class SincronizacaoPastaDispatcherTest extends KernelTestCase
{
    use Factories;

    private function conexaoAtiva(\App\Entity\Tenant\Tenant $tenant): void
    {
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $cifrador = self::getContainer()->get(CifradorDeSegredo::class);
        $conexao  = new TenantDriveConexao($tenant);
        $conexao->registrarCredenciais($cifrador->cifrar('tok'), 'a@b.com', 'drive', UserFactory::createOne()->_real());
        $conexao->definirRootFolder('root-abcdefghij');
        $em->persist($conexao);
        $em->flush();
    }

    private function transporteAsync(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    #[TestDox('com Drive conectado, enfileira a mensagem da pasta')]
    public function testDespachaComConexao(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '1']);
        $this->conexaoAtiva($tenant->_real());

        self::getContainer()->get(SincronizacaoPastaDispatcher::class)
            ->despachar($pasta->_real(), $user->_real(), $tenant->_real());

        $enviadas = $this->transporteAsync()->getSent();
        self::assertCount(1, $enviadas);
        $msg = $enviadas[0]->getMessage();
        self::assertInstanceOf(SincronizarPastaNoDrive::class, $msg);
        self::assertSame($pasta->getId(), $msg->pastaId);
        self::assertSame($tenant->getId(), $msg->tenantId);
        self::assertSame($user->getId(), $msg->usuarioId);
    }

    #[TestDox('sem Drive conectado, NÃO enfileira nada (no-op)')]
    public function testNaoDespachaSemConexao(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '2']);
        // sem conexão ativa

        self::getContainer()->get(SincronizacaoPastaDispatcher::class)
            ->despachar($pasta->_real(), $user->_real(), $tenant->_real());

        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    // ───────────────────────────────────────────────────────────────────────────────────────
    // R3 — propagar a mudança de nome ao Drive, POR EVENTO. A regra que estes testes protegem
    // é a de custo: nome igual NÃO pode virar write no Drive (D12.3). Se alguém "simplificar"
    // para despachar sempre, o cron volta a gastar ~1070 writes/hora à toa.
    // ───────────────────────────────────────────────────────────────────────────────────────

    #[TestDox('R3: nome mudou → enfileira COM o pedido de renomear')]
    public function testDespachaRenomeacaoQuandoNomeMuda(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '10', 'nomeCliente' => 'FULANO']);
        $this->conexaoAtiva($tenant->_real());

        self::getContainer()->get(SincronizacaoPastaDispatcher::class)
            ->despacharSeNomeMudou($pasta->_real(), $user->_real(), $tenant->_real(), '9 - FULANO');

        $enviadas = $this->transporteAsync()->getSent();
        self::assertCount(1, $enviadas);
        $msg = $enviadas[0]->getMessage();
        self::assertInstanceOf(SincronizarPastaNoDrive::class, $msg);
        self::assertTrue($msg->renomear, 'a mensagem saiu sem o pedido de renomear');
    }

    #[TestDox('R3: nome IGUAL → não enfileira nada (nome igual não vira write no Drive)')]
    public function testNaoDespachaQuandoNomeNaoMuda(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '10', 'nomeCliente' => 'FULANO']);
        $this->conexaoAtiva($tenant->_real());

        // Mesmo nome que a pasta tem agora — foi editada a situação, não o nome.
        self::getContainer()->get(SincronizacaoPastaDispatcher::class)
            ->despacharSeNomeMudou($pasta->_real(), $user->_real(), $tenant->_real(), '10 - FULANO');

        self::assertCount(
            0,
            $this->transporteAsync()->getSent(),
            'nome igual gerou mensagem — a renomeação virou rotina e vai gastar cota à toa',
        );
    }

    #[TestDox('R3: o despacho comum não pede renomeação (criar pasta / anexar documento)')]
    public function testDespachoComumNaoPedeRenomeacao(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '11']);
        $this->conexaoAtiva($tenant->_real());

        self::getContainer()->get(SincronizacaoPastaDispatcher::class)
            ->despachar($pasta->_real(), $user->_real(), $tenant->_real());

        $msg = $this->transporteAsync()->getSent()[0]->getMessage();
        self::assertFalse($msg->renomear);
    }
}
