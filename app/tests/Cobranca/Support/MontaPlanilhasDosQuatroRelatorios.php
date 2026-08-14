<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Monta planilhas com o layout dos TRÊS relatórios que entraram na SPEC quatro-relatórios
 * (acordos, receitas e dados cadastrais). A inadimplência continua em {@see MontaPlanilhaDeEspelho}.
 *
 * Os layouts foram extraídos célula a célula dos arquivos reais de `app/var/relatorios/`
 * (gitignored, contêm PII), emissão de 10/08/2026. O que este trait reproduz de propósito, porque é
 * onde os leitores quebram:
 *
 * 1. **Linhas espaçadas de duas em duas** na tabela de contas originais dos acordos;
 * 2. **O valor negativo como TEXTO com U+00A0** entre o sinal e o algarismo (`- 15,00`) — a
 *    armadilha do INV-Q9, que faz o desconto sumir da soma sem erro nenhum;
 * 3. **Uma aba por acordo**, com o `Valor final acordado` no cabeçalho de cada uma.
 */
trait MontaPlanilhasDosQuatroRelatorios
{
    /** @var list<string> */
    private array $planilhasDosQuatro = [];

    /** O separador que a contabilidade usa entre o sinal e o número — NÃO é espaço comum. */
    private const NBSP = "\xC2\xA0";

    protected function limparPlanilhasDosQuatro(): void
    {
        foreach ($this->planilhasDosQuatro as $caminho) {
            if (is_file($caminho)) {
                unlink($caminho);
            }
        }

        $this->planilhasDosQuatro = [];
    }

    /**
     * Uma planilha de acordos detalhados, uma aba por acordo.
     *
     * @param list<array{numero: int, valorFinal: ?string, parcelas: list<array{0: string, 1: string}>, originais?: list<string>}> $acordos
     *        `parcelas` é uma lista de `[rótulo da classe, valor como TEXTO pt-BR]`; passar o valor
     *        como texto é essencial — é assim que a planilha real vem, e é o que expõe o INV-Q9.
     */
    protected function montarPlanilhaDeAcordos(array $acordos, string $situacao = 'Em andamento'): string
    {
        $planilha = new Spreadsheet();
        $planilha->removeSheetByIndex(0);

        foreach ($acordos as $acordo) {
            $aba = $planilha->createSheet();
            $aba->setTitle('Acordo n' . $acordo['numero']);

            $linhas = [
                ['L. G Soluções Contábeis Eireli'],
                ['APLC - TOP LIFE 1'],
                [],
                ['ACORDOS DETALHADOS'],
                [],
                ['Acordo de número ' . $acordo['numero']],
                ['Unidade: 01-01', 'Data base: 23/03/2026'],
                ['Sacado: FULANO DE TAL', 'Valor total das contas originais: 145,00'],
                // `valorFinal === null` monta a aba SEM a declaração — o caso do achado 9, em que a
                // aba não tem contra o que fechar e sumiria do portão se ele só iterasse totalizadores.
                $acordo['valorFinal'] === null
                    ? ['Criado em: 23/03/2026']
                    : ['Criado em: 23/03/2026', 'Valor final acordado: ' . $acordo['valorFinal']],
                ['Situação: ' . $situacao],
                [],
                ['Relação das contas originais'],
                ['Nosso Número', 'Classe de Conta', 'Competência', 'Vencimento', 'Valor original (R$)', 'Detalhamento'],
            ];

            // ⚠️ ESPAÇADAS DE DUAS EM DUAS, como no arquivo real: um leitor que pare na primeira
            // linha em branco lê só a primeira conta e reporta sucesso.
            foreach ($acordo['originais'] ?? ['50,00'] as $i => $valorOriginal) {
                $linhas[] = ['6763' . $i, '1.1 - Taxa de condomínio', '08/2018', '10/08/2018', $valorOriginal, '-'];
                $linhas[] = [];
            }

            $linhas[] = ['Parcelas das contas geradas pelo acordo'];
            $linhas[] = [
                'Nosso Número', 'Classe de Conta', 'Parcela', 'Competência',
                'Vencimento', 'Liquidação', 'Valor acordado (R$)', 'Valor liquidado (R$)',
            ];

            foreach ($acordo['parcelas'] as $i => [$classe, $valor]) {
                $linhas[] = ['6099' . $i, $classe, '1/1', '05/2022', '13/05/2022', '12/05/2022', $valor, $valor];
            }

            $linhas[] = [];
            $linhas[] = ['Filtros: Situação do acordo: ' . $situacao . '; Período de criação do acordo: Todos; Unidade/Cliente: Todos; Sacado: Todos'];
            $linhas[] = [];
            $linhas[] = ['L. G Soluções Contábeis Eireli - Sistema'];
            $linhas[] = ['Emissão: 10/08/2026 10:38'];

            $aba->fromArray($linhas, null, 'A1', true);
        }

        return $this->salvar($planilha, 'acordos');
    }

