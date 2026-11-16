<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodigoDoConector::class)]
final class CodigoDoConectorTest extends TestCase
{
    use CenarioDoConector;

    private function codigo(?ConcessaoDoConector $concessao = null, string $hash = '', string $desafio = self::DESAFIO): CodigoDoConector
    {
        return new CodigoDoConector($hash !== '' ? $hash : self::hash('codigo'), $concessao ?? self::concessao(), 'http://127.0.0.1/callback', $desafio, self::em('2026-11-16 10:00:00'));
    }

    #[TestDox('O escritório do código é o da concessão; vence 10 minutos depois de emitido')]
    public function testEscritorioEValidade(): void
    {
        $tenant = new Tenant();
        $codigo = $this->codigo(self::concessao(self::consentimento($tenant)));

        self::assertSame($tenant, $codigo->getTenant());
        self::assertEquals(self::em('2026-11-16 10:10:00'), $codigo->getExpiraEm());
        self::assertFalse($codigo->venceuEm(self::em('2026-11-16 10:09:59')));
        self::assertTrue($codigo->venceuEm(self::em('2026-11-16 10:10:00')));
    }

    #[TestDox('O código é de uso único, e o vencido não é usado')]
    public function testUsoUnico(): void
    {
        $codigo = $this->codigo();
        $codigo->usar(self::em('2026-11-16 10:01:00'));
        $vencido = $this->codigo();
        $recusas = 0;
        foreach ([[$codigo, '2026-11-16 10:02:00'], [$vencido, '2026-11-16 10:10:00']] as [$c, $quando]) {
            try {
                $c->usar(self::em($quando));
            } catch (\DomainException) {
                ++$recusas;
            }
        }

        self::assertSame(2, $recusas);
        self::assertEquals(self::em('2026-11-16 10:01:00'), $codigo->getUsadoEm());
        self::assertNull($vencido->getUsadoEm());
    }

    #[TestDox('PKCE só S256: recusa desafio que não tem 43 caracteres base64url (plain, curto, com "=")')]
    public function testSoS256(): void
    {
        $recusas = 0;
        foreach (['verificador-plain-qualquer', substr(self::DESAFIO, 0, 42), substr(self::DESAFIO, 0, 42) . '='] as $desafio) {
            try {
                $this->codigo(desafio: $desafio);
            } catch (\InvalidArgumentException) {
                ++$recusas;
            }
        }

        self::assertSame(3, $recusas);
    }

    #[TestDox('Guarda só hash; concessão revogada não emite código')]
    public function testHashEConcessaoRevogada(): void
    {
        $revogada = self::concessao();
        $revogada->revogar(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, self::em('2026-11-16 09:00:00'));
        $recusas = 0;
        foreach ([fn () => $this->codigo(hash: 'codigo-cru'), fn () => $this->codigo($revogada)] as $tentativa) {
            try {
                $tentativa();
            } catch (\LogicException) {
                ++$recusas;
            }
        }

        self::assertSame(2, $recusas);
    }
}
