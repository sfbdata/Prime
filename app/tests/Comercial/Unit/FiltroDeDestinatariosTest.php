<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\FiltroDeDestinatarios;
use App\Comercial\DTO\AtributosDoDestinatario;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\FiltroDoPublico;
use App\Comercial\Exception\CampanhaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O "Filtrar destinatários" do desenho 05 VS 21.10.26 (L634-L635; spec comercial-e-emails.md §9): cada campo, a
 * combinação com E, a busca com OU entre termos, e o "sem essa informação no cadastro" contado uma vez, no primeiro
 * campo que precisa do dado, na ordem do dc.
 */
#[CoversClass(FiltroDeDestinatarios::class)]
#[CoversClass(FiltroDoPublico::class)]
final class FiltroDeDestinatariosTest extends TestCase
{
    private const HOJE = '2026-10-21';

    #[TestDox('Sem filtro, todos atendem e ninguém fica sem informação')]
    public function testSemFiltro(): void
    {
        [$atendem, $sem] = $this->aplicar([]);

        self::assertCount(6, $atendem);
        self::assertSame(0, $sem);
    }

    /** @param list<string> $esperados */
    #[DataProvider('umCampo')]
    #[TestDox('Um campo: $_dataName')]
    public function testUmCampo(array $filtro, array $esperados, int $semInformacao): void
    {
        [$atendem, $sem] = $this->aplicar($filtro);

        self::assertSame($esperados, self::emails($atendem));
        self::assertSame($semInformacao, $sem);
    }

    /** @return iterable<string, array{0: array<string, string>, 1: list<string>, 2: int}> */
    public static function umCampo(): iterable
    {
        yield 'gênero F (sem gênero, ou sem conta, conta como sem informação)' => [['gen' => 'F'], ['ana@x.com', 'bia@x.com'], 3];
        yield 'idade 25 a 34' => [['id' => 'b'], ['ana@x.com'], 4];
        yield 'UF DF' => [['uf' => 'DF'], ['bia@x.com'], 4];
        yield 'perfil primário (sem conta não atende, mas não é sem informação)' => [['papel' => 'prim'], ['ana@x.com', 'caio@x.com'], 0];
        yield 'cargo advogado' => [['cargo' => 'adv'], ['caio@x.com'], 4];
        yield 'OAB pendente' => [['oab' => 'pend'], ['bia@x.com'], 0];
        yield 'plano profissional (de algum escritório)' => [['plano' => 'profissional'], ['caio@x.com'], 0];
        yield 'aniversário deste mês' => [['aniv' => 'mes'], ['ana@x.com'], 4];
        yield 'aniversário do próximo mês' => [['aniv' => 'prox'], ['bia@x.com'], 4];
        yield 'busca com OU entre termos, no e-mail e no nome' => [['q' => 'caio; Beatriz'], ['bia@x.com', 'caio@x.com'], 0];
    }

    #[TestDox('Dois campos combinam com E')]
    public function testCombinaComE(): void
    {
        [$atendem] = $this->aplicar(['papel' => 'prim', 'gen' => 'F']);

        self::assertSame(['ana@x.com'], self::emails($atendem));
    }

    #[TestDox('Sem informação conta uma vez, no primeiro campo do dc que precisa do dado: quem já saiu por valor não conta')]
    public function testSemInformacaoUmaVezNaOrdemDoDc(): void
    {
        // Gênero vem antes de UF. Caio (M) sai pelo gênero e não conta como sem UF; Dani (sem nada), Edu e Fê (sem
        // conta) contam uma vez cada, mesmo faltando os dois dados.
        [$atendem, $sem] = $this->aplicar(['gen' => 'F', 'uf' => 'SP']);

        self::assertSame(['ana@x.com'], self::emails($atendem));
        self::assertSame(3, $sem);
    }

    #[TestDox('Aniversário do próximo mês em dezembro é janeiro')]
    public function testAniversarioViraOAno(): void
    {
        $janeiro = new AtributosDoDestinatario(nascimento: new \DateTimeImmutable('1990-01-15'));
        [$atendem] = FiltroDeDestinatarios::aplicar(
            [new DestinatarioDaCampanha('jan@x.com', 'Jan')],
            ['jan@x.com' => $janeiro],
            FiltroDoPublico::deArray(['aniv' => 'prox']),
            new \DateTimeImmutable('2026-12-10'),
        );

        self::assertCount(1, $atendem);
    }