    /**
     * O valor negativo exatamente como a contabilidade escreve: sinal, **U+00A0**, número.
     *
     * Existe como método para que o teste do INV-Q9 não possa passar por acidente — se alguém
     * escrever `'- 15,00'` com espaço comum no teste, o defeito deixa de ser reproduzido e o teste
     * vira verde sem provar nada.
     */
    protected function negativoComoAContabilidadeEscreve(string $numeroSemSinal): string
    {
        return '-' . self::NBSP . $numeroSemSinal;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $linhasDeDado  `[classe, valor, valorRecebido]`
     * @param array{0: string, 1: string}|null             $totalAdulterado para provar que o portão enxerga
     */
    protected function montarPlanilhaDeReceitas(array $linhasDeDado, ?array $totalAdulterado = null): string
    {
        $somaValor = 0.0;
        $somaRecebido = 0.0;
        $linhas = [
            ['L. G Soluções Contábeis Eireli'],
            ['APLC - TOP LIFE 1'],
            [],
            ['RECEITAS DETALHADAS POR UNIDADE/CLIENTE'],
            [],
            ['APLC - TOP LIFE 1'],
            [
                'Unidade', 'Sacado', 'NN', 'Classe de Conta', 'Competência',
                'Vencimento', 'Recebimento', 'Valor (R$)', 'Valor recebido (R$)', 'Informações do acordo',
            ],
        ];

        foreach ($linhasDeDado as $i => [$classe, $valor, $recebido]) {
            $linhas[] = [
                '01-01', 'FULANO DE TAL', '7567' . $i, $classe, '03/2026',
                '24/03/2026', '25/03/2026', $valor, $recebido, '-',
            ];
            $somaValor += $this->paraFloat($valor);
            $somaRecebido += $this->paraFloat($recebido);
        }

        $linhas[] = [];
        $linhas[] = [
            'Total de receitas',
            $totalAdulterado[0] ?? number_format($somaValor, 2, ',', '.'),
            $totalAdulterado[1] ?? number_format($somaRecebido, 2, ',', '.'),
        ];
        $linhas[] = [];
        $linhas[] = ['Filtros: Situação das contas: Baixadas; Período de vencimento: Todos; Todos; Unidade/Cliente: Todos; Sacado: Todos'];
        $linhas[] = ['L. G Soluções Contábeis Eireli'];
        $linhas[] = ['Emissão: 10/08/2026 10:38'];

        $planilha = new Spreadsheet();
        $planilha->getActiveSheet()->fromArray($linhas, null, 'A1', true);

        return $this->salvar($planilha, 'receitas');
    }

    /**
     * @param list<string> $unidades
     * @param ?int         $totalDeclarado o rótulo `Total de unidades filtradas`, que na vida real
     *                                     NÃO bate com a contagem — ver o portão do leitor de cadastro
     */
    protected function montarPlanilhaDeCadastro(array $unidades, ?int $totalDeclarado = null): string
    {
        $linhas = [
            ['L. G Soluções Contábeis Eireli'],
            ['APLC - TOP LIFE 1'],
            [],
            ['DADOS CADASTRAIS DOS CONDÔMINOS'],
            [],
            ['Total de unidades filtradas: ' . ($totalDeclarado ?? count($unidades))],
            [],
            ['Unidade', 'Nome/Nome fantasia', 'Sacado', 'CPF/CNPJ', 'Fração ideal', 'Endereço', 'E-mail', 'Telefone'],
        ];

        foreach ($unidades as $i => $unidade) {
            $linhas[] = [
                $unidade, 'CONDOMINO ' . $i, 'Proprietário', '000.000.000-0' . $i,
                '-', 'Área Rural - S/N', 'condomino' . $i . '@example.com', '+55 (61) 90000000' . $i,
            ];
        }

        $linhas[] = [];
        $linhas[] = ['Filtros: Unidades: Todas; Sacado: Todos'];
        $linhas[] = [];
        $linhas[] = ['L. G Soluções Contábeis Eireli'];
        $linhas[] = ['Emissão: 10/08/2026 10:38'];

        $planilha = new Spreadsheet();
        $planilha->getActiveSheet()->fromArray($linhas, null, 'A1', true);

        return $this->salvar($planilha, 'cadastro');
    }

    private function paraFloat(string $texto): float
    {
        $limpo = str_replace([self::NBSP, ' '], '', $texto);

        return (float) str_replace(',', '.', str_replace('.', '', $limpo));
    }

    private function salvar(Spreadsheet $planilha, string $prefixo): string
    {
        $caminho = sys_get_temp_dir() . '/espelho_' . $prefixo . '_' . uniqid('', true) . '.xlsx';

        (new Xlsx($planilha))->save($caminho);
        $planilha->disconnectWorksheets();

        $this->planilhasDosQuatro[] = $caminho;

        return $caminho;
    }
}
