<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\TipoRelatorioContabil;

/**
 * Diz qual dos quatro relatórios da contabilidade um arquivo é, pelo NOME
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §4.2).
 *
 * 🔴 INV-Q6: **arquivo não classificado é ERRO, nunca silêncio.** Esta classe substitui um filtro que
 * dizia `stripos($nome, 'nadimpl') !== false` e **descartava calado** tudo que não casasse. Foi assim
 * que três dos quatro relatórios ficaram semanas fora do espelho enquanto os comandos imprimiam
 * números com cara de totais. Por isso `classificar()` devolve `null` em vez de escolher um padrão, e
 * quem chama é obrigado a tratar o `null` como recusa — nunca como "pula esse".
 *
 * A classificação é pelo nome, e isso é uma escolha: o conteúdo só diria o tipo depois de abrir o
 * arquivo com o leitor certo, que é justamente o que se está tentando decidir. O nome é o que a
 * emissão da contábil produz de forma estável — ver os padrões medidos abaixo.
 */
final class ClassificadorDeRelatorio
{
    /**
     * Fragmento distintivo de cada tipo, em texto já normalizado (minúsculo, sem acento, separadores
     * virando espaço). Medidos nos arquivos reais de `app/var/relatorios/` e nas emissões antigas de
     * `docs/gestao-cobrancas/`, que vêm com acento e com sufixos de download (` (1) (1)`).
     *
     * ⚠️ **Não use `detalhadas` para nada**: `Inadimplencias_detalhadas`, `Receitas_detalhadas` e
     * `Acordos_detalhados` compartilham essa palavra. O discriminador é sempre o substantivo do
     * relatório, e é por isso que cada fragmento abaixo é ele.
     *
     * @var array<string, TipoRelatorioContabil>
     */
    private const PADROES = [
        'inadimpl' => TipoRelatorioContabil::Inadimplencia,
        'acordos' => TipoRelatorioContabil::Acordos,
        'receitas' => TipoRelatorioContabil::Receitas,
        'cadastrais' => TipoRelatorioContabil::Cadastro,
    ];

    /**
     * O tipo do arquivo, ou `null` se o nome não corresponder a nenhum dos quatro.
     *
     * ⚠️ Devolve `null` também quando o nome casa com MAIS DE UM padrão. Um arquivo chamado
     * `Acordos_e_receitas.xlsx` não é os dois nem é "o primeiro que casou": é ambíguo, e adivinhar
     * seria escolher em silêncio — a falha que o INV-Q6 existe para impedir.
     */
    public function classificar(string $nomeDoArquivo): ?TipoRelatorioContabil
    {
        if (!$this->ehPlanilha($nomeDoArquivo)) {
            return null;
        }

        $normalizado = $this->normalizar($nomeDoArquivo);
        $casados = [];

        foreach (self::PADROES as $fragmento => $tipo) {
            if (str_contains($normalizado, $fragmento)) {
                $casados[$tipo->value] = $tipo;
            }
        }

        return count($casados) === 1 ? array_values($casados)[0] : null;
    }

    /**
     * Por que um arquivo foi recusado, em frase que diga o que fazer.
     *
     * Existe separado de `classificar()` porque a mensagem é para o operador que rodou o comando em
     * produção e não tem como abrir o código para descobrir o que o `null` significou.
     */
    public function motivoDaRecusa(string $nomeDoArquivo): string
    {
        if (!$this->ehPlanilha($nomeDoArquivo)) {
            return 'não é .xlsx';
        }

        $normalizado = $this->normalizar($nomeDoArquivo);
        $casados = [];

        foreach (self::PADROES as $fragmento => $tipo) {
            if (str_contains($normalizado, $fragmento)) {
                $casados[$tipo->value] = $tipo->value;
            }
        }

        if (count($casados) > 1) {
            return sprintf(
                'o nome casa com mais de um relatório (%s) — renomeie para deixar claro qual é',
                implode(', ', array_values($casados)),
            );
        }

        return 'o nome não identifica nenhum dos quatro relatórios '
            . '(inadimplência, acordos, receitas, dados cadastrais)';
    }

    private function ehPlanilha(string $nomeDoArquivo): bool
    {
        return str_ends_with(mb_strtolower($nomeDoArquivo), '.xlsx');
    }

    /**
     * Minúsculo, sem acento, e todo separador (`_`, `-`, `.`, espaço) virando espaço único.
     *
     * O acento importa: a mesma emissão sai como `Inadimplencias_detalhadas.xlsx` pelo script de
     * download e como `TOPLIFE I_Inadimplências_detalhadas_2026_07_08.xlsx` quando alguém baixa pelo
     * navegador. Os dois têm de classificar igual.
     */
    private function normalizar(string $texto): string
    {
        $minusculo = mb_strtolower(trim($texto));

        $semAcento = strtr($minusculo, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);

        return (string) preg_replace('/[^a-z0-9]+/', ' ', $semAcento);
    }
}
