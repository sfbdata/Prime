<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `arquivadaEm` ("Pastas concluídas" do Dashboard, spec dashboard-parte-1): entrar em Arquivado grava
 * a data, continuar arquivada não mexe, sair apaga. Vale para todo caminho que passa pelo
 * setSituacao (a pílula, o menu ⋮ e o "Editar dados").
 */
#[CoversClass(Pasta::class)]
final class PastaArquivadaEmTest extends TestCase
{
    #[TestDox('Pasta nova não tem data de arquivamento')]
    public function testPastaNovaSemData(): void
    {
        self::assertNull((new Pasta())->getArquivadaEm());
    }

    #[TestDox('Arquivar grava a data informada')]
    public function testArquivarGravaAData(): void
    {
        $pasta = new Pasta();
        $em    = new \DateTimeImmutable('2026-10-10 14:30');

        $pasta->setSituacao(Pasta::SITUACAO_ARQUIVADA, $em);

        self::assertSame($em, $pasta->getArquivadaEm());
    }

    #[TestDox('Arquivar sem data informada grava agora')]
    public function testArquivarSemDataGravaAgora(): void
    {
        $antes = new \DateTimeImmutable('-1 second');
        $pasta = (new Pasta())->setSituacao(Pasta::SITUACAO_ARQUIVADA);

        self::assertNotNull($pasta->getArquivadaEm());
        self::assertGreaterThanOrEqual($antes, $pasta->getArquivadaEm());
    }

    #[TestDox('Regravar Arquivado (o "Editar dados" faz isso a cada salvamento) não troca a data')]
    public function testContinuarArquivadaNaoTrocaAData(): void
    {
        $pasta = (new Pasta())->setSituacao(Pasta::SITUACAO_ARQUIVADA, new \DateTimeImmutable('2026-10-10 14:30'));

        $pasta->setSituacao(Pasta::SITUACAO_ARQUIVADA, new \DateTimeImmutable('2026-10-20 09:00'));

        self::assertEquals(new \DateTimeImmutable('2026-10-10 14:30'), $pasta->getArquivadaEm());
    }

    #[TestDox('Desarquivar apaga a data; para Ativo, Suspenso ou Cancelado')]
    public function testDesarquivarApagaAData(): void
    {
        foreach ([Pasta::SITUACAO_ATIVA, Pasta::SITUACAO_SUSPENSA, Pasta::SITUACAO_CANCELADA] as $destino) {
            $pasta = (new Pasta())->setSituacao(Pasta::SITUACAO_ARQUIVADA, new \DateTimeImmutable('2026-10-10'));

            $pasta->setSituacao($destino);

            self::assertNull($pasta->getArquivadaEm(), $destino);
        }
    }

    #[TestDox('Arquivar de novo depois de desarquivar grava a nova data')]
    public function testArquivarDeNovoGravaANovaData(): void
    {
        $pasta = (new Pasta())->setSituacao(Pasta::SITUACAO_ARQUIVADA, new \DateTimeImmutable('2026-09-01'));
        $pasta->setSituacao(Pasta::SITUACAO_ATIVA);

        $pasta->setSituacao(Pasta::SITUACAO_ARQUIVADA, new \DateTimeImmutable('2026-10-05'));

        self::assertEquals(new \DateTimeImmutable('2026-10-05'), $pasta->getArquivadaEm());
    }

    #[TestDox('Restaurar da lixeira volta a Ativo e apaga a data de arquivamento')]
    public function testRestaurarApagaAData(): void
    {
        $pasta = (new Pasta())->setSituacao(Pasta::SITUACAO_ARQUIVADA, new \DateTimeImmutable('2026-10-05'));
        $pasta->marcarExcluida((new User())->setEmail('autor@test.com'), new \DateTimeImmutable('2026-10-06'));

        $pasta->restaurar();

        self::assertSame(Pasta::SITUACAO_ATIVA, $pasta->getSituacao());
        self::assertNull($pasta->getArquivadaEm());
    }
}
