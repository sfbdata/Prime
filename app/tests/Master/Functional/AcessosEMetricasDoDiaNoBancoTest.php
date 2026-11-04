<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Master\Command\RetratarMetricasCommand;
use App\Master\EventListener\AcessoDiarioNoLoginListener;
use App\Master\Repository\AcessoDiarioRepository;
use App\Master\Repository\MetricaDiariaDoSistemaRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Os dados reais do gráfico Crescimento (desenho 05 - Perfis 1.2, VS 03.11.26) contra o banco: o login grava UMA linha
 * por pessoa por dia, a foto diária grava e regrava as 4 métricas do dia, e a conta excluída leva os acessos junto.
 */
#[CoversClass(AcessoDiarioRepository::class)]
#[CoversClass(MetricaDiariaDoSistemaRepository::class)]
#[CoversClass(AcessoDiarioNoLoginListener::class)]
#[CoversClass(RetratarMetricasCommand::class)]
final class AcessosEMetricasDoDiaNoBancoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
    }

    #[TestDox('dois logins no mesmo dia, pelo despachante do firewall, gravam uma linha só')]
    public function testUmaLinhaPorPessoaPorDia(): void
    {
        $usuario = $this->usuario();
        $despachante = self::getContainer()->get('security.event_dispatcher.main');

        $despachante->dispatch($this->login($usuario));
        $despachante->dispatch($this->login($usuario));

        self::assertSame(1, $this->acessosDe($usuario));
        $hoje = (new \DateTimeImmutable())->format('Y-m-d');
        self::assertGreaterThanOrEqual(1, self::getContainer()->get(AcessoDiarioRepository::class)->pessoasPorDia()[$hoje]);
    }

    #[TestDox('excluir a pessoa apaga os acessos dela (só usuário e dia, nada mais)')]
    public function testExcluirApagaOsAcessos(): void
    {
        $usuario = $this->usuario();
        self::getContainer()->get(AcessoDiarioRepository::class)->registrar((int) $usuario->getId(), new \DateTimeImmutable('2026-11-01'));
        $id = (int) $usuario->getId();

        $this->db->executeStatement('DELETE FROM "user" WHERE id = ?', [$id]);

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM master_acesso_diario WHERE usuario_id = ?', [$id]));
    }

    #[TestDox('o comando da tarefa diária grava as 4 métricas do dia e, rodando de novo, só atualiza')]
    public function testComandoGravaERegrava(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:master:retratar-metricas'));

        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, $tester->execute([]));

        $hoje   = (new \DateTimeImmutable())->format('Y-m-d');
        $linhas = $this->db->fetchAllKeyValue('SELECT metrica, valor FROM master_metrica_diaria WHERE dia = ? ORDER BY metrica', [$hoje]);
        self::assertSame(['bytes', 'contas', 'pastas', 'usuarios'], array_keys($linhas));
        self::assertStringContainsString('contas:', $tester->getDisplay());

        $fotos = self::getContainer()->get(MetricaDiariaDoSistemaRepository::class)->porMetricaEDia();
        self::assertEquals((float) $linhas['usuarios'], $fotos['usuarios'][$hoje]);
    }

    private function usuario(): User
    {
        $u = new User();
        $u->setEmail('acesso_' . uniqid() . '@teste.com');
        $u->setFullName('Pessoa do Acesso');
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function acessosDe(User $usuario): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM master_acesso_diario WHERE usuario_id = ?', [$usuario->getId()]);
    }

    private function login(User $usuario): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($usuario->getUserIdentifier(), static fn (): User => $usuario)),
            new PostAuthenticationToken($usuario, 'main', $usuario->getRoles()),
            new Request(),
            null,
            'main',
        );
    }
}
