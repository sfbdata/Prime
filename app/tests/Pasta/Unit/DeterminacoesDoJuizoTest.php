<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Pasta\DTO\DeterminacaoDoJuizoOutput;
use App\Pasta\DTO\TeorDePublicacaoInput;
use App\Pasta\Service\DeterminacoesDoJuizo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra do "Exigido pelo juízo" (DOC-79) sobre teores no formato que o DJEN entrega: texto
 * corrido de TJ (cabeçalho em maiúsculas, partes, advogados, decisão num bloco só) e HTML.
 *
 * Os teores abaixo são FICTÍCIOS — nomes, OABs e números inventados —, montados no molde das
 * intimações do TJDFT que chegam pelo Push (cabeçalho "Número do processo / Classe judicial /
 * AUTOR / REU", decisão, "Intime-se", assinatura eletrônica). Nenhum dado de cliente.
 */
#[CoversClass(DeterminacoesDoJuizo::class)]
#[CoversClass(DeterminacaoDoJuizoOutput::class)]
final class DeterminacoesDoJuizoTest extends TestCase
{
    /** Intimação de emenda da inicial, texto corrido, como o TJDFT manda. */
    private const TEOR_EMENDA = 'Poder Judiciário da União TRIBUNAL DE JUSTIÇA DO DISTRITO FEDERAL E DOS TERRITÓRIOS '
        . '3VARCIVBSB 3ª Vara Cível de Brasília Número do processo: 0712345-67.2026.8.07.0001 '
        . 'Classe judicial: PROCEDIMENTO COMUM CÍVEL (7) AUTOR: JOANA EXEMPLO DA SILVA '
        . 'Advogado do(a) AUTOR: CARLOS FICTICIO - DF00000 REU: BANCO MODELO S.A. DECISÃO '
        . 'Trata-se de ação de indenização por danos morais. Verifico que a petição inicial não veio '
        . 'acompanhada dos documentos indispensáveis. Intime-se a parte autora para, no prazo de 15 '
        . '(quinze) dias, emendar a petição inicial, juntando procuração atualizada e comprovante de '
        . 'residência em seu nome, sob pena de indeferimento. Após, voltem conclusos. '
        . 'Documento assinado eletronicamente por MARIA JUIZA EXEMPLO - Juíza de Direito.';

    private function servico(): DeterminacoesDoJuizo
    {
        return new DeterminacoesDoJuizo($this->createStub(PublicacaoDjenRepository::class));
    }

    private function teor(
        string $texto,
        ?string $tipoDocumento = 'Decisão',
        ?string $tipoComunicacao = 'Intimação',
        string $data = '2026-09-03',
        int $id = 501,
        ?string $idDoDocumento = '987654321',
    ): TeorDePublicacaoInput {
        return new TeorDePublicacaoInput($id, $tipoDocumento, $tipoComunicacao, new \DateTimeImmutable($data), $idDoDocumento, $texto);
    }

    /** @return list<DeterminacaoDoJuizoOutput> */
    private function comDocumento(TeorDePublicacaoInput $teor): array
    {
        return array_values(array_filter(
            $this->servico()->determinacoesDoTeor($teor),
            static fn (DeterminacaoDoJuizoOutput $d): bool => $d->documentos !== [],
        ));
    }

    #[TestDox('teor real de emenda: acha a determinação, o prazo explícito de 15 dias e os documentos que ela manda juntar')]
    public function testTeorRealDeEmenda(): void
    {
        $achadas = $this->comDocumento($this->teor(self::TEOR_EMENDA));

        self::assertCount(1, $achadas, 'uma frase manda juntar documento');
        $d = $achadas[0];
        self::assertSame(['procuracao', 'residencia', 'inicial'], $d->documentos, 'na ordem do catálogo; "petição inicial" está no trecho');
        self::assertSame(15, $d->prazoQuantidade);
        self::assertSame('dias', $d->prazoUnidade);
        self::assertNull($d->prazoContagem, 'o texto não diz úteis nem corridos — não se presume');
        self::assertSame('prazo de 15 dias', $d->prazoTexto());
        self::assertStringStartsWith('Intime-se a parte autora', $d->trecho);
        self::assertSame(501, $d->publicacaoId);
        self::assertSame('Origem: Decisão de 03/09/2026 (ID 987654321)', $d->origemTexto());
    }

    #[TestDox('"voltem conclusos" vira determinação sem prazo e sem documento')]
    public function testConclusos(): void
    {
        $todas     = $this->servico()->determinacoesDoTeor($this->teor(self::TEOR_EMENDA));
        $conclusos = array_values(array_filter($todas, static fn (DeterminacaoDoJuizoOutput $d): bool => $d->ato === 'Autos conclusos'));

        self::assertCount(1, $conclusos);
        self::assertNull($conclusos[0]->prazoQuantidade);
        self::assertSame([], $conclusos[0]->documentos);
    }

    #[TestDox('teor sem verbo de determinação (relatório, fundamentação) não devolve nada')]
    public function testSemVerbo(): void
    {
        $teor = $this->teor(
            'Trata-se de ação de cobrança ajuizada por EMPRESA FICTICIA LTDA em face de JOSE MODELO. '
            . 'A parte ré foi citada e apresentou contestação tempestiva. É o relatório. Decido.',
        );

        self::assertSame([], $this->servico()->determinacoesDoTeor($teor));
    }

    #[TestDox('teor vazio, ou só com marcação, não devolve nada e não conta como lido')]
    public function testTeorVazio(): void
    {
        $leitura = $this->servico()->ler([$this->teor(''), $this->teor('<p> </p>')]);

        self::assertSame([], $leitura->determinacoes);
        self::assertSame(0, $leitura->publicacoesLidas, 'sem texto de verdade não há leitura — a tela continua dizendo que o processo não foi lido');
    }

    #[TestDox('prazo por extenso e "dias úteis": "no prazo de cinco dias úteis" vira 5 dias úteis')]
    public function testPrazoPorExtenso(): void
    {
        $achadas = $this->comDocumento($this->teor('Intime-se o exequente para, no prazo de cinco dias úteis, apresentar memória de cálculo atualizada do débito.'));

        self::assertCount(1, $achadas);
        self::assertSame(['calculo'], $achadas[0]->documentos);
        self::assertSame('prazo de 5 dias úteis', $achadas[0]->prazoTexto());
    }

    #[TestDox('"em 15 dias" qualifica a determinação, mas NÃO vira prazo: prazo só quando o texto diz "prazo de N dias"')]
    public function testPrazoNaoExplicitoNaoViraPrazo(): void
    {
        $achadas = $this->comDocumento($this->teor('Intime-se o autor para juntar a procuração em 15 dias.'));

        self::assertCount(1, $achadas);
        self::assertSame(['procuracao'], $achadas[0]->documentos);
        self::assertNull($achadas[0]->prazoQuantidade);
        self::assertNull($achadas[0]->prazoTexto());
    }

    #[TestDox('teor em HTML: a marcação sai antes da leitura, e "prazo de 10 (dez) dias úteis" é lido')]
    public function testTeorEmHtml(): void
    {
        $html = '<p><b>DECISÃO</b></p><p>Defiro a gratuidade provisoriamente.</p>'
            . '<p>Junte o requerente, no prazo de 10 (dez) dias úteis, declaração de hipossuficiência atualizada.</p>'
            . '<p>Cumpra-se.</p>';

        $achadas = $this->comDocumento($this->teor($html));

        self::assertCount(1, $achadas);
        self::assertSame(['hipossuf'], $achadas[0]->documentos);
        self::assertSame('prazo de 10 dias úteis', $achadas[0]->prazoTexto());
        self::assertStringNotContainsString('<', $achadas[0]->trecho);
    }

    #[TestDox('a regra encontra TUDO: uma determinação que pede quatro documentos devolve os quatro')]
    public function testEncontraTudo(): void
    {
        $achadas = $this->comDocumento($this->teor(
            'Intime-se a parte autora para, no prazo de 15 dias, apresentar procuração, cópia do RG, '
            . 'comprovante de residência e declaração de hipossuficiência.',
        ));

        self::assertCount(1, $achadas);
        self::assertSame(['procuracao', 'identidade', 'residencia', 'hipossuf'], $achadas[0]->documentos);
    }

    #[TestDox('a regra não encontra NADA: determinações sem documento (audiência, vista) não pedem documento nenhum')]
    public function testNaoEncontraNada(): void
    {
        $teor = $this->teor(
            'Designo audiência de conciliação para o dia 10/11/2026, às 14h. Intimem-se as partes para comparecimento. '
            . 'Dê-se vista às partes pelo prazo comum de 5 dias.',
        );

        $todas = $this->servico()->determinacoesDoTeor($teor);

        self::assertNotEmpty($todas, 'há determinação');
        self::assertSame([], $this->comDocumento($teor), 'mas nenhuma manda juntar documento');
    }

    #[TestDox('publicação de tipo que não carrega determinação (edital) é ignorada, mesmo com verbo e prazo')]
    public function testTipoSemDeterminacao(): void
    {
        $teor = $this->teor(
            'Junte o interessado, no prazo de 20 dias, procuração com poderes específicos.',
            'Edital',
            'Edital',
        );

        self::assertSame([], $this->servico()->determinacoesDoTeor($teor));
    }

    #[TestDox('o filtro de tipo remove TUDO: a leitura conta as publicações lidas e não devolve determinação')]
    public function testFiltroRemoveTudo(): void
    {
        $leitura = $this->servico()->ler([
            $this->teor('Junte o interessado, no prazo de 20 dias, procuração.', 'Edital', 'Edital', id: 1),
            $this->teor('Junte o interessado, no prazo de 20 dias, procuração.', 'Lista de distribuição', 'Lista de Distribuição', id: 2),
        ]);

        self::assertSame(2, $leitura->publicacoesLidas);
        self::assertSame([], $leitura->determinacoes);
    }

    #[TestDox('publicação cujo TIPO é o documento ("Sentença") vira "Processo: Sentença (ID x) · data"')]
    public function testDocumentoDoProcessoPeloTipo(): void
    {
        $leitura = $this->servico()->ler([
            $this->teor('Ante o exposto, julgo procedente o pedido.', 'Sentença', 'Intimação', '2026-08-20', 7, '555'),
        ]);

        self::assertSame([['chave' => 'sentenca', 'rotulo' => 'Processo: Sentença (ID 555) · 20/08/2026']], $leitura->documentosDoProcesso);
    }

    #[TestDox('frases são quebradas no ponto seguido de maiúscula; o trecho exibido tem no máximo 220 caracteres')]
    public function testFrasesETrecho(): void
    {
        $longa = 'Intime-se a parte autora para, no prazo de 15 dias, juntar procuração ' . str_repeat('com poderes bastantes e específicos ', 8) . '.';

        $achadas = $this->comDocumento($this->teor($longa));

        self::assertCount(1, $achadas);
        self::assertSame(220, mb_strlen($achadas[0]->trecho));
        self::assertSame(['Primeira frase aqui.', 'Segunda frase ali.'], DeterminacoesDoJuizo::frases('Primeira frase aqui. Segunda frase ali.'));
    }

    #[TestDox('"prazo certamente em curso" é limite inferior: disponibilização + N dias corridos')]
    public function testPrazoEmCurso(): void
    {
        $d = new DeterminacaoDoJuizoOutput(1, 'Decisão', new \DateTimeImmutable('2026-09-01'), null, 'Manifestação', 't', 15, 'dias', 'uteis', []);

        self::assertTrue($d->prazoCertamenteEmCursoEm(new \DateTimeImmutable('2026-09-16 23:00')));
        self::assertFalse($d->prazoCertamenteEmCursoEm(new \DateTimeImmutable('2026-09-17 00:01')));
        self::assertSame('Manifestação: prazo de 15 dias úteis (Decisão de 01/09/2026)', $d->linhaDePrazo());

        $horas = new DeterminacaoDoJuizoOutput(1, 'Decisão', new \DateTimeImmutable('2026-09-01'), null, 'Pagamento / depósito', 't', 48, 'horas', null, []);
        self::assertTrue($horas->prazoCertamenteEmCursoEm(new \DateTimeImmutable('2026-09-03')));
        self::assertFalse($horas->prazoCertamenteEmCursoEm(new \DateTimeImmutable('2026-09-04')));

        $semPrazo = new DeterminacaoDoJuizoOutput(1, 'Decisão', new \DateTimeImmutable('2026-09-01'), null, 'Manifestação', 't', null, null, null, []);
        self::assertFalse($semPrazo->prazoCertamenteEmCursoEm(new \DateTimeImmutable('2026-09-01')), 'sem prazo explícito não se afirma nada');

        $semData = new DeterminacaoDoJuizoOutput(1, 'Decisão', null, null, 'Manifestação', 't', 15, 'dias', null, []);
        self::assertFalse($semData->prazoCertamenteEmCursoEm(new \DateTimeImmutable('2026-09-01')), 'sem data não se afirma nada');
    }
}
