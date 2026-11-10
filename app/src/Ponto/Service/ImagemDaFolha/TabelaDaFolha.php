<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDaFolha;

use App\Ponto\DTO\LinhaDaFolhaImpressa;
use App\Ponto\Service\LinhasDaFolhaImpressa;

/**
 * A tabela do mês na folha em PDF (desenho 16 L667 a L686): colunas de [34, 76, 66, 66, 66, 66, 72, 62, 66, 316] a partir
 * de y = 308, cabeçalho de 36 com "Horários Registrados" sobre as quatro batidas, uma linha de 19,6 por dia com listra
 * nas pares e os fios verticais de cima a baixo.
 *
 * O texto de cada célula já vem pronto em {@see LinhaDaFolhaImpressa}: a batida que falta e as horas do dia futuro vêm
 * vazias e a célula fica vazia (spec ponto-desenho-0811, seção 8; o desenho põe um traço). As cores são as do desenho:
 * fora de sequência em `#a3232b` (batidas e observação), registro incompleto em `#7a5200` na observação, saldo negativo
 * em `#a3232b` e "FERIADO" em vermelho negrito no feriado sem batida. A observação que não cabe na coluna encolhe e,
 * se preciso, corta primeiro os ajustes (decisão 27; {@see self::observacaoQueCabe()}).
 */
final class TabelaDaFolha
{
    public const TOPO = 308;
    private const COLUNAS = [34, 76, 66, 66, 66, 66, 72, 62, 66, 316];
    private const ALTURA_DO_CABECALHO = 36;
    private const ALTURA_DA_LINHA = 19.6;
    private const VERMELHO = '#a3232b';
    private const OCRE = '#7a5200';
    private const OBSERVACAO = '#243845';
    private const TAMANHO_DO_TITULO = 10.5;
    /** A observação começa em 10 e encolhe até 8, o mínimo da decisão 26 (como no pé e nos quadros da folha). */
    private const OBSERVACAO_MAIOR = 10;
    private const OBSERVACAO_MENOR = 8;
    /** A marca do corte, a mesma do {@see PaginaDaFolha::caber()}. */
    private const RETICENCIAS = '...';

    /** @var list<float> o x de cada coluna e o do fim da última */
    private readonly array $xs;

    public function __construct(
        private readonly PaginaDaFolha $pagina,
    ) {
        $xs = [(float) ImagemDaFolha::X];
        foreach (self::COLUNAS as $i => $largura) {
            $xs[] = $xs[$i] + $largura;
        }
        $this->xs = $xs;
    }

    /**
     * @param list<LinhaDaFolhaImpressa> $linhas
     *
     * @return float o y do fim da tabela
     */
    public function desenhar(array $linhas): float
    {
        $this->cabecalho();
        $y = self::TOPO + self::ALTURA_DO_CABECALHO;
        foreach ($linhas as $i => $linha) {
            $this->linha($linha, $y, $i % 2 === 0);
            $y += self::ALTURA_DA_LINHA;
        }
        foreach ($this->xs as $x) {
            $this->pagina->fio($x - .3, self::TOPO + self::ALTURA_DO_CABECALHO, .6, $y - self::TOPO - self::ALTURA_DO_CABECALHO);
        }

        return $y;
    }

    /** A faixa de 36 com os títulos das colunas em negrito 10,5 e os das batidas em 10 (L668 a L672). */
    private function cabecalho(): void
    {
        $y = self::TOPO;
        $this->pagina->faixaClara(ImagemDaFolha::X, $y, ImagemDaFolha::LARGURA_UTIL, self::ALTURA_DO_CABECALHO);
        $this->pagina->quadro(ImagemDaFolha::X, $y, ImagemDaFolha::LARGURA_UTIL, self::ALTURA_DO_CABECALHO);
        $this->tituloDaColuna('Dia', 0, $y + 22);
        foreach ([['Dia da', 1, 'Semana'], ['Horas', 6, 'Trabalhadas'], ['Horas', 7, 'Extras'], ['Saldo do', 8, 'Mês']] as [$cima, $coluna, $baixo]) {
            $this->tituloDaColuna($cima, $coluna, $y + 15);
            $this->tituloDaColuna($baixo, $coluna, $y + 28);
        }
        $meioDasBatidas = ($this->xs[2] + $this->xs[6]) / 2;
        $this->pagina->texto('Horários Registrados', 'negrito', self::TAMANHO_DO_TITULO, $meioDasBatidas, $y + 13, PaginaDaFolha::AZUL_ESCURO, true);
        $this->pagina->fio($this->xs[2], $y + 18, $this->xs[6] - $this->xs[2], .7);
        foreach (['Entrada', 'Início Int.', 'Fim Int.', 'Saída'] as $j => $titulo) {
            $this->pagina->texto($titulo, 'normal', 10, $this->centro($j + 2), $y + 31, PaginaDaFolha::AZUL_ESCURO, true);
        }
        $this->pagina->texto('Justificativa / Observações', 'negrito', self::TAMANHO_DO_TITULO, $this->xs[9] + 6, $y + 22, PaginaDaFolha::AZUL_ESCURO);
    }

    private function tituloDaColuna(string $titulo, int $coluna, float $y): void
    {
        $this->pagina->texto($titulo, 'negrito', self::TAMANHO_DO_TITULO, $this->centro($coluna), $y, PaginaDaFolha::AZUL_ESCURO, true);
    }

