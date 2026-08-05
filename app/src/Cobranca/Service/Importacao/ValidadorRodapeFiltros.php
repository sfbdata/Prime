<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Confere se a linha `Filtros:` do rodapé bate com o recorte que a importação exige — spec
 * `docs/specs/cobranca-validador-rodape-filtros.md`.
 *
 * **Por que este serviço existe:** um arquivo com recorte errado não dá erro nenhum. Ele importa
 * lindamente e o número fica menor que a realidade — o relatório de Acordos saía com um filtro que
 * escondia a maior parte dos acordos e ninguém viu por meses, porque o único lugar do arquivo que
 * registra o recorte usado é este rodapé, e ninguém lê rodapé. Aqui a falha silenciosa vira erro
 * barulhento no segundo zero.
 *
 * Vale igual com download manual: não depende da automação (handoff §7.1), e foi contra um arquivo
 * baixado à mão (03/08) que a regra se provou.
 *
 * Cinco armadilhas do formato REAL que o parser tem de absorver (§2.2 da spec, medidas em 13 arquivos):
 * dois espaços após `Filtros:` na inadimplência · `Inadimplência até:04/08/2026` sem espaço depois dos
 * dois-pontos · o `Período de recebimento` que perde o rótulo e vira `Todos` órfão quando não há
 * janela · a lista de campos que varia entre emissões · o plural (`Baixadas`) que não é o enum
 * enviado (`BAIXADA`).
 */
final class ValidadorRodapeFiltros
{
    private const PREFIXO = 'Filtros:';

    /** Onde procurar o rodapé. Ele fica no fim; varrer a planilha inteira seria caro à toa. */
    private const COLUNA_RODAPE = 'A';

    /**
     * Lê a primeira aba do arquivo e confere o rodapé.
     *
     * Leitura MAGRA de verdade (§3.3): primeira aba, `readDataOnly` **e um `IReadFilter` que descarta
     * toda coluna que não seja a A**. Sem o filtro, `setReadDataOnly` + `setLoadSheetsOnly` ainda
     * carregam a grade inteira: medido na Receitas completa de TL1 (916 KB), eram **21.150 linhas ×
     * colunas A–J** carregadas para ler UMA célula, e o tempo do importe dobrava (4,1 s → 7,4 s).
     * Achado da 1ª revisão — o docblock afirmava "só a coluna A" e a restrição só existia na busca,
     * depois da planilha inteira estar em memória.
     *
     * No Acordos o rodapé se repete idêntico em todas as abas (medido em 8, 26 e 8), então a primeira
     * basta.
     *
     * Arquivo ilegível (zip quebrado, `.xlsx` truncado, arquivo renomeado) **não estoura**: vira
     * recusa com motivo. É o cenário-mãe desta frente — download interrompido —, e deixar a exceção
     * subir trocaria a mensagem útil por um stack trace (regressão apontada na 1ª revisão).
     */
    public function validar(string $caminhoArquivo, RecorteEsperado $esperado): ResultadoRodape
    {
        try {
            $linha = $this->extrairLinha($caminhoArquivo);
        } catch (\Exception $e) {
            // `\Exception`, NÃO `\Throwable` (achado da 2ª revisão): um `\Error` — `TypeError` no
            // filtro, assinatura mudada num upgrade do PhpSpreadsheet, bug em `extrairLinha()` —
            // viraria "confira se o download completou" em TODO arquivo, jogando no fornecedor a culpa
            // de um defeito nosso e apagando o rastro. Erro de programação tem de subir e aparecer.
            return new ResultadoRodape(
                false,
                [sprintf(
                    'Não foi possível abrir o arquivo (confira se é a planilha "%s" e se o download completou).',
                    $esperado->fonte,
                )],
                arquivoIlegivel: true,
            );
        }

        return $this->validarTexto($linha, $esperado);
    }

    /**
     * Confere um texto de rodapé já extraído. É o caminho testável (sem `.xlsx`) e o que `validar()`
     * usa por dentro — os dois passam pelas MESMAS regras, para o teste não provar um código que a
     * produção não executa.
     *
     * `null` = a linha `Filtros:` não foi encontrada. Isso é RECUSA, nunca "passa por omissão": sem
     * rodapé não há recorte para conferir, e tratar ausência como sucesso reabriria em silêncio
     * exatamente a porta que este serviço fecha.
     */
    public function validarTexto(?string $linha, RecorteEsperado $esperado): ResultadoRodape
    {
        if ($linha === null) {
            return new ResultadoRodape(false, [sprintf(
                'O arquivo não tem a linha "%s" no rodapé — sem ela não há como conferir o recorte de %s.',
                self::PREFIXO,
                $esperado->fonte,
            )]);
        }

        [$campos, $orfaos, $ambiguas] = $this->separarCampos($linha);

        $motivos = [];
        foreach ($esperado->expectativas as $expectativa) {
            if (in_array($expectativa['chave'], $ambiguas, true)) {
                $motivos[] = sprintf(
                    'O campo "%s" aparece mais de uma vez no rodapé, com valores diferentes — recorte ambíguo.',
                    $expectativa['chave'],
                );

                continue;
            }

            $motivo = $this->conferir($expectativa, $campos, $orfaos);
            if ($motivo !== null) {
                $motivos[] = $motivo;
            }
        }

        return new ResultadoRodape($motivos === [], $motivos, $linha);
    }

