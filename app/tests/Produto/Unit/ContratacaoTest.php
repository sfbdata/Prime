<?php

declare(strict_types=1);

namespace App\Tests\Produto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Enum\SituacaoDaContratacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Transições da linha de contratação (spec multiproduto, §4). */
#[CoversClass(Contratacao::class)]
final class ContratacaoTest extends TestCase
{
    private function ativa(\DateTimeImmutable $inicio): Contratacao
    {
        return Contratacao::ativar(new Tenant(), Produto::Voto, OrigemDaContratacao::Cadastro, null, $inicio);
    }

    #[TestDox('Ativar nasce ativa, sem fim, com a origem e o início pedidos')]
    public function testAtivar(): void
    {
        $inicio = new \DateTimeImmutable('2026-11-23 10:00:00');

        $linha = $this->ativa($inicio);

        self::assertSame(SituacaoDaContratacao::Ativa, $linha->getSituacao());
        self::assertSame(Produto::Voto, $linha->getProduto());
        self::assertSame(OrigemDaContratacao::Cadastro, $linha->getOrigem());
        self::assertNull($linha->getAlteradaPor());
        self::assertEquals($inicio, $linha->getInicio());
        self::assertNull($linha->getFim());
    }

    #[TestDox('Encerrar grava o fim e troca origem e autor pelos de quem encerrou')]
    public function testEncerrar(): void
    {
        $linha = $this->ativa(new \DateTimeImmutable('2026-11-23 10:00:00'));
        $master = new User();
        $agora = new \DateTimeImmutable('2026-11-24 09:00:00');

        $linha->encerrar(OrigemDaContratacao::Master, $master, $agora);

        self::assertSame(SituacaoDaContratacao::Encerrada, $linha->getSituacao());
        self::assertEquals($agora, $linha->getFim());
        self::assertSame(OrigemDaContratacao::Master, $linha->getOrigem());
        self::assertSame($master, $linha->getAlteradaPor());
    }

    #[TestDox('Encerrar de novo lança DomainException')]
    public function testEncerrarDuasVezes(): void
    {
        $linha = $this->ativa(new \DateTimeImmutable('2026-11-23 10:00:00'));
        $linha->encerrar(OrigemDaContratacao::Dono, null, new \DateTimeImmutable('2026-11-23 11:00:00'));

        $this->expectException(\DomainException::class);

        $linha->encerrar(OrigemDaContratacao::Dono, null, new \DateTimeImmutable('2026-11-23 12:00:00'));
    }

    #[TestDox('Relógio que volta não deixa o fim antes do início (CHECK do banco)')]
    public function testFimNuncaAntesDoInicio(): void
    {
        $inicio = new \DateTimeImmutable('2026-11-23 10:00:00');
        $linha = $this->ativa($inicio);

        $linha->encerrar(OrigemDaContratacao::Dono, null, $inicio->modify('-1 minute'));

        self::assertEquals($inicio, $linha->getFim());
    }

    #[TestDox('Vale só ativa e já começada')]
    public function testValeEm(): void
    {
        $inicio = new \DateTimeImmutable('2026-11-23 10:00:00');
        $linha = $this->ativa($inicio);

        self::assertFalse($linha->valeEm($inicio->modify('-1 second')), 'antes do início');
        self::assertTrue($linha->valeEm($inicio));

        $linha->encerrar(OrigemDaContratacao::Dono, null, $inicio->modify('+1 hour'));

        self::assertFalse($linha->valeEm($inicio->modify('+30 minutes')), 'encerrada não vale');
    }
}
