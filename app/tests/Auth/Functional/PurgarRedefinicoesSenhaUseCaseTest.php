<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\UseCase\PurgarRedefinicoesSenhaUseCase;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sem esta purga, o IP e o user agent de todo pedido de redefinição ficariam no banco
 * para sempre — e a justificativa para manter a tabela fora da auditoria ("é efêmera")
 * seria falsa.
 */
#[CoversClass(PurgarRedefinicoesSenhaUseCase::class)]
final class PurgarRedefinicoesSenhaUseCaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PurgarRedefinicoesSenhaUseCase $sut;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em  = static::getContainer()->get(EntityManagerInterface::class);
        $this->sut = static::getContainer()->get(PurgarRedefinicoesSenhaUseCase::class);
    }

    #[TestDox('Purga apaga consumidos e vencidos, preserva os links ainda válidos')]
    public function testPurgaApagaConsumidosEVencidosPreservaVivos(): void
    {
        $agora = new \DateTimeImmutable('2026-07-01 12:00:00');
        $user  = $this->criarUsuario();

        $ontem = $agora->modify('-25 hours');

        $vivo      = $this->criarPedido($user, $agora->modify('+30 minutes'), $ontem);
        $vencido   = $this->criarPedido($user, $agora->modify('-1 minute'), $ontem);
        $consumido = $this->criarPedido($user, $agora->modify('+30 minutes'), $ontem);
        $consumido->marcarUsado($agora->modify('-5 minutes'));
        $this->em->flush();

        $vivoId      = $vivo->getId();
        $vencidoId   = $vencido->getId();
        $consumidoId = $consumido->getId();

        $apagados = $this->sut->executar($agora, dryRun: false);

        self::assertSame(2, $apagados, 'deve apagar o vencido e o consumido');

        $this->em->clear();
        self::assertNotNull($this->em->find(RedefinicaoSenha::class, $vivoId), 'link vivo preservado');
        self::assertNull($this->em->find(RedefinicaoSenha::class, $vencidoId), 'link vencido apagado');
        self::assertNull($this->em->find(RedefinicaoSenha::class, $consumidoId), 'link consumido apagado');
    }

    #[TestDox('Purga preserva o pedido criado nas últimas 24 h, mesmo consumido ou vencido (o teto diário conta)')]
    public function testPurgaPreservaPedidosDoDia(): void
    {
        $agora = new \DateTimeImmutable('2026-07-01 12:00:00');
        $user  = $this->criarUsuario();
        $consumido = $this->criarPedido($user, $agora->modify('-1 minute'), $agora->modify('-23 hours'));
        $consumido->marcarUsado($agora->modify('-22 hours'));
        $this->em->flush();

        self::assertSame(0, $this->sut->executar($agora, dryRun: true));
        self::assertSame(0, $this->sut->executar($agora, dryRun: false));

        $this->em->clear();
        self::assertNotNull($this->em->find(RedefinicaoSenha::class, $consumido->getId()));
    }

    #[TestDox('Dry-run conta o que seria purgado sem apagar nada')]
    public function testDryRunContaSemApagar(): void
    {
        $agora = new \DateTimeImmutable('2026-07-01 12:00:00');
        $user  = $this->criarUsuario();
        $vencido = $this->criarPedido($user, $agora->modify('-1 minute'), $agora->modify('-25 hours'));
        $this->em->flush();

        $contagem = $this->sut->executar($agora, dryRun: true);

        self::assertSame(1, $contagem);
        self::assertNotNull($this->em->find(RedefinicaoSenha::class, $vencido->getId()), 'dry-run não apaga');
    }

    private function criarUsuario(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('purga_' . uniqid() . '@adv.com');
        $user->setFullName('Purga');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'x'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarPedido(User $user, \DateTimeImmutable $expiraEm, \DateTimeImmutable $criadoEm): RedefinicaoSenha
    {
        $pedido = new RedefinicaoSenha(
            user: $user,
            tokenHash: RedefinicaoSenha::hashDoToken(bin2hex(random_bytes(32))),
            expiraEm: $expiraEm,
            ip: '1.2.3.4',
            userAgent: 'UA',
            criadoEm: $criadoEm,
        );
        $this->em->persist($pedido);

        return $pedido;
    }
}