    /** Um dia: listra nas pares, as células com a linha de base em y + 13,8 e o fio de baixo (L674 a L683). */
    private function linha(LinhaDaFolhaImpressa $linha, float $y, bool $listrada): void
    {
        if ($listrada) {
            $this->pagina->retangulo(ImagemDaFolha::X, $y, ImagemDaFolha::LARGURA_UTIL, self::ALTURA_DA_LINHA, PaginaDaFolha::AZUL_ESCURO, .085);
        }
        $base = $y + 13.8;
        $this->celula($linha->dia, 0, $base);
        $this->batidas($linha, $base);
        $this->celula($linha->trabalhadas, 6, $base);
        $this->celula($linha->extras, 7, $base);
        $this->celula($linha->saldo, 8, $base, $linha->saldoNegativo ? self::VERMELHO : PaginaDaFolha::TEXTO);
        $this->observacao($linha, $base);
        $this->pagina->fio(ImagemDaFolha::X, $y + self::ALTURA_DA_LINHA, ImagemDaFolha::LARGURA_UTIL, .5);
    }

    /** A semana e as quatro batidas; no feriado sem batida, "FERIADO" na semana e no meio das batidas (L677 a L681). */
    private function batidas(LinhaDaFolhaImpressa $linha, float $base): void
    {
        if ($linha->feriadoSemBatida) {
            $this->pagina->texto($linha->semana, 'negrito', 11, $this->centro(1), $base, self::VERMELHO, true);
            $this->pagina->texto(LinhasDaFolhaImpressa::FERIADO_SEM_BATIDA, 'negrito', 11, ($this->xs[2] + $this->xs[6]) / 2, $base, self::VERMELHO, true);

            return;
        }
        $this->celula($linha->semana, 1, $base);
        foreach ($linha->batidas as $j => $batida) {
            $this->celula($batida, $j + 2, $base, $linha->foraDeSequencia ? self::VERMELHO : PaginaDaFolha::TEXTO);
        }
    }

    /** As observações do dia numa linha só, na coluna (L683; decisão 27). */
    private function observacao(LinhaDaFolhaImpressa $linha, float $base): void
    {
        $cor = $linha->foraDeSequencia ? self::VERMELHO : ($linha->incompleto ? self::OCRE : self::OBSERVACAO);
        [$texto, $tamanho] = $this->observacaoQueCabe($linha, self::COLUNAS[9] - 12);
        $this->pagina->texto($texto, 'normal', $tamanho, $this->xs[9] + 6, $base, $cor);
    }

    /**
     * A observação que cabe na coluna (decisão 27; P35 a, P34; desenho L622 e L673): a fonte encolhe de 10 até 8, um
     * ponto por vez, até o texto inteiro caber. Se nem em 8 couber, só os ajustes ("<Batida> ajustada em ...", que vêm
     * primeiro) se cortam com "...": o feriado, a pendência e as justificativas ficam inteiros, na ordem da L622. Se nem
     * eles sozinhos couberem depois do "...", corta o texto todo, como antes. A linha não cresce (página única, altura
     * de linha fixa): nunca quebra.
     *
     * @return array{string, int} o texto e o tamanho da fonte
     */
    private function observacaoQueCabe(LinhaDaFolhaImpressa $linha, float $maximo): array
    {
        for ($tamanho = self::OBSERVACAO_MAIOR; $tamanho >= self::OBSERVACAO_MENOR; --$tamanho) {
            if ($this->pagina->largura($linha->observacao, 'normal', $tamanho) <= $maximo) {
                return [$linha->observacao, $tamanho];
            }
        }
        $resto = self::depoisDosAjustes($linha);
        $cortada = $resto === null ? null : $this->ajustesCortados($linha->ajustes, LinhasDaFolhaImpressa::SEPARADOR . $resto, $maximo);

        return [$cortada ?? $this->pagina->caber($linha->observacao, 'normal', self::OBSERVACAO_MENOR, $maximo), self::OBSERVACAO_MENOR];
    }

    /**
     * O que vem depois dos ajustes na observação (o feriado, a pendência e as justificativas), ou null no dia sem ajuste
     * ou só com ajustes.
     */
    private static function depoisDosAjustes(LinhaDaFolhaImpressa $linha): ?string
    {
        $comeco = $linha->ajustes . LinhasDaFolhaImpressa::SEPARADOR;
        if ($linha->ajustes === '' || !str_starts_with($linha->observacao, $comeco)) {
            return null;
        }

        return substr($linha->observacao, strlen($comeco));
    }

    /**
     * O maior começo dos ajustes que, com "..." e o resto inteiro (`$fim`, já com o separador), cabe em 8; o começo sai
     * sem o espaço e o " · " soltos antes do "...". Null se nem "..." com o resto cabe.
     */
    private function ajustesCortados(string $ajustes, string $fim, float $maximo): ?string
    {
        $comOResto = fn (int $letras): string => preg_replace('/[\s·]+$/u', '', mb_substr($ajustes, 0, $letras)) . self::RETICENCIAS . $fim;
        $cabe = fn (int $letras): bool => $this->pagina->largura($comOResto($letras), 'normal', self::OBSERVACAO_MENOR) <= $maximo;
        if (!$cabe(0)) {
            return null;
        }
        [$menor, $maior] = [0, mb_strlen($ajustes)];
        while ($menor < $maior) {
            $meio = intdiv($menor + $maior + 1, 2);
            if ($cabe($meio)) {
                $menor = $meio;
                continue;
            }
            $maior = $meio - 1;
        }

        return $comOResto($menor);
    }

    private function celula(string $texto, int $coluna, float $base, string $cor = PaginaDaFolha::TEXTO): void
    {
        $this->pagina->texto($texto, 'normal', 11, $this->centro($coluna), $base, $cor, true);
    }

    private function centro(int $coluna): float
    {
        return $this->xs[$coluna] + self::COLUNAS[$coluna] / 2;
    }
}
