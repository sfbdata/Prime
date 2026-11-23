<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PedidoDeBackupRepository::class)]
final class PedidoDeBackupRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PedidoDeBackupRepository $repo;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PedidoDeBackupRepository::class);
        $this->agora = new \DateTimeImmutable('2026-11-23 12:00:00');
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório Backup ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function usuario(): User
    {
        $user = new User();
        $user->setEmail('backup_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function pedido(Tenant $tenant, User $usuario, ?\DateTimeImmutable $em = null): PedidoDeBackup
    {
        $pedido = new PedidoDeBackup($tenant, $usuario, $em ?? $this->agora);
        $this->em->persist($pedido);
        $this->em->flush();

        return $pedido;
    }

    private function pronto(Tenant $tenant, User $usuario, \DateTimeImmutable $expiraEm): PedidoDeBackup
    {
        $pedido = $this->pedido($tenant, $usuario, $this->agora->modify('-8 days'));
        $pedido->iniciarMontagem($this->agora->modify('-8 days'));
        $pedido->concluir(
            new ResultadoDoBackup(bin2hex(random_bytes(8)) . '.zip', 10, str_repeat('a', 64), 1, 0),
            $this->agora->modify('-8 days'),
            $expiraEm,
        );
        $this->em->flush();

        return $pedido;
    }

    #[TestDox('doEscritorio devolve o pedido do próprio escritório')]
    public function testDoEscritorioDevolveOProprio(): void
    {
        $tenant = $this->tenant();
        $pedido = $this->pedido($tenant, $this->usuario());

        $achado = $this->repo->doEscritorio((int) $pedido->getId(), (int) $tenant->getId());

        self::assertSame($pedido->getId(), $achado?->getId());
    }

    #[TestDox('doEscritorio não devolve pedido de outro escritório (IDOR)')]
    public function testDoEscritorioNaoDevolveDeOutroEscritorio(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $pedido = $this->pedido($a, $this->usuario());

        self::assertNull($this->repo->doEscritorio((int) $pedido->getId(), (int) $b->getId()));
        self::assertNull($this->repo->doEscritorio(999999999, (int) $a->getId()));
    }

    #[TestDox('temAbertoDaPessoa: fila e preparo contam; pronto e falhou não')]
    public function testTemAbertoDaPessoa(): void
    {
        $tenant  = $this->tenant();
        $usuario = $this->usuario();
        $tid = (int) $tenant->getId();
        $uid = (int) $usuario->getId();

        self::assertFalse($this->repo->temAbertoDaPessoa($tid, $uid));

        $pedido = $this->pedido($tenant, $usuario);
        self::assertTrue($this->repo->temAbertoDaPessoa($tid, $uid), 'na fila');

        $pedido->iniciarMontagem($this->agora);
        $this->em->flush();
        self::assertTrue($this->repo->temAbertoDaPessoa($tid, $uid), 'em preparo');

        $pedido->falhar('x', $this->agora);
        $this->em->flush();
        self::assertFalse($this->repo->temAbertoDaPessoa($tid, $uid), 'falhou');
    }

    #[TestDox('temAbertoDaPessoa: pedido aberto de outra pessoa ou de outro escritório não conta')]
    public function testTemAbertoIsolaPessoaEEscritorio(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $ana = $this->usuario();
        $beto = $this->usuario();
        $this->pedido($a, $ana);

        self::assertFalse($this->repo->temAbertoDaPessoa((int) $a->getId(), (int) $beto->getId()), 'outra pessoa');
        self::assertFalse($this->repo->temAbertoDaPessoa((int) $b->getId(), (int) $ana->getId()), 'outro escritório');
    }

    #[TestDox('quantosDaPessoaDesde conta só a pessoa, o escritório e a janela')]
    public function testQuantosDaPessoaDesde(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $ana = $this->usuario();
        $beto = $this->usuario();

        $this->pedido($a, $ana, $this->agora->modify('-2 hours'));
        $this->pedido($a, $ana, $this->agora->modify('-23 hours'));
        $this->pedido($a, $ana, $this->agora->modify('-25 hours'));
        $this->pedido($a, $beto, $this->agora->modify('-1 hour'));
        $this->pedido($b, $ana, $this->agora->modify('-1 hour'));

        $desde = $this->agora->modify('-24 hours');

        self::assertSame(2, $this->repo->quantosDaPessoaDesde((int) $a->getId(), (int) $ana->getId(), $desde));
        self::assertSame(1, $this->repo->quantosDaPessoaDesde((int) $a->getId(), (int) $beto->getId(), $desde));
        self::assertSame(1, $this->repo->quantosDaPessoaDesde((int) $b->getId(), (int) $ana->getId(), $desde));
        self::assertSame(0, $this->repo->quantosDaPessoaDesde((int) $b->getId(), (int) $beto->getId(), $desde));

        $falho = $this->pedido($b, $beto, $this->agora->modify('-30 minutes'));
        $falho->falhar('erro', $this->agora);
        $this->em->flush();
        self::assertSame(0, $this->repo->quantosDaPessoaDesde((int) $b->getId(), (int) $beto->getId(), $desde), 'pedido falhou não conta no limite');
    }

    #[TestDox('ultimosDaPessoa isola por pessoa e escritório, o mais novo primeiro, respeitando o limite')]
    public function testUltimosDaPessoa(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $ana = $this->usuario();
        $beto = $this->usuario();

        $velho = $this->pedido($a, $ana, $this->agora->modify('-3 days'));
        $meio  = $this->pedido($a, $ana, $this->agora->modify('-2 days'));
        $novo  = $this->pedido($a, $ana, $this->agora->modify('-1 day'));
        $this->pedido($a, $beto);
        $this->pedido($b, $ana);

        $lista = $this->repo->ultimosDaPessoa((int) $a->getId(), (int) $ana->getId());

        self::assertSame([$novo->getId(), $meio->getId(), $velho->getId()], array_map(static fn (PedidoDeBackup $p) => $p->getId(), $lista));
        self::assertCount(2, $this->repo->ultimosDaPessoa((int) $a->getId(), (int) $ana->getId(), 2));
        self::assertCount(1, $this->repo->ultimosDaPessoa((int) $b->getId(), (int) $ana->getId()));
        self::assertSame([], $this->repo->ultimosDaPessoa((int) $b->getId(), (int) $beto->getId()));
    }

    #[TestDox('prontosVencidos devolve só os prontos que passaram da validade, de qualquer escritório')]
    public function testProntosVencidos(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $ana = $this->usuario();
        $beto = $this->usuario();

        $vencidoA = $this->pronto($a, $ana, $this->agora->modify('-1 day'));
        $vencidoB = $this->pronto($b, $beto, $this->agora->modify('-1 second'));
        $naHora   = $this->pronto($a, $ana, $this->agora);
        $valido   = $this->pronto($a, $ana, $this->agora->modify('+1 day'));
        $naFila   = $this->pedido($a, $beto);

        $ids = array_map(static fn (PedidoDeBackup $p) => $p->getId(), $this->repo->prontosVencidos($this->agora));

        self::assertContains($vencidoA->getId(), $ids);
        self::assertContains($vencidoB->getId(), $ids);
        self::assertContains($naHora->getId(), $ids, 'a validade é inclusiva');
        self::assertNotContains($valido->getId(), $ids);
        self::assertNotContains($naFila->getId(), $ids);

        $vencidoA->vencer($this->agora);
        $this->em->flush();
        $depois = array_map(static fn (PedidoDeBackup $p) => $p->getId(), $this->repo->prontosVencidos($this->agora));
        self::assertNotContains($vencidoA->getId(), $depois, 'já vencido sai da lista');

        self::assertCount(1, array_filter(
            $this->repo->prontosVencidos($this->agora, 1),
            static fn (PedidoDeBackup $p) => true,
        ), 'o limite vale');
    }
}
