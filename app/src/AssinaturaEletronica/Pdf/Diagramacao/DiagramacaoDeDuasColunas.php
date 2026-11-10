<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

use App\AssinaturaEletronica\Pdf\Alinhamento;

/**
 * As duas colunas do `pdf()` (dc:399-403): introdução (blocos `full`) na largura toda a partir de 142, depois as
 * colunas de 398 com vão de 30 até 1250. A fonte começa em 17,5 e desce de 0,25 em 0,25 até tudo caber numa página
 * (entrelinha 1,17 e respiro 0,62 do tamanho). Não coube a 9,5: continua em outra página a 9,5 (E3-9; o desenho
 * deixaria a página vazia). Número (`n`) e título (`t`) ficam no cabeçalho ({@see \App\AssinaturaEletronica\Pdf\CabecalhoDeDuasColunas}).
 * Página do PDF enviado (`pg`), que o desenho não prevê nas colunas, vai sozinha numa página depois do texto.
 */
final class DiagramacaoDeDuasColunas
{
    public const MAIOR = 17.5;
    public const MENOR = 9.5;
    private const PASSO = .25;
    private const INICIO = 142.0;
    private const FORA_DAS_COLUNAS = ['n', 't', 'pg'];

    /**
     * @param list<array<string, int|string>> $blocos
     *
     * @return list<list<ElementoDaPagina>>
     */
    public function diagramar(array $blocos): array
    {
        $introducao = array_values(array_filter($blocos, static fn (array $b): bool => !empty($b['full'])));
        $resto = array_values(array_filter($blocos, static fn (array $b): bool => empty($b['full']) && !\in_array($b['k'], self::FORA_DAS_COLUNAS, true)));

        $paginas = null;
        for ($tamanho = self::MAIOR; $tamanho >= self::MENOR && $paginas === null; $tamanho -= self::PASSO) {
            $paginas = $this->montar($introducao, $resto, $tamanho, false);
        }
        $paginas ??= $this->montar($introducao, $resto, self::MENOR, true) ?? [[]];

        foreach ($blocos as $bloco) {
            if ($bloco['k'] === 'pg') {
                $paginas[] = [new PaginaDoPdfEnviado((int) ($bloco['n'] ?? 1))];
            }
        }

        return $paginas;
    }

    /**
     * `monta(FS)`: nulo quando não cabe numa página (e não pode continuar).
     *
     * @param list<array<string, int|string>> $introducao
     * @param list<array<string, int|string>> $resto
     *
     * @return list<list<ElementoDaPagina>>|null
     */
    private function montar(array $introducao, array $resto, float $tamanho, bool $continuar): ?array
    {
        $elementos = [];
        $y = $this->introducao($introducao, $tamanho, $elementos);
        $colunas = new ColunasDaFolha($y + 4, $continuar);
        foreach ($elementos as $elemento) {
            $colunas->por($elemento);
        }

        foreach ($resto as $i => $bloco) {
            $cabe = $bloco['k'] === 'a'
                ? $this->assinatura($colunas, $bloco, $tamanho)
                : $this->texto($colunas, $bloco, $resto[$i + 1] ?? null, $tamanho);
            if (!$cabe) {
                return null;
            }
        }

        return $colunas->paginas();
    }

    /**
     * @param list<array<string, int|string>> $introducao
     * @param list<ElementoDaPagina>          $elementos
     *
     * @return float a altura depois da introdução
     */
    private function introducao(array $introducao, float $tamanho, array &$elementos): float
    {
        $y = self::INICIO;
        $largura = ColunasDaFolha::XR - ColunasDaFolha::XL;

        foreach ($introducao as $bloco) {
            $linhas = TextoComNegrito::quebrar(TextoComNegrito::palavras((string) ($bloco['x'] ?? '')), $tamanho, $largura, $tamanho * .28, false);
            foreach ($linhas as $i => $linha) {
                $elementos[] = new LinhaDeTexto($linha, ColunasDaFolha::XL, $largura, $y + $tamanho, $tamanho, $tamanho * .28, Alinhamento::Esquerda, $i < \count($linhas) - 1, false);
                $y += $tamanho * 1.17;
            }
            $y += $tamanho * .62;
        }

        return $y;
    }

    /** @param array<string, int|string> $bloco */
    private function assinatura(ColunasDaFolha $colunas, array $bloco, float $tamanho): bool
    {
        if (!$colunas->avancar($tamanho * 4.7)) {
            return false;
        }

        $centro = $colunas->x() + ColunasDaFolha::LARGURA / 2;
        $colunas->por(new LugarDeAssinatura((int) ($bloco['i'] ?? 0), (string) ($bloco['r'] ?? ''), $colunas->y, $centro, ColunasDaFolha::LARGURA * .42, true));
        $colunas->y += $tamanho * 4.7;

        return true;
    }

    /**
     * Cabeçalho (`h`) em negrito, puxando três linhas para não ficar sozinho no pé da coluna; parágrafo puxa duas.
     * Depois do bloco, respiro de 2 (cabeçalho), 1,5 (antes de subitem "1)" ou "1.1)") ou 0,62 do tamanho.
     *
     * @param array<string, int|string>      $bloco
     * @param array<string, int|string>|null $seguinte
     */
    private function texto(ColunasDaFolha $colunas, array $bloco, ?array $seguinte, float $tamanho): bool
    {
        $entrelinha = $tamanho * 1.17;
        $cabecalho = $bloco['k'] === 'h';
        $linhas = TextoComNegrito::quebrar(TextoComNegrito::palavras((string) ($bloco['x'] ?? '')), $tamanho, ColunasDaFolha::LARGURA, $tamanho * .28, $cabecalho);
        if (!$colunas->avancar($entrelinha * ($cabecalho ? 3 : min(2, \count($linhas))))) {
            return false;
        }

        foreach ($linhas as $j => $linha) {
            if (!$colunas->avancar($entrelinha)) {
                return false;
            }
            $alinhamento = $bloco['k'] === 'd' ? Alinhamento::Direita : Alinhamento::Esquerda;
            $justificar = $bloco['k'] === 'p' && $j < \count($linhas) - 1;
            $colunas->por(new LinhaDeTexto($linha, $colunas->x(), ColunasDaFolha::LARGURA, $colunas->y + $tamanho, $tamanho, $tamanho * .28, $alinhamento, $justificar, $cabecalho));
            $colunas->y += $entrelinha;
        }

        $subitem = $seguinte !== null && $seguinte['k'] === 'p' && preg_match('/^\d(\.\d)?\)/', (string) ($seguinte['x'] ?? '')) === 1;
        $colunas->y += $cabecalho ? 2 : ($subitem ? 1.5 : $tamanho * .62);

        return true;
    }
}
