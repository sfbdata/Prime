<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\TipoRelatorioContabil;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diz, antes de qualquer número, QUANTO dos quatro relatórios aquele número cobre
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §5).
 *
 * > *"depois dela, rode os três instrumentos de novo e me diga QUANTO DOS QUATRO RELATÓRIOS cada
 * > número cobre. Não quero mais número que parece total e é parcial."* — dono, 13/08/2026.
 *
 * 🔴 **INV-Q7 — nenhum instrumento imprime número de dinheiro sem dizer o que ele cobre.** A frente
 * inteira nasceu de três comandos que reportavam `3.023/3.023` e `3.472/3.575 exatas` sobre **um** dos
 * quatro relatórios, sem que a saída dissesse isso em lugar nenhum. O bloco daqui é o que impede que
 * volte a acontecer — inclusive para os relatórios que ainda não são conferidos.
 *
 * 🔑 **A cobertura é MEDIDA, não declarada em constante.** Ela sai do que existe no banco para aquela
 * carteira. Um tipo que ninguém carregou aparece como ausente, e é exatamente isso que se quer ver.
 *
 * 🔑 **E o denominador não é 4.** O relatório de dados cadastrais não publica valor nenhum (INV-Q2):
 * um número de dinheiro que "cobre o cadastro" não existe. Dizer "1 de 4" sugeriria que falta cobrir
 * algo que não há o que cobrir; dizer "1 de 3 relatórios com dinheiro" é a verdade.
 */
final class CoberturaDoEspelho
{
    /**
     * Os relatórios que publicam valor. O cadastro fica de fora — ver INV-Q2.
     *
     * @var list<TipoRelatorioContabil>
     */
    private const COM_DINHEIRO = [
        TipoRelatorioContabil::Inadimplencia,
        TipoRelatorioContabil::Acordos,
        TipoRelatorioContabil::Receitas,
    ];

    public function __construct(
        private readonly RelatorioImportadoRepository $relatorios,
    ) {
    }

    /**
     * Imprime o bloco de cobertura de uma carteira e devolve o **veredito amarrado a ela**.
     *
     * 🔴 Devolvia um `bool`, e os três chamadores o descartavam — a caixa verde saía sobre cobertura
     * de 1 de 3 em 100% das execuções. Agora devolve {@see VereditoSobCobertura}, que é quem decide se
     * "sucesso" pode sair verde. *"Um bool que precisa ser lembrado vai ser esquecido de novo."*
     *
     * @param list<TipoRelatorioContabil> $tiposQueOInstrumentoLe o que ESTE comando de fato consome
     */
    public function declarar(
        SymfonyStyle $io,
        Carteira $carteira,
        array $tiposQueOInstrumentoLe,
    ): VereditoSobCobertura {
        $io->text(sprintf('<comment>Cobertura desta medição — %s</comment>', $carteira->getNome() ?? '?'));

        $lidos = 0;
        $pendencias = [];

        foreach (TipoRelatorioContabil::cases() as $tipo) {
            $lotes = $this->daUltimaEmissao($this->relatorios->todosDaCarteira($carteira, $tipo));
            $temDinheiro = in_array($tipo, self::COM_DINHEIRO, true);
            $oInstrumentoLe = in_array($tipo, $tiposQueOInstrumentoLe, true);

            if ($temDinheiro) {
                if ($lotes !== [] && $oInstrumentoLe) {
                    ++$lidos;
                } else {
                    // As duas faltas são DIFERENTES e pedem ações diferentes: uma se resolve
                    // carregando o lote, a outra só quando o instrumento aprender a ler o tipo.
                    $pendencias[] = $lotes === []
                        ? sprintf('%s — nenhum lote no espelho; rode app:cobranca:espelho:carregar', $tipo->value)
                        : sprintf('%s — está no espelho, mas este comando ainda não o confere (fatia 0c)', $tipo->value);
                }
            }

            $io->text(sprintf('  %-14s %s', $tipo->value, $this->situacao($lotes, $temDinheiro, $oInstrumentoLe)));
        }

        $comDinheiro = count(self::COM_DINHEIRO);

        $io->text(sprintf(
            '  <comment>Os números abaixo cobrem %d de %d relatório(s) com dinheiro.</comment>',
            $lidos,
            $comDinheiro,
        ));

        if ($lidos < $comDinheiro) {
            $io->text('  <comment>Cobertura PARCIAL: o que não está listado como conferido não foi medido.</comment>');
        }

        $io->newLine();

        return new VereditoSobCobertura($lidos, $comDinheiro, $pendencias);
    }

