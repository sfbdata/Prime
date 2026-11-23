<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDeCalculos;
use App\Backup\Coletor\ColetorDoBackup;
use App\Calculo\Entity\CalculoEmitido;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Cálculos no backup: só o registro dos cálculos que a PRÓPRIA pessoa emitiu. Escritório 1: A, C (com módulo), S (sem módulo). Escritório 2: B. */
#[CoversClass(ColetorDeCalculos::class)]
final class ColetorDeCalculosTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private Tenant $t1;
    private Tenant $t2;
    private User $a;
    private User $b;
    private User $c;
    private User $s;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();
        [$this->b, $this->t2] = $this->criarEscritorio();
        $this->c = $this->criarColaborador($this->t1, 'Carlos', ['modules.calculo.view']);
        $this->s = $this->criarColaborador($this->t1, 'Sem Modulo', []);

        $this->emitir($this->t1, $this->a, 'CALC-DO-A', '100.00');
        $this->emitir($this->t1, $this->a, 'CALC-DO-A-2', '200.00');
        $this->emitir($this->t1, $this->c, 'CALC-DO-C', '300.00');
        $this->emitir($this->t2, $this->b, 'CALC-DO-B', '400.00');
        $this->em()->flush();
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        return [new ColetorDeCalculos(static::getContainer()->get(PermissionChecker::class), $this->em(), $lote)];
    }

    private function emitir(Tenant $tenant, User $usuario, string $codigo, string $total): void
    {
        $this->em()->persist(new CalculoEmitido($tenant, $usuario, $codigo, new \DateTimeImmutable('2026-11-01 10:00'), new \DateTimeImmutable('2026-10-31'), $total, hash('sha256', $codigo)));
    }

    #[TestDox('A leva só os cálculos dele: nem o do colega C nem o do escritório B')]
    public function testSoOsDaPropriaPessoa(): void
    {
        $tudo = self::tudo($this->backupDe($this->a, $this->t1));

        self::assertStringContainsString('CALC-DO-A', $tudo);
        self::assertStringContainsString('CALC-DO-A-2', $tudo);
        self::assertStringNotContainsString('CALC-DO-C', $tudo);
        self::assertStringNotContainsString('CALC-DO-B', $tudo);
    }

    #[TestDox('B (escritório 2) leva só o dele')]
    public function testOutroEscritorio(): void
    {
        $zip = $this->backupDe($this->b, $this->t2);

        self::assertCount(1, self::json($zip, 'dados/calculos.json'));
        self::assertStringNotContainsString('CALC-DO-A', self::tudo($zip));
    }

    #[TestDox('Quem tem o módulo leva o seu; quem não tem o módulo não leva nada')]
    public function testModulo(): void
    {
        $c = self::tudo($this->backupDe($this->c, $this->t1));
        self::assertStringContainsString('CALC-DO-C', $c);
        self::assertStringNotContainsString('CALC-DO-A', $c);

        $zip = $this->backupDe($this->s, $this->t1);
        self::assertArrayNotHasKey('dados/calculos.json', $zip);
    }

    #[TestDox('Lote de 1 gera o mesmo conteúdo')]
    public function testLoteDeUm(): void
    {
        $this->assertLoteDeUmIgual($this->a, $this->t1);
    }
}
