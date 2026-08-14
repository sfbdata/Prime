<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * O veredito de um instrumento do espelho, **amarrado à cobertura que ele mediu**
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §5.1).
 *
 * 🔴 **Existe porque o mesmo defeito apareceu TRÊS vezes nesta frente:** o instrumento calcula que
 * cobriu só uma parte, imprime isso, e três linhas abaixo estampa uma caixa verde. Aconteceu na peça
 * 4 (veredito cego ao balde, caixa verde sobre 0,4% de cobertura), aconteceu de novo no código do
 * `espelho:encargos`, e aconteceu pela terceira vez na primeira versão desta fatia — em que
 * `CoberturaDoEspelho::declarar()` devolvia um `bool` que os três chamadores descartavam.
 *
 * **A lição, na palavra do dono:** *"um bool que precisa ser lembrado vai ser esquecido de novo"*.
 *
 * Por isso o sucesso não é uma decisão do chamador: **é uma decisão deste objeto**. Quem tem o
 * veredito não consegue afirmar "está tudo certo" quando a cobertura é parcial, porque
 * {@see self::sucesso()} recusa — não avisa, recusa, e imprime o motivo no lugar.
 *
 * ⚠️ A trava tem um segundo braço, e ele é necessário: nada impede um chamador de ignorar este objeto
 * e chamar `$io->success()` na mão. É o que o `VeredictoNaoEscapaDaCoberturaTest` proíbe, lendo o
 * código-fonte dos comandos. Construção + teste de arquitetura; nenhum dos dois sozinho basta.
 */
final readonly class VereditoSobCobertura
{
    /**
     * @param int          $relatoriosCobertos  quantos relatórios COM DINHEIRO este instrumento leu de fato
     * @param int          $relatoriosComDinheiro quantos existem — o denominador honesto (o cadastro fica fora)
     * @param list<string> $pendencias          o que falta, em frase, um item por relatório descoberto
     */
    public function __construct(
        public int $relatoriosCobertos,
        public int $relatoriosComDinheiro,
        public array $pendencias = [],
    ) {
    }

    public function completa(): bool
    {
        return $this->relatoriosCobertos >= $this->relatoriosComDinheiro;
    }

    /**
     * Afirma sucesso — **e só imprime verde se a cobertura permitir**.
     *
     * Sob cobertura parcial a mesma frase sai como aviso, com a ressalva e **a lista do que falta**
     * colada nela. A frase não é apagada de propósito: o que o instrumento mediu continua verdadeiro
     * *dentro do recorte que ele cobre*, e esconder isso trocaria um exagero por outro. O que não pode
     * é a frase sair sozinha, com cara de conclusão sobre o todo.
     *
     * 🎯 **O aviso é barra de progresso, não relato de falha** (decisão do dono, 13/08):
     *
     * > *"o dia em que o verde voltar é o dia em que o sistema está batendo com a contabilidade de
     * > verdade"*.
     *
     * Por isso ele diz **o que falta cobrir**, item a item, e diz em voz alta que não é defeito. Um
     * aviso que só informa que é parcial, sem dizer parcial em quê, é lido como problema — e alguém
     * vai "consertar" o que está certo.
     */
    public function sucesso(SymfonyStyle $io, string $frase): void
    {
        if ($this->completa()) {
            $io->success($frase);

            return;
        }

        $linhas = [
            $frase,
            '',
            sprintf(
                'Isto vale para %d de %d relatório(s) com dinheiro — o que não foi lido não foi medido, '
                . 'e portanto não está sendo afirmado aqui.',
                $this->relatoriosCobertos,
                $this->relatoriosComDinheiro,
            ),
        ];

        if ($this->pendencias !== []) {
            $linhas[] = '';
            $linhas[] = 'Falta cobrir:';

            foreach ($this->pendencias as $pendencia) {
                $linhas[] = '  · ' . $pendencia;
            }
        }

        $linhas[] = '';
        $linhas[] = 'ISTO NÃO É FALHA: é o quanto da contabilidade o sistema já consegue conferir. '
            . 'Quando esta caixa voltar a sair VERDE, é porque os três relatórios com dinheiro estão '
            . 'sendo conferidos — que é a meta.';

        $io->warning(implode("\n", $linhas));
    }
}
