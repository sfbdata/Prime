<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\CatalogoDeTemas;
use App\Master\Service\TemaComemorativo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Catálogo dos 11 temas comemorativos e o cálculo das datas (spec docs/specs/temas-comemorativos.md; literais do
 * desenho docs/design/perfis-1.2/vs-21-10-26/bj-tema.js).
 */
#[CoversClass(CatalogoDeTemas::class)]
#[CoversClass(TemaComemorativo::class)]
final class CatalogoDeTemasTest extends TestCase
{
    #[TestDox('os 11 temas, na ordem do desenho, com nome e mensagem literais')]
    public function testOnzeTemasComTextosDoDesenho(): void
    {
        $esperado = [
            'mulher' => ['Dia Internacional da Mulher', 'Feliz Dia da Mulher! Nossa homenagem a todas as advogadas e profissionais do Direito.'],
            'maes' => ['Dia das Mães', 'Feliz Dia das Mães! Um carinho especial a todas as mães da advocacia.'],
            'pais' => ['Dia dos Pais', 'Feliz Dia dos Pais! Nossa homenagem aos pais da advocacia.'],
            'advogado' => ['Dia do Advogado', 'Feliz Dia do Advogado! Obrigado por defender direitos todos os dias.'],
            'independencia' => ['Independência do Brasil', '7 de Setembro: viva o Brasil!'],
            'criancas' => ['Dia das Crianças', 'Feliz Dia das Crianças! Que a criança que existe em você também comemore hoje.'],
            'outrosa' => ['Outubro Rosa', 'Outubro Rosa: prevenção ao câncer de mama. Cuide-se e faça seus exames.'],
            'novazul' => ['Novembro Azul', 'Novembro Azul: prevenção ao câncer de próstata. Cuide da sua saúde.'],
            'justica' => ['Dia da Justiça', '8 de Dezembro, Dia da Justiça: nossa homenagem a quem faz a Justiça acontecer.'],
            'natal' => ['Natal', 'Feliz Natal! Que a sua família e o seu escritório tenham um fim de ano de paz.'],
            'reveillon' => ['Réveillon', 'Feliz Ano Novo! Que o próximo ano traga muitas vitórias para o seu escritório.'],
        ];

        $temas = CatalogoDeTemas::todos();

        self::assertSame(array_keys($esperado), array_keys($temas));
        foreach ($esperado as $id => [$nome, $mensagem]) {
            self::assertSame($nome, $temas[$id]->nome);
            self::assertSame($mensagem, $temas[$id]->mensagem);
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $temas[$id]->p);
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $temas[$id]->d);
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $temas[$id]->l);
            self::assertStringNotContainsString('—', $mensagem);
            self::assertStringNotContainsString('–', $mensagem);
        }
    }

    #[TestDox('cores, ícones, fraco e animado como no desenho')]
    public function testCoresIconesEMarcas(): void
    {
        $outubro = CatalogoDeTemas::buscar('outrosa');
        self::assertNotNull($outubro);
        self::assertSame(['#d0558f', '#a63e70', '#fcebf3'], [$outubro->p, $outubro->d, $outubro->l]);
        self::assertSame('🎗️', $outubro->icone);
        self::assertTrue($outubro->fraco);

        $criancas = CatalogoDeTemas::buscar('criancas');
        self::assertNotNull($criancas);
        self::assertTrue($criancas->animado);
        self::assertSame('linear-gradient(90deg,#ef5350,#ffa726,#ffee58,#66bb6a,#42a5f5,#ab47bc)', $criancas->fita);

        $independencia = CatalogoDeTemas::buscar('independencia');
        self::assertNotNull($independencia);
        self::assertSame('Brasil', $independencia->icone);
        self::assertTrue($independencia->iconeEhTexto());
        self::assertFalse($criancas->iconeEhTexto());

        $fracos = array_keys(array_filter(CatalogoDeTemas::todos(), static fn (TemaComemorativo $t): bool => $t->fraco));
        self::assertSame(['outrosa', 'novazul'], $fracos);
        $animados = array_keys(array_filter(CatalogoDeTemas::todos(), static fn (TemaComemorativo $t): bool => $t->animado));
        self::assertSame(['criancas'], $animados);
    }

    #[TestDox('paraJson leva o "r, g, b" da primária e da escura')]
    public function testParaJson(): void
    {
        $json = CatalogoDeTemas::buscar('natal')?->paraJson();

        self::assertNotNull($json);
        self::assertSame('178, 34, 52', $json['pRgb']);
        self::assertSame('138, 26, 40', $json['dRgb']);
        self::assertSame('🎄', $json['ic']);
        self::assertFalse($json['anim']);
    }

    #[TestDox('id fora do catálogo não existe')]
    public function testIdDesconhecido(): void
    {
        self::assertFalse(CatalogoDeTemas::existe('carnaval'));
        self::assertNull(CatalogoDeTemas::buscar('carnaval'));
        self::assertTrue(CatalogoDeTemas::existe('reveillon'));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function segundosDomingos(): iterable
    {
        yield 'Mães 2026' => ['maes', 2026, '2026-05-10'];
        yield 'Mães 2027' => ['maes', 2027, '2027-05-09'];
        yield 'Mães 2025' => ['maes', 2025, '2025-05-11'];
        yield 'Pais 2025' => ['pais', 2025, '2025-08-10'];
        yield 'Pais 2026' => ['pais', 2026, '2026-08-09'];
        yield 'Pais 2027' => ['pais', 2027, '2027-08-08'];
    }

    #[DataProvider('segundosDomingos')]
    #[TestDox('2º domingo: $id em $ano cai em $data')]
    public function testSegundoDomingo(string $id, int $ano, string $data): void
    {
        $tema = CatalogoDeTemas::buscar($id);
        self::assertNotNull($tema);
        $fuso = new \DateTimeZone(CatalogoDeTemas::FUSO);

        self::assertSame($data, $tema->inicioEm($ano, $fuso)->format('Y-m-d'));
        self::assertSame('0', $tema->inicioEm($ano, $fuso)->format('w'), 'é domingo');
        self::assertTrue($tema->caiNoDia($this->dia($data)));
        self::assertFalse($tema->caiNoDia($this->dia($data)->modify('-7 days')), 'o 1º domingo não vale');
    }

    #[TestDox('Réveillon atravessa o ano: 26/12 a 01/01, testando o ano anterior')]
    public function testReveillonAtravessaOAno(): void
    {
        $tema = CatalogoDeTemas::buscar('reveillon');
        self::assertNotNull($tema);

        self::assertFalse($tema->caiNoDia($this->dia('2026-12-25')));
        self::assertTrue($tema->caiNoDia($this->dia('2026-12-26')));
        self::assertTrue($tema->caiNoDia($this->dia('2026-12-31')));
        self::assertTrue($tema->caiNoDia($this->dia('2027-01-01')));
        self::assertFalse($tema->caiNoDia($this->dia('2027-01-02')));
    }

    #[TestDox('Natal vale de 18 a 25 de dezembro')]
    public function testNatal(): void
    {
        $tema = CatalogoDeTemas::buscar('natal');
        self::assertNotNull($tema);

        self::assertFalse($tema->caiNoDia($this->dia('2026-12-17')));
        self::assertTrue($tema->caiNoDia($this->dia('2026-12-18')));
        self::assertTrue($tema->caiNoDia($this->dia('2026-12-25')));
        self::assertFalse($tema->caiNoDia($this->dia('2026-12-26')));
    }

    #[TestDox('Outubro Rosa e Novembro Azul valem o mês inteiro')]
    public function testMesesInteiros(): void
    {
        $outubro = CatalogoDeTemas::buscar('outrosa');
        $novembro = CatalogoDeTemas::buscar('novazul');
        self::assertNotNull($outubro);
        self::assertNotNull($novembro);

        self::assertFalse($outubro->caiNoDia($this->dia('2026-09-30')));
        self::assertTrue($outubro->caiNoDia($this->dia('2026-10-01')));
        self::assertTrue($outubro->caiNoDia($this->dia('2026-10-31')));
        self::assertFalse($outubro->caiNoDia($this->dia('2026-11-01')));
        self::assertTrue($novembro->caiNoDia($this->dia('2026-11-30')));
        self::assertFalse($novembro->caiNoDia($this->dia('2026-12-01')));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function proximas(): iterable
    {
        yield 'Mulher depois de março: no ano seguinte' => ['mulher', '2026-10-21', '2027-03-08', '08/03'];
        yield 'Outubro Rosa em curso' => ['outrosa', '2026-10-21', '2026-10-01', '01/10 a 31/10'];
        yield 'Natal ainda por vir' => ['natal', '2026-10-21', '2026-12-18', '18/12 a 25/12'];
        yield 'Réveillon em curso em 01/01' => ['reveillon', '2027-01-01', '2026-12-26', '26/12 a 01/01'];
        yield 'Mães no dia seguinte: próximo ano' => ['maes', '2026-05-11', '2027-05-09', '09/05'];
        yield 'Mães no próprio dia' => ['maes', '2026-05-10', '2026-05-10', '10/05'];
    }

    #[DataProvider('proximas')]
    #[TestDox('próxima ocorrência de $id a partir de $hoje: $inicio ("$quando")')]
    public function testProximaOcorrencia(string $id, string $hoje, string $inicio, string $quando): void
    {
        $tema = CatalogoDeTemas::buscar($id);
        self::assertNotNull($tema);

        self::assertSame($inicio, $tema->proximaOcorrencia($this->dia($hoje))[0]->format('Y-m-d'));
        self::assertSame($quando, $tema->quando($this->dia($hoje)));
    }

    #[TestDox('o dia vale no fuso de Brasília: 02:00 UTC de 13/10 ainda é 12/10')]
    public function testDiaEmBrasilia(): void
    {
        $dia = CatalogoDeTemas::diaEmBrasilia(new \DateTimeImmutable('2026-10-13 02:00:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-10-12 00:00', $dia->format('Y-m-d H:i'));
        self::assertSame(CatalogoDeTemas::FUSO, $dia->getTimezone()->getName());
    }

    private function dia(string $data): \DateTimeImmutable
    {
        return new \DateTimeImmutable($data, new \DateTimeZone(CatalogoDeTemas::FUSO));
    }
}
