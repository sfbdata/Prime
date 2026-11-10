<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDaFolha;

use App\Ponto\Service\ImagemDoComprovante\TelaDoComprovante;

/**
 * A página da folha do mês sobre a tela da E15, com os auxiliares do desenho 16 (L652 a L658): o texto `t`, o corte
 * `cap` com "...", o `quadro` de fio fino, a faixa de título `cabeca` e o par rótulo e valor `rot`.
 *
 * Guarda cada texto escrito e cada peça sem texto (o QR, o selo) na ordem em que entram: a imagem não tem texto que se
 * leia de volta, e é por esse registro que os testes conferem o que a página mostra e onde.
 */
final class PaginaDaFolha
{
    public const AZUL_ESCURO = '#0b3550';
    public const TEXTO = '#12242f';
    private const FIO = ['#0b3550', .32];
    private const FUNDO_DA_FAIXA = [214, 230, 241, .92];
    private const RETICENCIAS = '...';

    /** @var list<array{texto: string, fonte: string, tamanho: float, x: float, y: float, cor: string}> */
    private array $textos = [];

    /** @var list<string> */
    private array $pecas = [];

    public function __construct(
        private readonly TelaDoComprovante $tela,
    ) {
    }

    public function tela(): TelaDoComprovante
    {
        return $this->tela;
    }

    /**
     * O `t` do desenho: texto com a linha de base em (x, y); `centro` centraliza em x. O ângulo é o do GD (graus,
     * anti-horário), para a marca d'água e a faixa vertical.
     */
    public function texto(string $texto, string $fonte, float $tamanho, float $x, float $y, string $cor = self::TEXTO, bool $centro = false, float $opacidade = 1.0, float $angulo = 0.0): void
    {
        if ($texto === '') {
            return;
        }
        $inicio = $centro ? $x - $this->largura($texto, $fonte, $tamanho) / 2 : $x;
        $this->textos[] = ['texto' => $texto, 'fonte' => $fonte, 'tamanho' => $tamanho, 'x' => $inicio, 'y' => $y, 'cor' => $cor];
        $this->tela->texto($texto, $fonte, $tamanho, $inicio, $y, $this->tela->cor($cor, $opacidade), $angulo);
    }

    public function largura(string $texto, string $fonte, float $tamanho): float
    {
        return $this->tela->largura($texto, $fonte, $tamanho);
    }

    /**
     * O `cap` do desenho (L653): o texto inteiro se couber em `maximo`; senão, o maior começo dele com "..." que cabe.
     */
    public function caber(string $texto, string $fonte, float $tamanho, float $maximo): string
    {
        if ($this->largura($texto, $fonte, $tamanho) <= $maximo) {
            return $texto;
        }
        [$menor, $maior] = [0, mb_strlen($texto)];
        while ($menor < $maior) {
            $meio = intdiv($menor + $maior + 1, 2);
            if ($this->largura(mb_substr($texto, 0, $meio) . self::RETICENCIAS, $fonte, $tamanho) <= $maximo) {
                $menor = $meio;
                continue;
            }
            $maior = $meio - 1;
        }

        return mb_substr($texto, 0, $menor) . self::RETICENCIAS;
    }

    /**
     * O tamanho em que o texto cabe em `maximo`, encolhendo de meio em meio até `minimo` (a DejaVu é mais larga que a
     * Arial do desenho). Quem chama ainda corta com {@see caber()} o que nem no mínimo coube.
     */
    public function tamanhoQueCabe(string $texto, string $fonte, float $tamanho, float $minimo, float $maximo): float
    {
        while ($tamanho > $minimo && $this->largura($texto, $fonte, $tamanho) > $maximo) {
            $tamanho = max($minimo, $tamanho - .5);
        }

        return $tamanho;
    }

    /** Uma linha de texto que encolhe até `minimo` e, se ainda não couber, é cortada com "...". */
    public function linhaQueCabe(string $texto, string $fonte, float $tamanho, float $minimo, float $x, float $y, float $maximo, string $cor = self::TEXTO): void
    {
        $tamanho = $this->tamanhoQueCabe($texto, $fonte, $tamanho, $minimo, $maximo);
        $this->texto($this->caber($texto, $fonte, $tamanho, $maximo), $fonte, $tamanho, $x, $y, $cor);
    }

    /** O `quadro` (L654): contorno de fio 0,7 em `rgba(11,53,80,.32)`. */
    public function quadro(float $x, float $y, float $largura, float $altura): void
    {
        $this->tela->contorno($x, $y, $largura, $altura, .7, $this->tela->cor(...self::FIO));
    }

    /** Retângulo cheio com a cor do fio: os traços finos da tabela (L670, L684 e L685). */
    public function fio(float $x, float $y, float $largura, float $altura): void
    {
        $this->tela->retangulo($x, $y, $largura, $altura, $this->tela->cor(...self::FIO));
    }

    public function retangulo(float $x, float $y, float $largura, float $altura, string $cor, float $opacidade = 1.0): void
    {
        $this->tela->retangulo($x, $y, $largura, $altura, $this->tela->cor($cor, $opacidade));
    }

    /** A `cabeca` (L655): faixa de 20 em `rgba(214,230,241,.92)` com o título em negrito 11,5, centrado ou à esquerda. */
    public function cabeca(string $titulo, float $x, float $y, float $largura, bool $centro): void
    {
        $this->faixaClara($x, $y, $largura, 20);
        $this->quadro($x, $y, $largura, 20);
        $this->texto($titulo, 'negrito', 11.5, $centro ? $x + $largura / 2 : $x + 7, $y + 14, self::AZUL_ESCURO, $centro);
    }

    /** O fundo das faixas de título e do cabeçalho da tabela (L655 e L668). */
    public function faixaClara(float $x, float $y, float $largura, float $altura): void
    {
        $this->tela->retangulo($x, $y, $largura, $altura, $this->tela->corRgb(...self::FUNDO_DA_FAIXA));
    }

    /** O `rot` (L656): rótulo em negrito 11 e o valor em 11 logo depois, cortado para caber em `maximo` no total. */
    public function rotulo(string $rotulo, string $valor, float $x, float $y, float $maximo): void
    {
        $largura = $this->largura($rotulo . ' ', 'negrito', 11);
        $this->texto($rotulo, 'negrito', 11, $x, $y);
        $this->texto($this->caber($valor, 'normal', 11, $maximo - $largura), 'normal', 11, $x + $largura, $y);
    }

    /** Uma peça sem texto que entrou na página ("qr", "selo", "faixa-de-validacao"). */
    public function marcar(string $peca): void
    {
        $this->pecas[] = $peca;
    }

    /** @return list<array{texto: string, fonte: string, tamanho: float, x: float, y: float, cor: string}> */
    public function textos(): array
    {
        return $this->textos;
    }

    /** @return list<string> */
    public function pecas(): array
    {
        return $this->pecas;
    }

    public function jpeg(): string
    {
        return $this->tela->jpeg();
    }
}
