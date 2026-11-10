<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\DTO\CabecalhoDaFolha;
use App\Ponto\DTO\CarimboDoAceiteNaFolha;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\ResumoDoMesDaFolha;
use App\Ponto\DTO\ValidacaoDaFolha;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Service\LinhasDaFolhaImpressa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O conteúdo da folha em PDF (spec ponto-desenho-0811, E16, P34 a P36, decisões 3 e 8; desenho 16 L634 a L705): as
 * frases fixas, o cabeçalho com CPF e CTPS mascarados, o resumo, o carimbo, a validação e a impressão dos registros.
 */
#[Group('ponto')]
#[CoversClass(ConteudoDaFolha::class)]
#[CoversClass(CabecalhoDaFolha::class)]
#[CoversClass(ResumoDoMesDaFolha::class)]
#[CoversClass(CarimboDoAceiteNaFolha::class)]
#[CoversClass(ValidacaoDaFolha::class)]
final class ConteudoDaFolhaTest extends TestCase
{
    #[TestDox('P36: as três frases das observações gerais, a declaração e a base legal do desenho (L695 a L705)')]
    public function testFrasesFixas(): void
    {
        self::assertSame([
            'As horas extras foram autorizadas previamente pela gestão e serão compensadas conforme acordo de banco de horas.',
            'Feriados trabalhados serão compensados.',
            'Registros realizados pelo sistema eletrônico de ponto BlueJus.',
        ], ConteudoDaFolha::OBSERVACOES_GERAIS);
        self::assertSame('Declaro que os registros acima representam fielmente minha jornada de trabalho.', ConteudoDaFolha::DECLARACAO);
        self::assertSame('Art. 74, §2º da CLT  |  Portaria MTP nº 671/2021  |  Art. 13 da Portaria nº 3.626/91', ConteudoDaFolha::BASE_LEGAL);
        self::assertSame('FOLHA DE PONTO · 03/2026', $this->conteudo(2026, 3)->titulo());
    }