    /**
     * @param array{tipo: string, chave: string, valores: list<string>} $expectativa
     * @param array<string, string>                                    $campos
     * @param list<string>                                             $orfaos
     */
    private function conferir(array $expectativa, array $campos, array $orfaos): ?string
    {
        $chave = $expectativa['chave'];
        $valores = $expectativa['valores'];
        $presente = array_key_exists($chave, $campos);

        // O único campo cuja AUSÊNCIA é uma forma de estar certo: o rodapé omite o rótulo do "Período
        // de recebimento" quando não há janela, e deixa um `Todos` solto no lugar (§2.2.2). Sem o
        // órfão, porém, a ausência não vira aprovação — seria adivinhar.
        if ($expectativa['tipo'] === RecorteEsperado::TIPO_TODOS_OU_ORFAO && !$presente) {
            foreach ($orfaos as $orfao) {
                if (in_array($orfao, $valores, true)) {
                    return null;
                }
            }

            return sprintf(
                'O campo "%s" não aparece no rodapé e também não há um "%s" solto no lugar dele — recorte desconhecido.',
                $chave,
                implode('"/"', $valores),
            );
        }

        if (!$presente) {
            return sprintf('O rodapé não traz o campo "%s", que precisa valer "%s".', $chave, implode('" ou "', $valores));
        }

        // Comparação EXATA (`in_array` estrito), nunca `str_contains`: "Baixadas" contém "Baixada", e
        // é assim que o recorte errado passaria despercebido (§2.2.4).
        if (in_array($campos[$chave], $valores, true)) {
            return null;
        }

        return sprintf(
            'O campo "%s" veio como "%s" e precisa ser "%s".',
            $chave,
            $campos[$chave],
            implode('" ou "', $valores),
        );
    }

    /**
     * Quebra a linha em `chave => valor`, devolvendo à parte os pedaços SEM `:` (os órfãos).
     *
     * O `trim` depois de tirar o prefixo absorve os dois espaços da inadimplência; o corte no PRIMEIRO
     * `:` com `trim` dos dois lados absorve o `Inadimplência até:04/08/2026`, que vem colado — e
     * preserva `01/01/2026 a 04/08/2026` inteiro como valor, que é o que interessa.
     *
     * @return array{0: array<string, string>, 1: list<string>, 2: list<string>}
     */
    private function separarCampos(string $linha): array
    {
        $conteudo = trim($linha);
        if (str_starts_with($conteudo, self::PREFIXO)) {
            $conteudo = trim(substr($conteudo, strlen(self::PREFIXO)));
        }

        $campos = [];
        $orfaos = [];
        $ambiguas = [];
        foreach (explode(';', $conteudo) as $pedaco) {
            $pedaco = trim($pedaco);
            if ($pedaco === '') {
                continue; // a Receitas termina com `;`
            }

            $posicao = strpos($pedaco, ':');
            if ($posicao === false) {
                $orfaos[] = $pedaco;

                continue;
            }

            $chave = trim(substr($pedaco, 0, $posicao));
            $valor = trim(substr($pedaco, $posicao + 1));
            // Chave repetida com valores DIFERENTES é recorte ambíguo, e ambiguidade cai para o lado
            // RÍGIDO: guarda o valor divergente para a conferência recusar. Deixar a primeira vencer
            // (ou a última, que é o natural do PHP) seria escolher em silêncio qual das duas o
            // operador quis — o resto deste arquivo se recusa a adivinhar, e aqui não pode ser
            // diferente (achado da 2ª revisão).
            if (array_key_exists($chave, $campos) && $campos[$chave] !== $valor) {
                $ambiguas[$chave] = true;
            }
            $campos[$chave] ??= $valor;
        }

        return [$campos, $orfaos, array_keys($ambiguas)];
    }

    /** A primeira linha da coluna A que começa com `Filtros:`, ou null se não houver nenhuma. */
    private function extrairLinha(string $caminhoArquivo): ?string
    {
        $reader = IOFactory::createReaderForFile($caminhoArquivo);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class(self::COLUNA_RODAPE) implements IReadFilter {
            public function __construct(private readonly string $coluna)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $columnAddress === $this->coluna;
            }
        });

        $primeiraAba = $reader->listWorksheetNames($caminhoArquivo)[0] ?? null;
        if ($primeiraAba === null) {
            return null;
        }
        $reader->setLoadSheetsOnly([$primeiraAba]);

        $planilha = $reader->load($caminhoArquivo);
        $aba = $planilha->getActiveSheet();
        $ultima = $aba->getHighestDataRow();

        // De BAIXO para cima: o rodapé mora no fim. O comentário desta classe já dizia isso, mas o
        // laço subia de 1 até o fim e instanciava ~21 mil células numa Receitas completa antes de
        // chegar nele — doc que descreve uma otimização que o código não faz (achado da 2ª revisão).
        $encontrada = null;
        for ($i = $ultima; $i >= 1; $i--) {
            $valor = trim((string) $aba->getCell(self::COLUNA_RODAPE . $i)->getValue());
            if (str_starts_with($valor, self::PREFIXO)) {
                $encontrada = $valor;

                break;
            }
        }

        $planilha->disconnectWorksheets();

        return $encontrada;
    }
}
