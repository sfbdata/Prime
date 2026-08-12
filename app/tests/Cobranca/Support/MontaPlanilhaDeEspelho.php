<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Monta planilhas com o MESMO layout do relatório real da contabilidade.
 *
 * As planilhas de verdade estão em `docs/gestao-cobrancas/planilhas atualizadas/`, que é
 * **gitignored** por conter dado de cliente — logo os testes montam as próprias, o que também os
 * torna reproduzíveis. O layout aqui foi extraído do TL1 de 12/08 célula a célula: 4 linhas
 * institucionais, uma em branco, o cabeçalho na linha 6, os dados a partir da 7, o bloco totalizador
 * nas **duas** formas e o rodapé com `Inadimplência até:` sem espaço após os dois-pontos.
 *
 * Quem usa precisa chamar {@see self::limparPlanilhas()} no `tearDown()`.
 */
trait MontaPlanilhaDeEspelho
{
    /** @var list<string> */
    private array $planilhasTemporarias = [];

    /** Cabeçalho da linha 6, nome a nome — é o que o leitor valida antes de aceitar o arquivo. */
    private const CABECALHO_ESPELHO = [
        'Unidade', 'Sacado', 'NN', 'Classe de conta', 'Competência', 'Vencimento', 'Atraso',
        'Valor (R$)', 'Juros (R$)', 'Multa (R$)', 'Correção (R$)', 'Honorários (R$)', 'Total (R$)',
        'Informações do acordo', 'Recebimento',
    ];

    protected function limparPlanilhas(): void
    {
        foreach ($this->planilhasTemporarias as $caminho) {
            if (is_file($caminho)) {
                unlink($caminho);
            }
        }

        $this->planilhasTemporarias = [];
    }

    /**
     * @param list<list<mixed>> $linhas
     * @param list<string>|null $cabecalho          para provar que coluna fora de posição é recusada
     * @param float|null        $valorTotalAdulterado para provar que a reconciliação enxerga adulteração
     */
    protected function montarPlanilha(
        array $linhas,
        ?array $cabecalho = null,
        ?float $valorTotalAdulterado = null,
        string $dadosAte = '12/08/2026',
        string $emissao = '12/08/2026 09:42',
    ): string {
        $soma = ['valor' => 0.0, 'juros' => 0.0, 'multa' => 0.0, 'correcao' => 0.0, 'honorarios' => 0.0, 'total' => 0.0];

        foreach ($linhas as $linha) {
            $soma['valor'] += (float) ($linha[7] ?? 0);
            $soma['juros'] += (float) ($linha[8] ?? 0);
            $soma['multa'] += (float) ($linha[9] ?? 0);
            $soma['correcao'] += (float) ($linha[10] ?? 0);
            $soma['honorarios'] += (float) ($linha[11] ?? 0);
            $soma['total'] += (float) ($linha[12] ?? 0);
        }

        $valorTotal = $valorTotalAdulterado ?? $soma['valor'];

        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();

        // `strictNullComparison: true` é OBRIGATÓRIO. Sem ele o `fromArray` compara com `!=` (solto),
        // e como `0 != null` é FALSO em PHP, toda célula com zero seria silenciosamente omitida — e o
        // teste "célula vazia não vira zero" passaria a medir o contrário do que promete. O arquivo
        // real tem zeros de verdade (a coluna Correção é 0 em 100% das linhas).
        $aba->fromArray([
            ['L. G Soluções Contábeis Eireli'],
            ['INADIMPLÊNCIA DETALHADA'],
            ['Número de unidades: 96'],
            ['Juros: 1,00% ao mês; Multa: 2,00%; Honorários: 20,00%.'],
            [null],
        ], null, 'A1', true);

        $aba->fromArray([$cabecalho ?? self::CABECALHO_ESPELHO], null, 'A6', true);
        $aba->fromArray($linhas, null, 'A7', true);

        $proxima = 7 + count($linhas);

        // Forma LARGA: rótulo em A, valores em H..M.
        $aba->fromArray([[
            'Total inadimplência das unidades', null, null, null, null, null, null,
            $valorTotal, $soma['juros'], $soma['multa'], $soma['correcao'], $soma['honorarios'], $soma['total'],
        ]], null, 'A' . $proxima, true);

        $proxima += 2;

        // Cabeçalho do 2º bloco e forma ESTREITA: rótulo em A, valores em B..G.
        $aba->fromArray([
            ['Classe de conta', 'Valor (R$)', 'Juros (R$)', 'Multa (R$)', 'Correção (R$)', 'Honorários (R$)', 'Total (R$)'],
            ['1.1 - Taxa de condomínio', $valorTotal, $soma['juros'], $soma['multa'], $soma['correcao'], $soma['honorarios'], $soma['total']],
            ['Total de inadimplência', $valorTotal, $soma['juros'], $soma['multa'], $soma['correcao'], $soma['honorarios'], $soma['total']],
        ], null, 'A' . $proxima, true);

        $proxima += 4;

        $aba->fromArray([
            [sprintf('Filtros:  Inadimplência até:%s; Competência: Todas; Período de vencimento: Todos', $dadosAte)],
            [null],
            ['L. G Soluções Contábeis Eireli - Brasília, DF'],
            [sprintf('Emissão: %s', $emissao)],
        ], null, 'A' . $proxima, true);

        $caminho = sys_get_temp_dir() . '/espelho_teste_' . uniqid('', true) . '.xlsx';
        (new Xlsx($planilha))->save($caminho);
        $planilha->disconnectWorksheets();

        $this->planilhasTemporarias[] = $caminho;

        return $caminho;
    }

    /** Uma linha de dado com o formato do relatório real. */
    protected function linhaDeDado(
        string $unidade = '01-01',
        string $sacado = 'FULANO DE TAL',
        string $nn = '74608',
        string $classe = '1.1 - Taxa de condomínio',
        string $competencia = '02/2026',
        string $vencimento = '10/02/2026',
        int $atraso = 183,
        float $valor = 190.00,
        float $juros = 11.59,
        float $multa = 3.80,
        float $correcao = 0.0,
        float $honorarios = 41.08,
        ?float $total = null,
        string $acordo = '-',
        string $recebimento = '-',
    ): array {
        return [
            $unidade, $sacado, $nn, $classe, $competencia, $vencimento, (string) $atraso,
            $valor, $juros, $multa, $correcao, $honorarios,
            $total ?? ($valor + $juros + $multa + $correcao + $honorarios),
            $acordo, $recebimento,
        ];
    }
}
