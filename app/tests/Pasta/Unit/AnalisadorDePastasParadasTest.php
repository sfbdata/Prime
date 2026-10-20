<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\PastaParada;
use App\Pasta\DTO\PastaParaAnaliseDeParada;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\Service\AnalisadorDePastasParadas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `paradasDados` do desenho 02 (L4704-4737) e a spec docs/specs/pastas-paradas.md: o limite de 30
 * dias, as faixas, a última movimentação, quem sai da lista, os textos da pré-análise e as
 * sugestões, e o financeiro só para quem pode vê-lo.
 */
#[CoversClass(AnalisadorDePastasParadas::class)]
#[CoversClass(PastaParada::class)]
#[CoversClass(RelatorioPastasParadasOutput::class)]
#[Group('pasta')]
final class AnalisadorDePastasParadasTest extends TestCase
{
    private const HOJE = '2026-10-05 10:05:00';

    private static function hoje(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::HOJE, new \DateTimeZone('America/Sao_Paulo'));
    }

    /** Data `$dias` antes de hoje, a uma hora qualquer do dia (a conta é por data). */
    private static function ha(int $dias, string $hora = '17:40'): \DateTimeImmutable
    {
        return self::hoje()->modify('-' . $dias . ' days')->modify($hora);
    }

    /**
     * Pasta "em ordem" (contrato assinado, os dois documentos, pagamentos em dia, processo comum)
     * aberta há 200 dias; cada teste muda só o que interessa.
     *
     * @param array<string, mixed> $muda
     */
    private static function pasta(array $muda = []): PastaParaAnaliseDeParada
    {
        $base = [
            'id' => 1, 'numero' => '1180', 'cliente' => 'Ana Souza', 'responsavel' => 'Rui Responsável', 'responsavelId' => 7,
            'abertura' => self::ha(200), 'processoNumero' => '0701134-57.2025.8.07.0007', 'processoTribunal' => 'TJDFT',
            'processoSituacao' => '', 'movimentacoes' => [], 'faseAtual' => null, 'faseDesde' => null, 'andamentoEncerrado' => false,
            'proBono' => false, 'contratoAssinado' => true, 'totalDocumentos' => 2, 'temProcuracao' => true,
            'temContratoDeHonorarios' => true, 'pagamentos' => ['total' => 1, 'vencidos' => 0],
        ];

        return new PastaParaAnaliseDeParada(...array_merge($base, $muda));
    }

    /**
     * @param list<PastaParaAnaliseDeParada> $pastas
     *
     * @return list<PastaParada>
     */
    private static function analisar(array $pastas, bool $veFinanceiro = false): array
    {
        return (new AnalisadorDePastasParadas())->analisar($pastas, self::hoje(), static fn (int $id): bool => $veFinanceiro);
    }

    private static function unica(PastaParaAnaliseDeParada $pasta, bool $veFinanceiro = false): PastaParada
    {
        $lista = self::analisar([$pasta], $veFinanceiro);
        self::assertCount(1, $lista);

        return $lista[0];
    }

    #[TestDox('Limite: 30 dias parada não entra; 31 entra')]
    public function testLimiteDeTrintaDias(): void
    {
        self::assertSame([], self::analisar([self::pasta(['movimentacoes' => [self::ha(30)]])]));
        self::assertSame(31, self::unica(self::pasta(['movimentacoes' => [self::ha(31)]]))->dias);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function faixas(): iterable
    {
        yield '31 dias' => [31, 'Acompanhar', '#0a6a96'];
        yield '60 dias' => [60, 'Acompanhar', '#0a6a96'];
        yield '61 dias' => [61, 'Atenção', '#b06a00'];
        yield '90 dias' => [90, 'Atenção', '#b06a00'];
        yield '91 dias' => [91, 'Crítico', '#a3232b'];
    }

    #[DataProvider('faixas')]
    #[TestDox('Faixa de $dias dias: $nivel ($cor)')]
    public function testFaixas(int $dias, string $nivel, string $cor): void
    {
        $parada = self::unica(self::pasta(['movimentacoes' => [self::ha($dias)]]));

        self::assertSame($nivel, $parada->nivel);
        self::assertSame($cor, $parada->cor);
        self::assertSame($dias > 90, $parada->critica());
    }

    #[TestDox('Última movimentação: o máximo entre movimentação, Push, registro e a entrada na fase atual')]
    public function testUltimaMovimentacaoEhOMaximoDasFontes(): void
    {
        $semFase = self::unica(self::pasta(['movimentacoes' => [self::ha(200), self::ha(45), self::ha(120)]]));
        self::assertSame(45, $semFase->dias);
        self::assertSame(self::ha(45)->format('d/m/Y'), $semFase->ultima?->format('d/m/Y'));

        $faseMaisRecente = self::unica(self::pasta([
            'movimentacoes' => [self::ha(200), self::ha(45)],
            'faseAtual' => 'Protocolo', 'faseDesde' => self::ha(40),
        ]));
        self::assertSame(40, $faseMaisRecente->dias);
        self::assertSame('Última movimentação em ' . self::ha(40)->format('d/m/Y') . ' (40 dias parada).', $faseMaisRecente->pontos[1]);

        self::assertSame([], self::analisar([self::pasta(['movimentacoes' => [self::ha(200)], 'faseDesde' => self::ha(10), 'faseAtual' => 'Contrato'])]));
    }

    #[TestDox('Sem nenhuma movimentação: conta da abertura e diz que não há movimentação')]
    public function testSemMovimentacaoUsaAAbertura(): void
    {
        $parada = self::unica(self::pasta(['abertura' => self::ha(50)]));

        self::assertSame(50, $parada->dias);
        self::assertNull($parada->ultima);
        self::assertSame('Pasta aberta em ' . self::ha(50)->format('d/m/Y') . ', hoje faz 50 dias.', $parada->pontos[0]);
        self::assertSame('Nenhuma movimentação registrada desde a abertura.', $parada->pontos[1]);

        self::assertSame([], self::analisar([self::pasta(['abertura' => self::ha(20)])]), 'aberta há 20 dias não está parada');
        self::assertSame([], self::analisar([self::pasta(['abertura' => null])]), 'sem data nenhuma não há como dizer');
    }

    #[TestDox('Sai da lista: andamento na última fase da via; e, sem andamento, processo arquivado, baixado, transitado ou extinto')]
    public function testQuemSaiDaLista(): void
    {
        self::assertSame([], self::analisar([self::pasta(['faseAtual' => 'Finalizada', 'faseDesde' => self::ha(100), 'andamentoEncerrado' => true])]));

        foreach (['Arquivado definitivamente', 'BAIXADO', 'Transitado em julgado', 'Extinto'] as $situacao) {
            self::assertSame([], self::analisar([self::pasta(['processoSituacao' => $situacao])]), $situacao);
        }

        $comAndamento = self::pasta(['processoSituacao' => 'Arquivado', 'faseAtual' => 'Sentença', 'faseDesde' => self::ha(70)]);
        self::assertCount(1, self::analisar([$comAndamento]), 'com andamento gravado, vale o andamento');
    }

    #[TestDox('Pré-análise de uma pasta em ordem, com andamento e o financeiro visível')]
    public function testPreAnaliseCompleta(): void
    {
        $parada = self::unica(self::pasta(['faseAtual' => 'Protocolo', 'faseDesde' => self::ha(70)]), veFinanceiro: true);

        self::assertSame([
            'Pasta aberta em ' . self::ha(200)->format('d/m/Y') . ', hoje faz 200 dias.',
            'Última movimentação em ' . self::ha(70)->format('d/m/Y') . ' (70 dias parada).',
            'Andamento: na fase "Protocolo" há 70 dias (desde ' . self::ha(70)->format('d/m/Y') . ').',
            'Contrato assinado.',
            'Financeiro: em dia.',
        ], $parada->pontos);
        self::assertSame([AnalisadorDePastasParadas::SUGESTAO_PJE], $parada->sugestoes);
        self::assertSame('Consultar o andamento no PJe e registrar a providência.', AnalisadorDePastasParadas::SUGESTAO_PJE);
    }

    #[TestDox('Pré-análise: abertura não informada, sem processo, contrato não juntado, nenhum documento e nenhum lançamento')]
    public function testPreAnaliseDoPiorCaso(): void
    {
        $parada = self::unica(self::pasta([
            'abertura' => null, 'movimentacoes' => [self::ha(95)], 'processoNumero' => null, 'processoTribunal' => null,
            'contratoAssinado' => false, 'totalDocumentos' => 0, 'temProcuracao' => false, 'temContratoDeHonorarios' => false,
            'pagamentos' => ['total' => 0, 'vencidos' => 0],
        ]), veFinanceiro: true);

        self::assertSame([
            'Data de abertura não informada.',
            'Última movimentação em ' . self::ha(95)->format('d/m/Y') . ' (95 dias parada).',
            'Sem processo vinculado: não há número de processo cadastrado.',
            'Contrato não juntado.',
            'Nenhum documento juntado. Falta: Procuração e Contrato de honorários.',
            'Financeiro: nenhum lançamento.',
        ], $parada->pontos);
        self::assertSame([
            'Confirmar com o cliente a assinatura do contrato e juntar à pasta.',
            'Ver com o Financeiro se o pagamento já foi feito e dar a baixa no sistema.',
            'Solicitar ao cliente os documentos faltantes.',
            'Verificar se a ação já foi distribuída e vincular o processo, ou decidir pelo arquivamento.',
        ], $parada->sugestoes);
        self::assertTrue($parada->semContrato);
        self::assertTrue($parada->semProcesso());
        self::assertSame('Confirmar com o cliente a assinatura do contrato e juntar à pasta.', $parada->primeiraSugestaoSemFinanceiro());
    }

    #[TestDox('Pré-análise: processo suspenso, pró-bono, falta um documento e pagamentos vencidos')]
    public function testPreAnaliseSuspensoEProBono(): void
    {
        $parada = self::unica(self::pasta([
            'movimentacoes' => [self::ha(40)], 'processoSituacao' => 'Suspenso por convenção das partes', 'proBono' => true,
            'contratoAssinado' => false, 'totalDocumentos' => 3, 'temContratoDeHonorarios' => false,
            'pagamentos' => ['total' => 4, 'vencidos' => 2],
        ]), veFinanceiro: true);

        self::assertSame([
            'Pasta aberta em ' . self::ha(200)->format('d/m/Y') . ', hoje faz 200 dias.',
            'Última movimentação em ' . self::ha(40)->format('d/m/Y') . ' (40 dias parada).',
            'Processo 0701134-57.2025.8.07.0007 consta como suspenso.',
            'Contrato: pró-bono.',
            'Falta juntar: Contrato de honorários.',
            'Financeiro: 2 pagamento(s) vencido(s).',
        ], $parada->pontos);
        self::assertSame([
            'Ver com o Financeiro se o pagamento já foi feito e dar a baixa no sistema.',
            'Solicitar ao cliente os documentos faltantes.',
            'Conferir o motivo e o prazo da suspensão no PJe e registrar no expediente.',
        ], $parada->sugestoes);
        self::assertFalse($parada->semContrato, 'pró-bono conta como contrato');
        self::assertSame('Solicitar ao cliente os documentos faltantes.', $parada->primeiraSugestaoSemFinanceiro(), 'o aviso ao responsável pula a do financeiro');
    }

    #[TestDox('Sem procuração (só o contrato juntado): "Falta juntar: Procuração."')]
    public function testFaltaSoAProcuracao(): void
    {
        $parada = self::unica(self::pasta(['movimentacoes' => [self::ha(40)], 'temProcuracao' => false, 'totalDocumentos' => 1]));

        self::assertContains('Falta juntar: Procuração.', $parada->pontos);
        self::assertSame([AnalisadorDePastasParadas::SUGESTAO_DOCUMENTOS, AnalisadorDePastasParadas::SUGESTAO_PJE], $parada->sugestoes);
    }

    #[TestDox('Financeiro oculto para quem não vê: sem a linha e sem a sugestão do financeiro')]
    public function testFinanceiroOculto(): void
    {
        $pasta = self::pasta(['movimentacoes' => [self::ha(40)], 'pagamentos' => ['total' => 3, 'vencidos' => 3]]);

        $oculto = self::unica($pasta, veFinanceiro: false);
        self::assertSame([], array_values(array_filter($oculto->pontos, static fn (string $t): bool => str_starts_with($t, 'Financeiro'))));
        self::assertNotContains(AnalisadorDePastasParadas::SUGESTAO_FINANCEIRO, $oculto->sugestoes);

        $semQuemDiga = (new AnalisadorDePastasParadas())->analisar([$pasta], self::hoje());
        self::assertNotContains(AnalisadorDePastasParadas::SUGESTAO_FINANCEIRO, $semQuemDiga[0]->sugestoes, 'sem a pergunta, ninguém vê');

        $visivel = self::unica($pasta, veFinanceiro: true);
        self::assertContains('Financeiro: 3 pagamento(s) vencido(s).', $visivel->pontos);
    }

    #[TestDox('A visibilidade do financeiro é perguntada só para as pastas que entram na lista')]
    public function testPerguntaOFinanceiroSoDasParadas(): void
    {
        $perguntadas = [];
        (new AnalisadorDePastasParadas())->analisar(
            [self::pasta(['id' => 1, 'movimentacoes' => [self::ha(10)]]), self::pasta(['id' => 2, 'movimentacoes' => [self::ha(40)]])],
            self::hoje(),
            static function (int $id) use (&$perguntadas): bool {
                $perguntadas[] = $id;

                return true;
            },
        );

        self::assertSame([2], $perguntadas);
    }

    #[TestDox('Ordem: a mais parada primeiro; os cartões contam paradas, mais de 90 dias, sem contrato e sem processo')]
    public function testOrdemECartoes(): void
    {
        $lista = self::analisar([
            self::pasta(['id' => 1, 'numero' => '1176', 'movimentacoes' => [self::ha(40)]]),
            self::pasta(['id' => 2, 'numero' => '1178', 'movimentacoes' => [self::ha(120)], 'contratoAssinado' => false]),
            self::pasta(['id' => 3, 'numero' => '1181', 'movimentacoes' => [self::ha(70)], 'processoNumero' => null]),
        ]);

        self::assertSame(['1178', '1181', '1176'], array_map(static fn (PastaParada $p): string => $p->numero, $lista));

        $relatorio = new RelatorioPastasParadasOutput(self::hoje(), $lista);
        self::assertSame([
            ['rotulo' => 'Pastas paradas', 'valor' => 3],
            ['rotulo' => 'Mais de 90 dias', 'valor' => 1],
            ['rotulo' => 'Sem contrato', 'valor' => 1],
            ['rotulo' => 'Sem processo', 'valor' => 1],
        ], $relatorio->cartoes());
        self::assertSame('Pastas paradas há mais de 30 dias · 05/10/2026', $relatorio->titulo());
    }

    #[TestDox('Rodapé: sem atribuir a pré-análise à I.A e sem travessão')]
    public function testRodapeSemIA(): void
    {
        self::assertDoesNotMatchRegularExpression('/\bI\.?A\b|—|–/u', RelatorioPastasParadasOutput::NOTA_FINAL);
        self::assertStringStartsWith('Pré-análise gerada a partir dos dados do sistema;', RelatorioPastasParadasOutput::NOTA_FINAL);
    }
}
