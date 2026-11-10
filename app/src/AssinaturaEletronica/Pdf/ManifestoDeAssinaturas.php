<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * O manifesto de assinaturas (A2): as páginas depois do conteúdo, porta do bloco `if (!(d.limpa && !d.cod))` do
 * `pdf()` do desenho (dc:417-420). Recebe os textos já traduzidos; o montador pinta fundo, cabeçalho e rodapé.
 *
 * Primeira página: "MANIFESTO DE ASSINATURAS" (negrito 24, linha de base em 170) e o nome do documento (15, em 200);
 * um cartão de 118 por signatário a partir de 228, de 128 em 128 (fundo branco 0,72, contorno 0,7, barra de 4 na cor
 * da situação, rubrica de 66 de altura e até 200 de largura à direita); 6 abaixo, "TRILHA DE EVENTOS" (negrito 13) e,
 * 20 abaixo, os eventos do mais antigo ao mais novo, de 18 em 18. A nota legal fica em 1218 (BASE - 2) em toda página.
 *
 * O que não cabe continua (E3-9): o desenho põe tudo numa página só (e, com a trilha longa, corta os eventos que
 * passam de 3 linhas além do espaço); aqui um cartão ou evento cuja base passaria de 1202 (BASE - 18, o limite que o
 * desenho dá aos eventos) vai para a página seguinte, a partir de 170, sem repetir o título, com a trilha inteira. O
 * título da trilha nunca fica sozinho no pé: desce junto com o primeiro evento.
 */
final class ManifestoDeAssinaturas implements PaginasDepoisDoConteudo
{
    private const X = MolduraDaFolha::X;
    private const L = MolduraDaFolha::L;
    private const TOPO = MolduraDaFolha::TOPO;
    private const BASE = MolduraDaFolha::BASE;
    private const LIMITE = self::BASE - 18;
    private const ALTURA_DO_CARTAO = 118.0;
    private const PASSO_DO_CARTAO = 128.0;
    private const PASSO_DO_EVENTO = 18.0;
    private const TINTA = '#243845';
    private const CINZA = '#5b7383';

    /** @var list<list<array{tipo: string, y: float, cartao?: CartaoDoManifesto, texto?: string}>> */
    private array $paginas = [];

    /**
     * @param list<CartaoDoManifesto> $cartoes um por signatário, na ordem do documento
     * @param list<string>            $eventos já no formato "data  texto", do mais antigo ao mais novo
     */
    public function __construct(
        public readonly string $titulo,
        public readonly string $documento,
        public readonly array $cartoes,
        public readonly string $tituloDaTrilha,
        public readonly array $eventos,
        public readonly string $notaLegal,
    ) {
        $this->paginar();
    }

    public function quantas(): int
    {
        return \count($this->paginas);
    }

    public function desenhar(int $indice, Tela $tela, RecursosDaFolha $recursos): void
    {
        foreach ($this->paginas[$indice] ?? [] as $item) {
            $this->item($tela, $recursos, $item);
        }

        $tela->texto($this->notaLegal, self::X, self::BASE - 2, Fonte::Sans, 11.5, Cor::hex(self::CINZA));
    }

    /** @param array{tipo: string, y: float, cartao?: CartaoDoManifesto, texto?: string} $item */
    private function item(Tela $tela, RecursosDaFolha $recursos, array $item): void
    {
        if ($item['tipo'] === 'titulo') {
            $this->cabecalho($tela, $item['y']);

            return;
        }
        if (isset($item['cartao'])) {
            $this->cartao($tela, $recursos, $item['cartao'], $item['y']);

            return;
        }

        $trilha = $item['tipo'] === 'trilha';
        $tela->texto(
            $trilha ? $this->tituloDaTrilha : ($item['texto'] ?? ''),
            self::X,
            $item['y'],
            $trilha ? Fonte::SansNegrito : Fonte::Sans,
            $trilha ? 13 : 12,
            Cor::hex($trilha ? MolduraDaFolha::NV : self::TINTA),
        );
    }

    /** Distribui título, cartões, título da trilha e eventos pelas páginas, antes de qualquer desenho. */
    private function paginar(): void
    {
        $this->paginas = [[['tipo' => 'titulo', 'y' => self::TOPO]]];
        $y = self::TOPO + 58;

        foreach ($this->cartoes as $cartao) {
            $y = $this->colocar($y, self::ALTURA_DO_CARTAO, ['tipo' => 'cartao', 'cartao' => $cartao]) + self::PASSO_DO_CARTAO;
        }

        $y += 6;
        $y = $this->colocar($y, $this->eventos === [] ? 0.0 : 20.0, ['tipo' => 'trilha']) + 20;

        foreach ($this->eventos as $evento) {
            $y = $this->colocar($y, 0.0, ['tipo' => 'evento', 'texto' => $evento]) + self::PASSO_DO_EVENTO;
        }
    }

    /**
     * Põe o item em $y se o que ele ocupa abaixo dessa altura cabe até o limite; senão, no alto de uma página nova.
     *
     * @param array{tipo: string, cartao?: CartaoDoManifesto, texto?: string} $item
     *
     * @return float a altura em que o item ficou
     */
    private function colocar(float $y, float $ocupa, array $item): float
    {
        if ($y + $ocupa > self::LIMITE) {
            $this->paginas[] = [];
            $y = self::TOPO;
        }

        $this->paginas[\count($this->paginas) - 1][] = $item + ['y' => $y];

        return $y;
    }

    private function cabecalho(Tela $tela, float $y): void
    {
        $tela->texto($this->titulo, self::X, $y, Fonte::SansNegrito, 24, Cor::hex(MolduraDaFolha::NV));
        $tela->texto($this->documento, self::X, $y + 30, Fonte::Sans, 15, Cor::hex(self::TINTA));
    }

    private function cartao(Tela $tela, RecursosDaFolha $recursos, CartaoDoManifesto $cartao, float $y): void
    {
        $cor = $cartao->situacao->cor();
        $tela->retangulo(self::X, $y, self::L, self::ALTURA_DO_CARTAO, Cor::hex('#ffffff', .72));
        $tela->contorno(self::X, $y, self::L, self::ALTURA_DO_CARTAO, .7, Cor::hex(MolduraDaFolha::NV, .3));
        $tela->retangulo(self::X, $y, 4, self::ALTURA_DO_CARTAO, $cor);

        $tela->texto($cartao->nome, self::X + 16, $y + 25, Fonte::SansNegrito, 16, Cor::hex('#12242f'));
        $tela->texto($cartao->dados, self::X + 16, $y + 45, Fonte::Sans, 12.5, Cor::hex(self::TINTA));
        $tela->texto($cartao->tipo, self::X + 16, $y + 63, Fonte::Sans, 12.5, Cor::hex(self::TINTA));
        $tela->texto($cartao->textoDaSituacao, self::X + 16, $y + 83, Fonte::SansNegrito, 12.5, $cor);
        $tela->texto($cartao->nota, self::X + 16, $y + 101, Fonte::Sans, 11.5, Cor::hex(self::CINZA));

        $rubrica = $recursos->rubrica($cartao->indice);
        if ($rubrica !== null) {
            $largura = min(200, imagesx($rubrica) * 66 / imagesy($rubrica));
            $tela->imagem($rubrica, self::X + self::L - $largura - 14, $y + 26, $largura, 66);
        }
    }
}
