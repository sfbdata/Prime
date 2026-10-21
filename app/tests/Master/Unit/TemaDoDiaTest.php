<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\TemaConfig;
use App\Master\Repository\TemaConfigRepository;
use App\Master\Service\CatalogoDeTemas;
use App\Master\Service\TemaDoDia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Regra do tema ativo (spec docs/specs/temas-comemorativos.md, "Regra do tema ativo"), como o ativo() do desenho.
 */
#[CoversClass(TemaDoDia::class)]
#[CoversClass(TemaConfig::class)]
final class TemaDoDiaTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function automatico(): iterable
    {
        yield '12/10: Crianças vence o Outubro Rosa' => ['2026-10-12', 'criancas'];
        yield '13/10: Outubro Rosa (fraco, mês inteiro)' => ['2026-10-13', 'outrosa'];
        yield '08/03: Dia da Mulher' => ['2026-03-08', 'mulher'];
        yield '2º domingo de maio de 2026' => ['2026-05-10', 'maes'];
        yield '11/08: Dia do Advogado' => ['2026-08-11', 'advogado'];
        yield '07/09: Independência' => ['2026-09-07', 'independencia'];
        yield '08/12: Dia da Justiça' => ['2026-12-08', 'justica'];
        yield '20/11: Novembro Azul' => ['2026-11-20', 'novazul'];
        yield '24/12: Natal' => ['2026-12-24', 'natal'];
        yield '01/01: Réveillon do ano anterior' => ['2027-01-01', 'reveillon'];
        yield '15/06: nenhum' => ['2026-06-15', null];
    }

    #[DataProvider('automatico')]
    #[TestDox('automático: $dia dá $esperado')]
    public function testAutomatico(string $dia, ?string $esperado): void
    {
        self::assertSame($esperado, TemaDoDia::escolher(TemaConfig::MODO_AUTOMATICO, null, [], $this->dia($dia))?->id);
    }

    #[TestDox('automático: tema desligado sai da disputa; dia específico desligado deixa o fraco valer')]
    public function testDesligadosPorTema(): void
    {
        self::assertSame('outrosa', TemaDoDia::escolher(TemaConfig::MODO_AUTOMATICO, null, ['criancas'], $this->dia('2026-10-12'))?->id);
        self::assertNull(TemaDoDia::escolher(TemaConfig::MODO_AUTOMATICO, null, ['criancas', 'outrosa'], $this->dia('2026-10-12')));
        self::assertSame('natal', TemaDoDia::escolher(TemaConfig::MODO_AUTOMATICO, null, ['reveillon'], $this->dia('2026-12-20'))?->id);
    }

    #[TestDox('desligado: nenhum tema, mesmo no dia de um')]
    public function testDesligado(): void
    {
        self::assertNull(TemaDoDia::escolher(TemaConfig::MODO_DESLIGADO, 'natal', [], $this->dia('2026-12-24')));
    }

    #[TestDox('manual: o tema escolhido em qualquer dia, mesmo desligado na lista; sem escolha, nenhum')]
    public function testManual(): void
    {
        self::assertSame('natal', TemaDoDia::escolher(TemaConfig::MODO_MANUAL, 'natal', ['natal'], $this->dia('2026-06-15'))?->id);
        self::assertNull(TemaDoDia::escolher(TemaConfig::MODO_MANUAL, null, [], $this->dia('2026-10-12')));
    }

    #[TestDox('sem configuração gravada vale o automático com todos ligados, no fuso de Brasília')]
    public function testSemConfiguracao(): void
    {
        $repositorio = $this->createStub(TemaConfigRepository::class);
        $repositorio->method('atual')->willReturn(null);
        // 02:00 UTC de 13/10 = 23:00 de 12/10 em Brasília: ainda é Dia das Crianças.
        $temaDoDia = new TemaDoDia($repositorio, new MockClock('2026-10-13 02:00:00', 'UTC'));

        self::assertSame('criancas', $temaDoDia->ativo()?->id);
        self::assertSame('2026-10-12', $temaDoDia->hoje()->format('Y-m-d'));
    }

    #[TestDox('com configuração gravada, o ativo segue o modo e os desligados dela')]
    public function testComConfiguracao(): void
    {
        $config = new TemaConfig();
        $config->ligarTema('outrosa', false, null, new \DateTimeImmutable());
        $repositorio = $this->createStub(TemaConfigRepository::class);
        $repositorio->method('atual')->willReturn($config);
        $temaDoDia = new TemaDoDia($repositorio, new MockClock('2026-10-21 12:00:00', 'America/Sao_Paulo'));

        self::assertNull($temaDoDia->ativo());

        $config->definirModo(TemaConfig::MODO_MANUAL, 'pais', null, new \DateTimeImmutable());
        self::assertSame('pais', $temaDoDia->ativo()?->id);
    }

    #[TestDox('estado do card: modo, tema fixado, ativo, e cada tema com "quando" e "ligado"')]
    public function testEstado(): void
    {
        $config = new TemaConfig();
        $config->ligarTema('natal', false, null, new \DateTimeImmutable());
        $repositorio = $this->createStub(TemaConfigRepository::class);
        $repositorio->method('atual')->willReturn($config);
        $temaDoDia = new TemaDoDia($repositorio, new MockClock('2026-10-21 12:00:00', 'America/Sao_Paulo'));

        $estado = $temaDoDia->estado();

        self::assertSame('automatico', $estado['modo']);
        self::assertNull($estado['manual']);
        self::assertSame('outrosa', $estado['ativo']);
        self::assertCount(11, $estado['temas']);
        $porId = array_column($estado['temas'], null, 'id');
        self::assertSame('01/10 a 31/10', $porId['outrosa']['quando']);
        self::assertSame('08/03', $porId['mulher']['quando']);
        self::assertFalse($porId['natal']['ligado']);
        self::assertTrue($porId['reveillon']['ligado']);
        self::assertSame('Outubro Rosa', $porId['outrosa']['r']);
    }

    #[TestDox('modo inválido na entidade é recusado')]
    public function testModoInvalidoNaEntidade(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TemaConfig())->definirModo('festa', null, null, new \DateTimeImmutable());
    }

    #[TestDox('ligar e desligar o mesmo tema não duplica a lista')]
    public function testListaDeDesligados(): void
    {
        $config = new TemaConfig();
        $config->ligarTema('natal', false, null, new \DateTimeImmutable());
        $config->ligarTema('natal', false, null, new \DateTimeImmutable());
        self::assertSame(['natal'], $config->getDesligados());

        $config->ligarTema('natal', true, null, new \DateTimeImmutable());
        self::assertSame([], $config->getDesligados());
    }

    private function dia(string $data): \DateTimeImmutable
    {
        return new \DateTimeImmutable($data, new \DateTimeZone(CatalogoDeTemas::FUSO));
    }
}
