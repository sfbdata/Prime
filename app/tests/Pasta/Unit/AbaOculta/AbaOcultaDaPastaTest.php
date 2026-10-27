<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\AbaOculta;

use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbaOcultaDaPasta::class)]
#[CoversClass(AbaDaPasta::class)]
#[Group('pasta')]
final class AbaOcultaDaPastaTest extends TestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    #[TestDox('A lista guarda ids únicos e ordenados; "toda a equipe" alcança qualquer id')]
    public function testListaEParaTodos(): void
    {
        $regra = $this->regraPara(AbaDaPasta::Push, [4, 3, 4]);
        self::assertSame([3, 4], $regra->getUsuarios());
        self::assertTrue($regra->alcanca(3));
        self::assertFalse($regra->alcanca(9));

        $regra->ocultarParaTodos($this->master, new \DateTimeImmutable('2026-10-20'));
        self::assertTrue($regra->alcanca(9));
        self::assertSame([], $regra->getUsuarios());
    }

    #[TestDox('Lista vazia não é regra: a entidade recusa (sem ninguém, a aba volta a ser visível)')]
    public function testListaVaziaRecusada(): void
    {
        $this->expectException(\DomainException::class);
        $this->regraPara(AbaDaPasta::Push, []);
    }

    #[TestDox('Rótulos das abas: os do cabeçalho da pasta')]
    public function testRotulos(): void
    {
        self::assertSame(
            ['Dados da pasta', 'Metas', 'Processo', 'Financeiro', 'Modelos', 'Documentos', 'Push Processual'],
            array_map(static fn (AbaDaPasta $a): string => $a->rotulo(), AbaDaPasta::cases()),
        );
    }
}