    #[TestDox('Idade em anos completos: na véspera do aniversário ainda não fez')]
    public function testIdadeNaVespera(): void
    {
        $nascido = new AtributosDoDestinatario(nascimento: new \DateTimeImmutable('2001-10-22'));
        $lista = [new DestinatarioDaCampanha('n@x.com', 'N')];

        [$na24] = FiltroDeDestinatarios::aplicar($lista, ['n@x.com' => $nascido], FiltroDoPublico::deArray(['id' => 'a']), new \DateTimeImmutable('2026-10-21'));
        [$fez25] = FiltroDeDestinatarios::aplicar($lista, ['n@x.com' => $nascido], FiltroDoPublico::deArray(['id' => 'b']), new \DateTimeImmutable('2026-10-22'));

        self::assertCount(1, $na24, 'véspera: 24 anos');
        self::assertCount(1, $fez25, 'no dia: 25 anos');
    }

    #[TestDox('Nascimento no futuro é dado ruim: conta como sem a informação, e não numa faixa de idade')]
    public function testNascimentoNoFuturo(): void
    {
        $futuro = new AtributosDoDestinatario(nascimento: new \DateTimeImmutable('2060-01-01'));
        [$atendem, $sem] = FiltroDeDestinatarios::aplicar([new DestinatarioDaCampanha('f@x.com', 'F')], ['f@x.com' => $futuro], FiltroDoPublico::deArray(['id' => 'b']), new \DateTimeImmutable(self::HOJE));

        self::assertSame([[], 1], [$atendem, $sem]);
    }

    #[TestDox('Filtro: só valores do dc; o resumo tem só o preenchido, nas chaves do dc; o selo conta os campos')]
    public function testFiltroDoPublico(): void
    {
        $filtro = FiltroDoPublico::deArray(['gen' => 'F', 'uf' => 'df', 'q' => '  ', 'plano' => 'essencial']);

        self::assertSame(['gen' => 'F', 'uf' => 'DF', 'plano' => 'essencial'], $filtro->paraArray());
        self::assertSame(3, $filtro->quantos());
        self::assertFalse(FiltroDoPublico::deArray([])->ativo());
        foreach ([['gen' => 'X'], ['uf' => 'XX'], ['aniv' => 'ano'], ['plano' => 'Plano Grande'], ['cargo' => ['adv']], ['q' => str_repeat('a', 501)]] as $invalido) {
            try {
                FiltroDoPublico::deArray($invalido);
                self::fail('aceitou ' . json_encode($invalido));
            } catch (CampanhaRecusada) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Ana (prim, F, 30 anos, outubro, SP, OAB conf), Bia ("Beatriz", sec, F, novembro, DF, OAB pend), Caio (prim, M,
     * advogado, plano profissional num dos escritórios), Dani (cadastro sem dados), Edu e Fê (sem conta).
     *
     * @param array<string, string> $filtro
     *
     * @return array{0: list<DestinatarioDaCampanha>, 1: int}
     */
    private function aplicar(array $filtro): array
    {
        $lista = array_map(static fn (array $p): DestinatarioDaCampanha => new DestinatarioDaCampanha($p[0], $p[1]), [
            ['ana@x.com', 'Ana Souza'], ['bia@x.com', 'Beatriz Lima'], ['caio@x.com', 'Caio Prado'],
            ['dani@x.com', 'Dani'], ['edu@x.com', ''], ['fe@x.com', 'Fê'],
        ]);
        $atributos = [
            'ana@x.com' => new AtributosDoDestinatario('F', new \DateTimeImmutable('1996-10-02'), 'SP', 'prim', 'ctl', 'conf', ['free']),
            'bia@x.com' => new AtributosDoDestinatario('F', new \DateTimeImmutable('1980-11-30'), 'DF', 'sec', null, 'pend', ['free']),
            'caio@x.com' => new AtributosDoDestinatario('M', null, null, 'prim', 'adv', 'sem', ['free', 'profissional']),
            'dani@x.com' => new AtributosDoDestinatario(null, null, null, 'sec', null, 'sem', ['free']),
        ];

        return FiltroDeDestinatarios::aplicar($lista, $atributos, FiltroDoPublico::deArray($filtro), new \DateTimeImmutable(self::HOJE));
    }

    /**
     * @param list<DestinatarioDaCampanha> $lista
     *
     * @return list<string>
     */
    private static function emails(array $lista): array
    {
        return array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $lista);
    }
}
