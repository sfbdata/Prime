<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contract\TravaDaConversa;
use App\Inteligencia\Service\TravaPostgresDaConversa;
use App\Tests\Inteligencia\Support\DefineId;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A trava da aba Perguntar contra o Postgres de verdade: durante o trabalho, a sessão segura o
 * `pg_advisory_xact_lock(4716, chave)` de escritório + pasta + pessoa; uma recusa de dentro sai como veio,
 * com rollback e sem fechar o EntityManager.
 */
#[CoversClass(TravaPostgresDaConversa::class)]
final class TravaPostgresDaConversaTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{Tenant, User} */
    private function donos(int $tenantId = 42, int $userId = 9): array
    {
        $tenant = new Tenant();
        DefineId::em($tenant, $tenantId);
        $user = new User();
        DefineId::em($user, $userId);

        return [$tenant, $user];
    }

    private function travasDaSessao(int $chave): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted
               AND classid = :classe AND objid::bigint = :objid AND objsubid = 2",
            ['classe' => TravaPostgresDaConversa::CLASSE_TRAVA, 'objid' => $chave & 0xFFFFFFFF],
        );
    }

    #[TestDox('é a implementação registrada e, durante o trabalho, segura a trava consultiva da conversa')]
    public function testSeguraATravaDuranteOTrabalho(): void
    {
        self::bootKernel();
        $trava = static::getContainer()->get(TravaDaConversa::class);
        self::assertInstanceOf(TravaPostgresDaConversa::class, $trava);
        [$tenant, $user] = $this->donos();
        $chave = TravaPostgresDaConversa::chave($tenant, 7, $user);

        $durante = $trava->emTransacaoTravada($tenant, 7, $user, fn (): int => $this->travasDaSessao($chave));

        self::assertSame(1, $durante);
    }

    #[TestDox('a chave separa escritório, pasta e pessoa e cabe em int4')]
    public function testChave(): void
    {
        [$tenant, $user] = $this->donos();
        [$outroTenant, $outraPessoa] = $this->donos(43, 10);

        $chave = TravaPostgresDaConversa::chave($tenant, 7, $user);
        self::assertNotSame($chave, TravaPostgresDaConversa::chave($tenant, 8, $user));
        self::assertNotSame($chave, TravaPostgresDaConversa::chave($outroTenant, 7, $user));
        self::assertNotSame($chave, TravaPostgresDaConversa::chave($tenant, 7, $outraPessoa));
        self::assertGreaterThanOrEqual(-2147483648, $chave);
        self::assertLessThanOrEqual(2147483647, $chave);
    }

    #[TestDox('recusa de dentro do trabalho sai como veio, com rollback, e o EntityManager continua aberto')]
    public function testRecusaFazRollbackSemFecharOEm(): void
    {
        self::bootKernel();
        $trava = static::getContainer()->get(TravaDaConversa::class);
        [$tenant, $user] = $this->donos();
        $nivelAntes = $this->em()->getConnection()->getTransactionNestingLevel();

        try {
            $trava->emTransacaoTravada($tenant, 7, $user, static function (): never {
                throw new \DomainException('limitador recusou');
            });
            self::fail('deveria ter lançado');
        } catch (\DomainException $e) {
            self::assertSame('limitador recusou', $e->getMessage());
        }

        self::assertSame($nivelAntes, $this->em()->getConnection()->getTransactionNestingLevel());
        self::assertTrue($this->em()->isOpen());
    }
}
