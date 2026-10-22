<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Agenda\Service\CalendarioDoSistema;
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
        $this->dias = new DiasUteisDoEscritorio(new CalendarioDoSistema());
        $this->calculadora = new CalculadoraDoPrazoDoPush(new DeterminacoesDoJuizo($this->createStub(PublicacaoDjenRepository::class)));
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
        // Disponibilizado qua 10/12/2025; publicação qui 11/12; dia 1 = sex 12/12. Conta 12 a 19/12 (8 dias, sábado e
        // domingo contam em corridos); 20/12 a 20/01 é recesso e não conta; retoma em 21/01/2026: 21 (9), 22 (10),
        // 23 (11), 24 (12), 25 (13), 26 (14), 27 (15). Ter 27/01/2026 é dia útil, então fica nele.
        yield 'recesso suspende os dias corridos: 15 corridos de 10/12/2025 vencem em 27/01/2026' => [
            'Intime-se a parte ré para pagar no prazo de 15 dias corridos.', '2025-12-10', 'SP', '2026-01-27'];
        // 09/07 é feriado em SP: a publicação vai à sex 10/07 e a contagem começa na seg 13/07: 13 (1), 14 (2), 15 (3).
        yield 'termo inicial pelo calendário da UF: 09/07 em SP adia a publicação' => [
            'Intime-se a parte autora para se manifestar no prazo de 3 dias.', '2026-07-08', 'SP', '2026-07-15'];
        yield 'prazo de 1 dia vence no 1º dia útil depois da publicação' => [
            'Intime-se a parte autora para se manifestar no prazo de 1 (um) dia.', '2026-09-25', 'SP', '2026-09-29'];
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

    #[TestDox('escritório sem UF conta pelos feriados nacionais')]
    public function testEscritorioSemUf(): void
    {
        $prazos = $this->calcular('Intime-se a parte autora para se manifestar no prazo de 5 dias.', '2026-10-07', $this->dias->daUf(null));

        self::assertSame(['2026-10-16'], array_map(static fn (array $p): string => $p['data']->format('Y-m-d'), $prazos));
    }

    #[TestDox('frase que narra o passado ("intimada ... quedou-se inerte") não gera prazo; a mesma ordem no imperativo gera')]
    public function testNarracaoNaoGeraPrazo(): void
    {
        $narracao = 'A parte autora, intimada para emendar a inicial no prazo de 15 dias, quedou-se inerte.';
        $teor = new TeorDePublicacaoInput(1, 'Despacho', 'Intimação', new \DateTimeImmutable('2026-09-25'), '123', $narracao);
        $leitura = (new DeterminacoesDoJuizo($this->createStub(PublicacaoDjenRepository::class)))->determinacoesDoTeor($teor);
        self::assertSame([15], array_map(static fn ($d): ?int => $d->prazoQuantidade, $leitura), 'a leitura do juízo continua igual');

        self::assertSame([], $this->calcular($narracao, '2026-09-25'));
        self::assertCount(1, $this->calcular('Intime-se a parte autora para emendar a inicial no prazo de 15 dias.', '2026-09-25'));
    }

    #[TestDox('as outras formas de narração também não geram prazo')]
    public function testOutrasNarracoes(): void
    {
        $leitor = new DeterminacoesDoJuizo($this->createStub(PublicacaoDjenRepository::class));
        foreach (['O réu, citado para contestar no prazo de 15 dias, deixou transcorrer o prazo.',
            'A parte autora, intimada a se manifestar no prazo de 5 dias, não se manifestou.'] as $narracao) {
            $teor = new TeorDePublicacaoInput(1, 'Despacho', 'Intimação', new \DateTimeImmutable('2026-09-25'), '123', $narracao);
            self::assertNotSame([], $leitor->determinacoesDoTeor($teor), 'a leitura do juízo acha a frase: ' . $narracao);
            self::assertSame([], $this->calcular($narracao, '2026-09-25'), $narracao);
        }
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