    #[TestDox('Decisão 3: os mesmos registros dão sempre a mesma impressão (sha256), e o carimbo e a validação ficam fora dela')]
    public function testImpressaoEstavel(): void
    {
        $conteudo = $this->conteudo();
        $carimbado = $conteudo->comCarimbo(new CarimboDoAceiteNaFolha(SituacaoDoAceiteDaFolha::Concordo, new \DateTimeImmutable('2026-11-03 09:05:00'), null))
            ->comValidacao(new ValidacaoDaFolha('BJF-ABCD-EFGH-JK23', str_repeat('a', 64), new \DateTimeImmutable('2026-11-03 09:05:00')));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $conteudo->impressaoDosRegistros());
        self::assertSame($conteudo->impressaoDosRegistros(), $this->conteudo()->impressaoDosRegistros());
        self::assertSame($conteudo->impressaoDosRegistros(), $carimbado->impressaoDosRegistros());
        self::assertNotNull($carimbado->carimbo);
        self::assertNull($conteudo->validacao);
    }

    #[TestDox('Decisão 3: a impressão muda com uma batida, com a situação de uma justificativa e com um feriado novo')]
    public function testImpressaoMudaComOsRegistros(): void
    {
        $base = $this->conteudo()->impressaoDosRegistros();
        $outraBatida = $this->conteudo(dia: ['saida' => '17:01:00'])->impressaoDosRegistros();
        $justificada = $this->conteudo(justificativas: [$this->justificativa('pendente')])->impressaoDosRegistros();
        $abonada = $this->conteudo(justificativas: [$this->justificativa('abonado')])->impressaoDosRegistros();
        $feriado = $this->conteudo(dia: ['isFeriado' => true, 'nomeFeriado' => 'Aniversário da cidade'])->impressaoDosRegistros();

        self::assertCount(5, array_unique([$base, $outraBatida, $justificada, $abonada, $feriado]));
    }

    #[TestDox('O resumo dos registros: cada dia como a folha imprime e os totais do mês (sem banco anterior e atual)')]
    public function testResumoDosRegistros(): void
    {
        $resumo = $this->conteudo()->resumoDosRegistros();

        self::assertSame(['trabalhadas' => '8:00', 'extras' => '0:00', 'incompletos' => 0, 'a_revisar' => 0], $resumo['totais']);
        self::assertSame(['data', 'batidas', 'trabalhadas', 'extras', 'saldo', 'observacao', 'feriado', 'fora_de_sequencia', 'incompleto', 'justificativas'], array_keys($resumo['dias'][0]));
        self::assertSame(['08:00', '12:00', '13:00', '17:00'], $resumo['dias'][0]['batidas']);
    }

    #[TestDox('Cabeçalho: os dados da folha exportada, os intervalos mínimos e CPF e CTPS mascarados (decisão 8)')]
    public function testCabecalho(): void
    {
        $cabecalho = CabecalhoDaFolha::daFolhaExportada($this->dadosExportados(), '0042', 60, 660);

        self::assertSame(['01/10/2026', '31/10/2026', 'Escritório Modelo', '0042', 'Maria da Silva'], [$cabecalho->inicioDoPeriodo, $cabecalho->fimDoPeriodo, $cabecalho->empregador, $cabecalho->codigo, $cabecalho->nome]);
        self::assertSame(['***.456.789-**', '***2329', '1h00 (mínimo)', 'mínimo 11h00'], [$cabecalho->cpf, $cabecalho->ctps, $cabecalho->intervaloIntrajornada, $cabecalho->intervaloInterjornada]);
        self::assertSame('', CabecalhoDaFolha::daFolhaExportada([], '', 45, 660)->cpf, 'sem o dado, a célula fica vazia');
        self::assertSame('0h45 (mínimo)', CabecalhoDaFolha::daFolhaExportada([], '', 45, 660)->intervaloIntrajornada);
    }

    #[TestDox('Máscaras: CPF só com 11 dígitos; a CTPS mostra só os quatro últimos caracteres')]
    public function testMascaras(): void
    {
        self::assertSame('***.456.789-**', CabecalhoDaFolha::cpfMascarado('12345678901'));
        self::assertSame('', CabecalhoDaFolha::cpfMascarado('1234567890'));
        self::assertSame('', CabecalhoDaFolha::cpfMascarado(''));
        self::assertSame('***2329', CabecalhoDaFolha::ctpsMascarada(' 0012329 '));
        self::assertSame('***12', CabecalhoDaFolha::ctpsMascarada('12'));
        self::assertSame('', CabecalhoDaFolha::ctpsMascarada('  '));
    }

    #[TestDox('Resumo do mês: os totais da folha exportada e os dias incompletos e a revisar, contados pelo que cada linha mostra')]
    public function testResumoDoMes(): void
    {
        $impressao = new LinhasDaFolhaImpressa();
        // O dia com as quatro batidas e a saída antes da entrada: o motor marca incompleto, a linha mostra "a revisar".
        $invertido = $impressao->linha($this->dia(['saida' => '07:30:00', 'registroIncompleto' => true]), []);
        $linhas = [
            $impressao->linha($this->dia(['saida' => '', 'registroIncompleto' => true]), []),
            $impressao->linha($this->dia(['retorno' => '11:00:00']), []),
            $invertido,
            $impressao->linha($this->dia(), []),
        ];

        $resumo = ResumoDoMesDaFolha::daFolhaExportada($this->dadosExportados(), $linhas);

        self::assertSame(['168:00', '2:00', '+1:00', '+3:00', '0:00'], [$resumo->trabalhadas, $resumo->extras, $resumo->saldoAnterior, $resumo->saldoAtual, $resumo->horasACompensar]);
        self::assertSame('1 · 2', $resumo->textoDosIncompletos());
        self::assertSame('0 · 1', ResumoDoMesDaFolha::daFolhaExportada($this->dadosExportados(), [$invertido])->textoDosIncompletos(), 'desenho L616, L618 e L626');
    }

    #[TestDox('Carimbo do aceite (L700) e faixa de validação (L703): os textos com data e hora da emissão')]
    public function testCarimboEValidacao(): void
    {
        $quando = new \DateTimeImmutable('2026-11-03 09:05:07');
        $concordo = new CarimboDoAceiteNaFolha(SituacaoDoAceiteDaFolha::Concordo, $quando, null);
        $ressalva = new CarimboDoAceiteNaFolha(SituacaoDoAceiteDaFolha::Ressalva, $quando, 'O dia 12 foi de folga.');

        self::assertSame(['Aceite eletrônico em 03/11/2026, 09:05:07', null], [$concordo->texto(), $concordo->textoDaRessalva()]);
        self::assertSame(['Aceite com ressalva em 03/11/2026, 09:05:07', 'Ressalva: O dia 12 foi de folga.'], [$ressalva->texto(), $ressalva->textoDaRessalva()]);
        self::assertSame('Emitida em 03/11/2026, 09:05:07. Vale somente com registro correspondente no BlueJus.', (new ValidacaoDaFolha('BJF-ABCD-EFGH-JK23', str_repeat('a', 64), $quando))->textoDaEmissao());
        self::assertSame(['HMAC-SHA256', 'CÓDIGO DE VALIDAÇÃO'], [ValidacaoDaFolha::ROTULO_DO_SELO, ValidacaoDaFolha::TITULO]);
    }

    /**
     * @param array<string, mixed>     $dia
     * @param list<JustificativaPonto> $justificativas
     */
    private function conteudo(int $ano = 2026, int $mes = 10, array $dia = [], array $justificativas = []): ConteudoDaFolha
    {
        $impressao = new LinhasDaFolhaImpressa();
        $linhas = $impressao->imprimir([$this->dia($dia), $this->dia(['chaveDia' => '2026-10-06', 'diaMes' => '06'])], $justificativas);
        $dados = $this->dadosExportados();

        return new ConteudoDaFolha(
            $ano,
            $mes,
            CabecalhoDaFolha::daFolhaExportada($dados, '0042', 60, 660),
            $linhas,
            ResumoDoMesDaFolha::daFolhaExportada(['totalHorasTrabalhadas' => '8:00', 'totalHorasExtras' => '0:00'] + $dados, $linhas),
            $impressao->intervalos([$this->dia($dia)], 60, 660),
        );
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function dia(array $campos = []): array
    {
        return $campos + [
            'diaMes' => '05', 'chaveDia' => '2026-10-05',
            'entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '17:00:00',
            'fimSemana' => false, 'isFeriado' => false, 'nomeFeriado' => null,
            'minutosTrabalhadosDia' => 480, 'saldoDia' => 0, 'saldoAcumulado' => 0, 'registroIncompleto' => false,
        ];
    }

    /** @return array<string, string> */
    private function dadosExportados(): array
    {
        return [
            'inicioMes' => '01/10/2026', 'fimMes' => '31/10/2026', 'nomeEmpresa' => 'Escritório Modelo', 'nomeUsuario' => 'Maria da Silva',
            'cpf' => '123.456.789-01', 'ctps' => '0012329',
            'totalHorasTrabalhadas' => '168:00', 'totalHorasExtras' => '2:00', 'saldoBancoAnterior' => '+1:00', 'saldoBancoAtual' => '+3:00', 'horasACompensar' => '0:00',
        ];
    }

    private function justificativa(string $situacao): JustificativaPonto
    {
        return (new JustificativaPonto())->setData(new \DateTime('2026-10-06'))->setTipo('atestado_medico')->setStatus($situacao);
    }
}
