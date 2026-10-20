<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Integration;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\UseCase\DesconectarGoogleAgendaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Agenda\GoogleFalso;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Desconectar em lote (exclusão de conta, suspensão, purga do escritório): pronto para o dono decidir onde ligar
 * (revisão da b5, item 2). Revoga no Google e tira a conexão daqui, de todas as do escritório ou da pessoa.
 */
#[CoversClass(DesconectarGoogleAgendaUseCase::class)]
final class DesconectarEmLoteTest extends KernelTestCase
{
    private GoogleFalso $google;

    protected function setUp(): void
    {
        $this->google = new GoogleFalso();
        static::getContainer()->set(GoogleAgendaApi::class, $this->google->api());
    }

    #[TestDox('Todas do escritório: as duas pessoas saem e são revogadas; o outro escritório fica')]
    public function testTodasDoEscritorio(): void
    {
        $tenant = $this->escritorio();
        $outro = $this->escritorio();
        $ana = $this->pessoa();
        $bia = $this->pessoa();
        $this->conectar($ana, $tenant, 'ana@gmail.com');
        $this->conectar($bia, $tenant, 'bia@gmail.com');
        $this->conectar($ana, $outro, 'ana.outro@gmail.com');

        static::getContainer()->get(DesconectarGoogleAgendaUseCase::class)->todasDoEscritorio($tenant, false);

        self::assertSame(0, $this->conexoes('tenant_id', (int) $tenant->getId()));
        self::assertSame(1, $this->conexoes('tenant_id', (int) $outro->getId()));
        self::assertCount(2, array_filter($this->google->pedidos, static fn (string $p): bool => 'POST /revoke' === $p));
    }

    #[TestDox('Todas da pessoa: sai de todos os escritórios, apagando a agenda do escritório quando pedido')]
    public function testTodasDaPessoa(): void
    {
        $ana = $this->pessoa();
        $conexao = $this->conectar($ana, $this->escritorio(), 'ana@gmail.com');
        $this->conectar($ana, $this->escritorio(), 'ana2@gmail.com');
        $this->em()->getConnection()->executeStatement("UPDATE agenda_google_conexao SET calendario_id = 'agenda-ana' WHERE id = :id", ['id' => $conexao->getId()]);
        $this->em()->clear();

        static::getContainer()->get(DesconectarGoogleAgendaUseCase::class)->todasDaPessoa($this->em()->find(User::class, $ana->getId()), true);

        self::assertSame(0, $this->conexoes('usuario_id', (int) $ana->getId()));
        self::assertContains('DELETE /calendar/v3/calendars/agenda-ana', $this->google->pedidos);
    }

    private function conexoes(string $coluna, int $id): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_conexao WHERE ' . $coluna . ' = :id', ['id' => $id]);
    }

    private function escritorio(): Tenant
    {
        $tenant = (new Tenant())->setName('Tenant LOTE ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function pessoa(): User
    {
        $u = (new User())->setEmail('lote_' . uniqid() . '@test.com')->setFullName('Pessoa Lote')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function conectar(User $pessoa, Tenant $tenant, string $conta): ConexaoGoogleAgenda
    {
        $cifrado = static::getContainer()->get(CifradorDeSegredo::class)->cifrar('REFRESH-' . $conta);
        $conexao = new ConexaoGoogleAgenda($tenant, $pessoa, $cifrado, $conta, 'America/Sao_Paulo');
        $this->em()->persist($conexao);
        $this->em()->flush();

        return $conexao;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