    /**
     * Os lotes da emissão MAIS RECENTE — nem só o último arquivo, nem o acervo inteiro.
     *
     * 🔑 As duas pontas erradas foram medidas rodando o comando, não lidas no código:
     *
     * - **só o último lote** escondia que os acordos vêm em DOIS arquivos por emissão
     *   (`_EM_ANDAMENTO` e `_LIQUIDADO`): meia carteira exibia a mesma linha de carteira inteira;
     * - **todos os lotes** despejava o acervo: na TOP LIFE I do dev, 8 emissões de inadimplência
     *   numa única linha de 500 caracteres. Cobertura que ninguém lê não cobre nada.
     *
     * A emissão é a chave porque é ela que a contabilidade fecha de uma vez: os dois arquivos de
     * acordo de um mesmo fechamento têm `emitidoEm` idêntico (medido: ambos `10/08/2026 10:38`).
     * Sem `emitidoEm`, cai para `dadosAte`; sem os dois, o lote fica sozinho — nunca agrupado por
     * engano com outro de data desconhecida.
     *
     * ⚠️ **Medido: `dadosAte` é NULL em 12 de 35 lotes** — todos os de acordos, receitas e cadastro,
     * porque esses relatórios não declaram data de corte (só a inadimplência declara). Para eles a
     * ordenação `dadosAte DESC, id DESC` cai no `id`, ou seja, **ordem de inserção**. Hoje não morde
     * (os 12 têm `emitidoEm` idêntico dentro de cada tipo); morde no dia em que uma segunda emissão
     * for carregada fora de ordem cronológica. O conserto durável é ordenar por `emitidoEm` quando
     * `dadosAte` for nulo — fica para quando houver mais de uma emissão desses tipos no espelho.
     *
     * @param list<\App\Cobranca\Entity\RelatorioImportado> $lotes já ordenados do mais novo ao mais velho
     *
     * @return list<\App\Cobranca\Entity\RelatorioImportado>
     */
    private function daUltimaEmissao(array $lotes): array
    {
        if ($lotes === []) {
            return [];
        }

        $referencia = $this->chaveDaEmissao($lotes[0]);

        if ($referencia === null) {
            return [$lotes[0]];
        }

        return array_values(array_filter(
            $lotes,
            fn (object $lote): bool => $this->chaveDaEmissao($lote) === $referencia,
        ));
    }

    private function chaveDaEmissao(object $lote): ?string
    {
        /** @var \App\Cobranca\Entity\RelatorioImportado $lote */
        return $lote->getEmitidoEm()?->format('Y-m-d H:i')
            ?? $lote->getDadosAte()?->format('Y-m-d');
    }

    /**
     * 🔑 Recebe os lotes da emissão, não um só — e isso é correção de um furo real.
     *
     * Os acordos vêm em **dois arquivos por carteira** (`_EM_ANDAMENTO` e `_LIQUIDADO`), do mesmo
     * tipo. Medido na TOP LIFE I: 65 abas num, 260 no outro. Reportando só o último lote, uma
     * carteira com **metade** dos acordos carregados exibia uma linha de cobertura idêntica à de
     * cobertura completa — número parcial com cara de total, dentro do instrumento criado para acabar
     * com isso.
     *
     * @param list<\App\Cobranca\Entity\RelatorioImportado> $lotes
     */
    private function situacao(array $lotes, bool $temDinheiro, bool $oInstrumentoLe): string
    {
        if (!$temDinheiro) {
            // Não é falta: é ausência de pergunta. Ver INV-Q2.
            return $lotes === []
                ? '⚠ ausente do espelho · sem valor a conferir (INV-Q2)'
                : sprintf('%s · sem valor a conferir (INV-Q2)', $this->identificar($lotes));
        }

        if ($lotes === []) {
            return '🔴 AUSENTE do espelho — nada deste relatório entrou nos números abaixo';
        }

        return $oInstrumentoLe
            ? sprintf('%s · ✓ lido e conferido', $this->identificar($lotes))
            : sprintf('%s · ⚠ carregado, mas NÃO conferido por este comando', $this->identificar($lotes));
    }

    /**
     * @param list<\App\Cobranca\Entity\RelatorioImportado> $lotes
     */
    private function identificar(array $lotes): string
    {
        $descricoes = [];

        foreach ($lotes as $lote) {
            $ate = $lote->getDadosAte()?->format('d/m/Y');

            $descricoes[] = sprintf(
                'lote %d · %s%s',
                $lote->getId() ?? 0,
                $lote->getEmitidoEm()?->format('d/m/Y H:i') ?? 'emissão desconhecida',
                $ate === null ? '' : sprintf(' · dados até %s', $ate),
            );
        }

        // O plural é informação: "2 arquivos" é o que distingue os acordos carregados por inteiro dos
        // carregados pela metade.
        return count($descricoes) === 1
            ? $descricoes[0]
            : sprintf('%d arquivos [%s]', count($descricoes), implode(' · ', $descricoes));
    }
}
