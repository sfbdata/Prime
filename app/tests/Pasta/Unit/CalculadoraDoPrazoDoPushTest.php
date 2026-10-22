<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\DiasUteisNacionais;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Pasta\DTO\TeorDePublicacaoInput;
use App\Pasta\Service\CalculadoraDoPrazoDoPush;
use App\Pasta\Service\DeterminacoesDoJuizo;
use App\Pasta\Service\DiasUteisDaUf;
use App\Pasta\Service\DiasUteisDoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O cálculo do prazo do Push (docs/specs/prazo-do-push.md §5), com datas fixas e o calendário do sistema (sem banco):
 * publicação no 1º dia útil depois da disponibilização, contagem a partir do dia útil seguinte, pela UF do escritório.
 */
#[CoversClass(CalculadoraDoPrazoDoPush::class)]
final class CalculadoraDoPrazoDoPushTest extends TestCase
{
    private CalculadoraDoPrazoDoPush $calculadora;
    private DiasUteisDoEscritorio $dias;

    protected function setUp(): void
    {
        $calendario = new CalendarioDoSistema();
        $this->dias = new DiasUteisDoEscritorio($calendario);
        $this->calculadora = new CalculadoraDoPrazoDoPush(
            new DeterminacoesDoJuizo($this->createStub(PublicacaoDjenRepository::class)),
            new DiasUteisNacionais($calendario),
        );
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> teor, disponibilização, UF, vencimento */
    public static function vencimentos(): iterable
    {
        yield 'disponibilizado na sexta: publica na segunda e 5 úteis vencem na segunda seguinte' => [
            'Intime-se a parte autora para se manifestar no prazo de 5 (cinco) dias úteis.', '2026-09-25', 'SP', '2026-10-05'];
        yield 'feriado nacional (12/10) no meio da contagem não conta' => [
            'Intime-se a parte autora para se manifestar no prazo de 5 dias.', '2026-10-07', 'SP', '2026-10-16'];
        yield 'feriado estadual (16/09 em AL) no meio da contagem não conta' => [
            'Intime-se a parte autora para se manifestar no prazo de 3 dias.', '2026-09-11', 'AL', '2026-09-18'];
        yield 'o mesmo prazo fora de AL conta o 16/09' => [
            'Intime-se a parte autora para se manifestar no prazo de 3 dias.', '2026-09-11', 'SP', '2026-09-17'];
        yield 'recesso: disponibilizado em 18/12 publica em 21/01 e conta de 22/01' => [
            'Intime-se a parte autora para se manifestar no prazo de 5 dias.', '2026-12-18', 'SP', '2027-01-28'];
        yield 'dias corridos que terminam no sábado vão à segunda' => [
            'Intime-se a parte ré para pagar no prazo de 4 (quatro) dias corridos.', '2026-10-19', 'SP', '2026-10-26'];
        yield 'dias corridos que terminam em dia útil ficam nele' => [
            'Intime-se a parte ré para pagar no prazo de 3 dias corridos.', '2026-10-19', 'SP', '2026-10-23'];
    }

    #[DataProvider('vencimentos')]
    #[TestDox('$teor disponibilizado em $disponibilizacao (UF $uf) vence em $vencimento')]
    public function testVencimento(string $teor, string $disponibilizacao, string $uf, string $vencimento): void
    {
        $prazos = $this->calcular($teor, $disponibilizacao, $this->dias->daUf($uf));

        self::assertCount(1, $prazos);
        self::assertSame('prazo', $prazos[0]['tipo']);
        self::assertSame($vencimento, $prazos[0]['data']->format('Y-m-d'));
        self::assertTrue($prazos[0]['estimado']);
        self::assertNull($prazos[0]['hora']);
    }

    #[TestDox('teor sem prazo explícito não gera nada (nada presumido)')]
    public function testTeorSemPrazo(): void
    {
        self::assertSame([], $this->calcular('Cite-se a parte ré. Intimem-se as partes para ciência da decisão.', '2026-10-19'));
    }

    #[TestDox('prazo em horas não gera linha nesta entrega')]
    public function testPrazoEmHoras(): void
    {
        self::assertSame([], $this->calcular('Intime-se a parte ré para pagar no prazo de 48 (quarenta e oito) horas.', '2026-10-19'));
    }

    #[TestDox('teor com dois prazos gera duas linhas, com ato e destinatário no título')]
    public function testDoisPrazos(): void
    {
        $prazos = $this->calcular(
            'Intime-se a parte autora para apresentar réplica no prazo de 15 dias. '
            . 'Após, intimem-se as partes para especificarem as provas no prazo de 5 dias.',
            '2026-09-25',
        );

        self::assertSame(
            [['Prazo do Push: Réplica (parte autora)', '2026-10-20'], ['Prazo do Push: Especificação de provas (partes)', '2026-10-05']],
            array_map(static fn (array $p): array => [$p['titulo'], $p['data']->format('Y-m-d')], $prazos),
        );
    }

    #[TestDox('sem destinatário reconhecido o título é só "Prazo do Push"')]
    public function testTituloSemDestinatario(): void
    {
        $prazos = $this->calcular('Cumpra-se no prazo de 5 dias.', '2026-09-25');

        self::assertSame(['Prazo do Push'], array_column($prazos, 'titulo'));
    }

    #[TestDox('audiência designada com data no teor gera uma linha de audiência com a hora, sem estimativa')]
    public function testAudienciaComData(): void
    {
        $prazos = $this->calcular('Designo audiência de conciliação para o dia 20/11/2026, às 14h30. Intimem-se as partes.', '2026-10-19');

        self::assertCount(1, $prazos);
        self::assertSame(
            ['audiencia', 'Audiência do Push', '2026-11-20', '14:30', false],
            [$prazos[0]['tipo'], $prazos[0]['titulo'], $prazos[0]['data']->format('Y-m-d'), $prazos[0]['hora'], $prazos[0]['estimado']]
        );
    }

    #[TestDox('audiência por extenso também vale')]
    public function testAudienciaPorExtenso(): void
    {
        $prazos = $this->calcular('Designo audiência de instrução para o dia 3 de dezembro de 2026. Intimem-se.', '2026-10-19');

        self::assertSame(['2026-12-03'], array_map(static fn (array $p): string => $p['data']->format('Y-m-d'), $prazos));
    }

    #[TestDox('audiência designada sem data no teor não gera nada')]
    public function testAudienciaSemData(): void
    {
        self::assertSame([], $this->calcular('Designo audiência de conciliação, em data a ser informada pela secretaria. Intimem-se as partes.', '2026-10-19'));
    }

    #[TestDox('publicação de tipo sem determinação (certidão) não gera nada')]
    public function testTipoSemDeterminacao(): void
    {
        $teor = new TeorDePublicacaoInput(1, 'Certidão', 'Certidão', new \DateTimeImmutable('2026-09-25'), null, 'Intime-se a parte autora no prazo de 5 dias.');

        self::assertSame([], $this->calculadora->daPublicacao($teor, $this->dias->daUf('SP')));
    }

    /** @return list<array{tipo: string, titulo: string, data: \DateTimeImmutable, hora: ?string, estimado: bool}> */
    private function calcular(string $texto, string $disponibilizacao, ?DiasUteisDaUf $calendario = null): array
    {
        $teor = new TeorDePublicacaoInput(1, 'Despacho', 'Intimação', new \DateTimeImmutable($disponibilizacao), '123', $texto);

        return $this->calculadora->daPublicacao($teor, $calendario ?? $this->dias->daUf('SP'));
    }
}
