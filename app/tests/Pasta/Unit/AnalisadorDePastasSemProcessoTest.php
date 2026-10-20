<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\AnaliseSemProcessoDaPasta;
use App\Pasta\DTO\PastaParaAnaliseSemProcesso;
use App\Pasta\DTO\ResumoSemProcesso;
use App\Pasta\Service\AnalisadorDePastasSemProcesso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Porte de `spAnalisar` (desenho 1.2.3, L4513-4537): cada categoria com o texto exato da sugestão e
 * da confiança, a expressão do número CNJ, o peso reduzido pelo aprendizado (L4518-4520), as metas
 * em dias úteis (L4530-4531) e o resumo para despacho (L4532-4535).
 */
#[CoversClass(AnalisadorDePastasSemProcesso::class)]
#[CoversClass(ResumoSemProcesso::class)]
final class AnalisadorDePastasSemProcessoTest extends TestCase
{
    private const CNJ = '0701134-57.2025.8.07.0007';

    /** Sexta-feira: os prazos em dias úteis pulam o fim de semana. */
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->hoje = new \DateTimeImmutable('2026-10-16 10:00:00');
    }

    /**
     * @param list<array{rotulo: string, texto: string}>         $fontes
     * @param list<array{em: \DateTimeImmutable, autor: string}> $registros
     */
    private static function pasta(
        array $fontes = [],
        bool $marcada = false,
        ?string $processo = null,
        array $registros = [],
        int $documentos = 0,
        int $id = 1,
    ): PastaParaAnaliseSemProcesso {
        return new PastaParaAnaliseSemProcesso(
            id: $id,
            numero: (string) (1179 + $id),
            cliente: 'CLIENTE ' . $id,
            responsavel: 'Ana Souza',
            responsavelId: 10,
            processoNumero: $processo,
            processoTribunal: $processo !== null ? 'TJDFT' : null,
            marcada: $marcada,
            fontes: $fontes,
            registros: $registros,
            totalDocumentos: $documentos,
        );
    }

    private static function registro(string $texto, string $autor = 'Ana Souza'): array
    {
        return ['rotulo' => 'Registro de ' . $autor, 'texto' => $texto];
    }

    /**
     * @param array<int, array{decisao: string, por: string, termos: list<string>}> $decisoes
     */
    private function analisar(PastaParaAnaliseSemProcesso $pasta, array $decisoes = []): AnaliseSemProcessoDaPasta
    {
        return (new AnalisadorDePastasSemProcesso())->analisar([$pasta], $decisoes, $this->hoje)[0];
    }

    #[TestDox('Com processo e marcada como administrativa: inconsistente, confirmado, meta para hoje')]
    public function testInconsistente(): void
    {
        $a = $this->analisar(self::pasta(marcada: true, processo: self::CNJ));

        self::assertSame(AnaliseSemProcessoDaPasta::INCONSISTENTE, $a->categoria);
        self::assertSame('A pasta tem o processo ' . self::CNJ . ' vinculado mas está marcada como administrativa. Desmarcar "Administrativo sem processo".', $a->sugestao);
        self::assertSame('confirmado', $a->confianca);
        self::assertSame([['titulo' => 'Desmarcar "Administrativo sem processo"', 'prazo' => 'hoje', 'responsavel' => 'Ana Souza']], $a->metas);
        self::assertSame('Desmarcar "Administrativo sem processo" até hoje', $a->proximoPasso);
        self::assertSame('tem processo e está marcada administrativa · nenhum registro · sem documentos', $a->resumo);
    }

    #[TestDox('Com processo vinculado: categoria com, sem sugestão nem resumo para despacho')]
    public function testComProcesso(): void
    {
        $a = $this->analisar(self::pasta(processo: self::CNJ));

        self::assertSame(AnaliseSemProcessoDaPasta::COM_PROCESSO, $a->categoria);
        self::assertSame('', $a->sugestao);
        self::assertSame('confirmado', $a->confianca);
        self::assertSame('', $a->resumo);
        self::assertSame([], $a->metas);
        self::assertFalse($a->pedeAcao());
    }

    #[TestDox('Número CNJ no texto: deveria ter processo, provável, sugestão cita o número e a fonte')]
    public function testDeveriaComNumeroCnj(): void
    {
        $a = $this->analisar(self::pasta([self::registro('Distribuído o processo ' . self::CNJ . ' na 1ª Vara Cível.')]));

        self::assertSame(AnaliseSemProcessoDaPasta::DEVERIA, $a->categoria);
        self::assertSame(self::CNJ, $a->cnj);
        self::assertSame('Vincular o processo ' . self::CNJ . ' citado em registro de ana souza.', $a->sugestao);
        self::assertSame('provavel', $a->confianca);
        self::assertSame([['número de processo', 'Registro de Ana Souza'], ['vara/tribunal', 'Registro de Ana Souza'], ['processo', 'Registro de Ana Souza']], $a->termos);
        self::assertSame('Localizar o número do processo no PJe (' . self::CNJ . ')', $a->metas[0]['titulo']);
        self::assertSame('conteúdo indica processo nº ' . self::CNJ . ' · nenhum registro · sem documentos', $a->resumo);
    }

    #[TestDox('A expressão do CNJ exige a máscara: 20 dígitos corridos não contam como número de processo')]
    public function testCnjSemMascaraNaoCasa(): void
    {
        $a = $this->analisar(self::pasta([self::registro('Número 07011345720258070007 anotado.')]));

        self::assertNull($a->cnj);
        self::assertSame([], $a->termos);
    }

    #[TestDox('Conteúdo judicial sem número: deveria, hipótese abaixo de 4 pontos, sugestão com os termos')]
    public function testDeveriaSemNumero(): void
    {
        $a = $this->analisar(self::pasta([['rotulo' => 'Meta', 'texto' => 'Preparar a Petição Inicial']]));

        self::assertSame(AnaliseSemProcessoDaPasta::DEVERIA, $a->categoria);
        self::assertSame('O conteúdo indica processo judicial (petição inicial). Cadastrar o número do processo.', $a->sugestao);
        self::assertSame('hipotese', $a->confianca);
    }

    #[TestDox('Marcada com conteúdo judicial forte: rever, provável a partir de 5 pontos')]
    public function testRever(): void
    {
        $a = $this->analisar(self::pasta([self::registro('Audiência marcada; contestação protocolada.')], marcada: true));

        self::assertSame(AnaliseSemProcessoDaPasta::REVER, $a->categoria);
        self::assertSame(
            'Marcada como administrativa, mas o conteúdo indica atuação judicial (contestação, audiência, protocolo). Rever o cadastro: localizar o processo e vincular, ou manter a marcação se for só consultoria.',
            $a->sugestao,
        );
        self::assertSame('provavel', $a->confianca);
        self::assertSame(['Revisar a classificação com o responsável', 'Vincular o processo ou confirmar como administrativa'], array_column($a->metas, 'titulo'));
    }

    #[TestDox('Marcada e coerente: admin provável quando há termo administrativo, declarado sem conteúdo')]
    public function testAdministrativaCoerenteEDeclarada(): void
    {
        $coerente  = $this->analisar(self::pasta([self::registro('Parecer da consultoria tributária')], marcada: true));
        $declarada = $this->analisar(self::pasta(marcada: true));

        self::assertSame(AnaliseSemProcessoDaPasta::ADMINISTRATIVA, $coerente->categoria);
        self::assertSame('Coerente: consultoria/parecer.', $coerente->sugestao);
        self::assertSame('provavel', $coerente->confianca);
        self::assertSame('', $coerente->resumo);
        self::assertSame('Marcada manualmente; não há conteúdo que contradiga.', $declarada->sugestao);
        self::assertSame('declarado', $declarada->confianca);
    }

    #[TestDox('Só conteúdo administrativo, sem marcar: sugere marcar como administrativa')]
    public function testSugereAdministrativa(): void
    {
        $a = $this->analisar(self::pasta([['rotulo' => 'Documento', 'texto' => 'Notificação extrajudicial ao devedor']]));

        self::assertSame(AnaliseSemProcessoDaPasta::SUGERE_ADMINISTRATIVA, $a->categoria);
        self::assertSame('O conteúdo indica atuação administrativa (extrajudicial). Sugestão: marcar como "Administrativo sem processo".', $a->sugestao);
        self::assertSame('hipotese', $a->confianca);
    }

    #[TestDox('Sem conteúdo ou com conteúdo neutro: sem classificação, "sem dados", textos distintos')]
    public function testClassificar(): void
    {
        $vazia  = $this->analisar(self::pasta());
        $neutra = $this->analisar(self::pasta([['rotulo' => 'Checklist', 'texto' => 'Procuração assinada']]));

        self::assertSame(AnaliseSemProcessoDaPasta::CLASSIFICAR, $vazia->categoria);
        self::assertSame('Pasta sem conteúdo registrado. O responsável deve vincular o processo ou marcar como administrativa.', $vazia->sugestao);
        self::assertSame('sem dados', $vazia->confianca);
        self::assertSame('Conteúdo insuficiente para decidir. O responsável deve vincular o processo ou marcar como administrativa.', $neutra->sugestao);
        self::assertSame('sem processo e sem classificação · nenhum registro · sem documentos', $vazia->resumo);
    }

    #[TestDox('Termo administrativo desconta 1,5 ponto do conteúdo judicial')]
    public function testTermoAdministrativoDesconta(): void
    {
        $a = $this->analisar(self::pasta([self::registro('Petição inicial ou parecer?')]));

        self::assertSame(AnaliseSemProcessoDaPasta::SUGERE_ADMINISTRATIVA, $a->categoria);
    }

    #[TestDox('Aprendizado: termo em 2 decisões "administrativo" do escritório pesa 40%')]
    public function testPesoReduzidoPeloAprendizado(): void
    {
        $pasta    = self::pasta([self::registro('Preparar a petição inicial')], id: 1);
        $uma      = [2 => ['decisao' => 'administrativo', 'por' => 'Bia', 'termos' => ['petição inicial']]];
        $duas     = $uma + [3 => ['decisao' => 'administrativo', 'por' => 'Bia', 'termos' => ['petição inicial']]];
        $precisam = [2 => ['decisao' => 'precisa', 'por' => 'Bia', 'termos' => ['petição inicial']], 3 => ['decisao' => 'precisa', 'por' => 'Bia', 'termos' => ['petição inicial']]];

        self::assertSame(AnaliseSemProcessoDaPasta::DEVERIA, $this->analisar($pasta, $uma)->categoria);
        self::assertSame(AnaliseSemProcessoDaPasta::DEVERIA, $this->analisar($pasta, $precisam)->categoria);
        self::assertSame(AnaliseSemProcessoDaPasta::CLASSIFICAR, $this->analisar($pasta, $duas)->categoria);
    }

    #[TestDox('A decisão anterior da própria pasta aparece na análise (decisão e quem)')]
    public function testDecisaoAnterior(): void
    {
        $a = $this->analisar(self::pasta(id: 7), [7 => ['decisao' => 'precisa', 'por' => 'Bia Lima', 'termos' => ['audiência']]]);

        self::assertSame(['decisao' => 'precisa', 'por' => 'Bia Lima'], $a->decisaoAnterior);
    }

    #[TestDox('Metas sugeridas em dias úteis a partir de uma sexta-feira (pulam sábado e domingo)')]
    public function testMetasEmDiasUteis(): void
    {
        $a = $this->analisar(self::pasta([self::registro('Preparar a petição inicial e a audiência')]));

        self::assertSame(['20/10', '21/10', '23/10'], array_column($a->metas, 'prazo'));
        self::assertSame('Localizar o número do processo no PJe até 20/10', $a->proximoPasso);
        self::assertSame('2026-10-19', AnalisadorDePastasSemProcesso::diasUteis($this->hoje, 1)->format('Y-m-d'));
    }

    #[TestDox('Resumo para despacho: registros (o mais recente, primeiro nome) e documentos')]
    public function testResumoComRegistrosEDocumentos(): void
    {
        $registros = [
            ['em' => new \DateTimeImmutable('2026-10-15 09:00'), 'autor' => 'Carlos Lima'],
            ['em' => new \DateTimeImmutable('2026-10-02 09:00'), 'autor' => 'Ana Souza'],
        ];
        $a = $this->analisar(self::pasta(registros: $registros, documentos: 3));

        self::assertSame('sem processo e sem classificação · 2 registro(s), último 15/10 por Carlos · 3 doc(s)', $a->resumo);
    }

    #[TestDox('termosJudiciais devolve só os rótulos, na ordem de SP_JUD')]
    public function testTermosJudiciais(): void
    {
        $termos = (new AnalisadorDePastasSemProcesso())->termosJudiciais([self::registro('Recurso de apelação após a sentença')]);

        self::assertSame(['sentença/decisão', 'recurso'], $termos);
    }

    #[TestDox('ResumoSemProcesso conta como `spResumo` (rever + inconsistente, classificar + sugAdmin)')]
    public function testResumo(): void
    {
        $analises = (new AnalisadorDePastasSemProcesso())->analisar([
            self::pasta(id: 1),
            self::pasta([['rotulo' => 'Documento', 'texto' => 'Parecer']], id: 2),
            self::pasta(marcada: true, processo: self::CNJ, id: 3),
            self::pasta(processo: self::CNJ, id: 4),
            self::pasta([self::registro('Contestação e audiência')], id: 5),
            self::pasta(marcada: true, id: 6),
        ], [], $this->hoje);

        $r = ResumoSemProcesso::deAnalises($analises);

        self::assertSame([4, 1, 1, 2, 1], [$r->sem, $r->deveria, $r->rever, $r->classificar, $r->admin]);
    }
}
