<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDaFolha;

use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\IntervalosDaFolha;
use App\Ponto\DTO\ResumoDoMesDaFolha;

/**
 * Os três quadros lado a lado abaixo da tabela (desenho 16 L686 a L695), com 116 de altura: "RESUMO DO MÊS" (296),
 * "CONTROLE DE INTERVALOS" (300) e "OBSERVAÇÕES GERAIS" (o resto), com 8 entre eles.
 *
 * Os mínimos dos rótulos da intrajornada e da interjornada são os da jornada do escritório (o desenho escreve 1h00 e
 * 11h00, os mínimos de quem não tem jornada). A linha da contagem de feriados encolhe para caber no quadro.
 */
final class QuadrosDaFolha
{
    public const ALTURA = 116;
    private const LARGURA_DO_RESUMO = 296;
    private const LARGURA_DOS_INTERVALOS = 300;
    private const VERDE = '#1e7b45';
    private const VERMELHO = '#a3232b';
    private const CINZA = '#4a6373';

    public function __construct(
        private readonly PaginaDaFolha $pagina,
    ) {
    }

    public function desenhar(ConteudoDaFolha $conteudo, float $y): void
    {
        $x2 = ImagemDaFolha::X + self::LARGURA_DO_RESUMO + 8;
        $x3 = $x2 + self::LARGURA_DOS_INTERVALOS + 8;
        $this->resumo($conteudo->resumo, $y);
        $this->intervalos($conteudo->intervalos, $x2, $y);
        $this->observacoes($x3, ImagemDaFolha::LARGURA_UTIL - self::LARGURA_DO_RESUMO - self::LARGURA_DOS_INTERVALOS - 16, $y);
    }

    /** Seis linhas de rótulo e valor a cada 15 (L687 e L688). */
    private function resumo(ResumoDoMesDaFolha $resumo, float $y): void
    {
        $this->moldura('RESUMO DO MÊS', ImagemDaFolha::X, $y, self::LARGURA_DO_RESUMO);
        $linhas = [
            ['Horas Trabalhadas no Mês:', $resumo->trabalhadas],
            ['Total de Horas Extras no Mês:', $resumo->extras],
            ['Saldo do Banco de Horas Anterior:', $resumo->saldoAnterior],
            ['Saldo do Banco de Horas Atual:', $resumo->saldoAtual],
            ['Horas a Compensar:', $resumo->horasACompensar],
            ['Dias incompletos · a revisar:', $resumo->textoDosIncompletos()],
        ];
        foreach ($linhas as $i => [$rotulo, $valor]) {
            $this->pagina->rotulo($rotulo, $valor, ImagemDaFolha::X + 8, $y + 36 + $i * 15, self::LARGURA_DO_RESUMO - 16);
        }
    }

    /** Intrajornada, interjornada e descanso semanal, "Conforme" em verde ou "Não conforme" em vermelho (L689 a L693). */
    private function intervalos(IntervalosDaFolha $intervalos, float $x, float $y): void
    {
        $this->moldura('CONTROLE DE INTERVALOS', $x, $y, self::LARGURA_DOS_INTERVALOS);
        $intra = sprintf('Intrajornada (mín. %s):', IntervalosDaFolha::duracao($intervalos->minimoIntrajornada));
        $inter = sprintf('Interjornada (mín. %s):', IntervalosDaFolha::duracao($intervalos->minimoInterjornada));
        $this->conformidade($intra, $intervalos->intrajornadaConforme(), $x + 8, $y + 35, IntervalosDaFolha::textoDosDias($intervalos->diasIntrajornada));
        $this->conformidade($inter, $intervalos->interjornadaConforme(), $x + 8, $y + 62, IntervalosDaFolha::textoDosDias($intervalos->diasInterjornada));
        $this->conformidade('Descanso Semanal Remunerado:', $intervalos->descansoSemanalConforme(), $x + 8, $y + 89, '');
        $this->pagina->linhaQueCabe($intervalos->textoDaContagem(), 'normal', 10.5, 8, $x + 8, $y + 106, self::LARGURA_DOS_INTERVALOS - 16);
    }

    /** O `conf` do desenho (L689): rótulo em 11, a situação em negrito e os dias em 9,5 logo abaixo. */
    private function conformidade(string $rotulo, bool $conforme, float $x, float $y, string $dias): void
    {
        $largura = $this->pagina->largura($rotulo . ' ', 'normal', 11);
        $this->pagina->texto($rotulo, 'normal', 11, $x, $y);
        $this->pagina->texto($conforme ? 'Conforme' : 'Não conforme', 'negrito', 11, $x + $largura, $y, $conforme ? self::VERDE : self::VERMELHO);
        $this->pagina->texto($this->pagina->caber($dias, 'normal', 9.5, self::LARGURA_DOS_INTERVALOS - 16), 'normal', 9.5, $x, $y + 12, self::CINZA);
    }

    /**
     * As três frases (P36) com marcador, quebradas por palavra na largura do quadro: 12,6 entre as linhas de uma frase e
     * 13,6 entre as frases (L695).
     */
    private function observacoes(float $x, float $largura, float $y): void
    {
        $this->moldura('OBSERVAÇÕES GERAIS', $x, $y, $largura);
        $linhaDeBase = $y + 34;
        foreach (ConteudoDaFolha::OBSERVACOES_GERAIS as $frase) {
            $linha = '• ';
            foreach (explode(' ', $frase) as $palavra) {
                if ($this->pagina->largura($linha . $palavra, 'normal', 10) > $largura - 14) {
                    $this->pagina->texto($linha, 'normal', 10, $x + 7, $linhaDeBase);
                    $linhaDeBase += 12.6;
                    $linha = '  ' . $palavra . ' ';
                    continue;
                }
                $linha .= $palavra . ' ';
            }
            $this->pagina->texto($linha, 'normal', 10, $x + 7, $linhaDeBase);
            $linhaDeBase += 13.6;
        }
    }

    /** A faixa de título e a caixa de baixo de cada quadro. */
    private function moldura(string $titulo, float $x, float $y, float $largura): void
    {
        $this->pagina->cabeca($titulo, $x, $y, $largura, true);
        $this->pagina->quadro($x, $y + 20, $largura, self::ALTURA - 20);
    }
}
