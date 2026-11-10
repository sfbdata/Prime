<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

use App\AssinaturaEletronica\Pdf\Alinhamento;
use App\AssinaturaEletronica\Pdf\Fonte;
use App\AssinaturaEletronica\Pdf\MedidorDeTexto;
use App\AssinaturaEletronica\Pdf\MolduraDaFolha;

/**
 * A página inteira do `pdf()` (dc:395-397): margens X = 92, L = 790, de 170 a 1220; título negrito 26 centrado
 * (entrelinha 36), subtítulo, cabeçalho e rótulo negrito 20, o resto 19 com entrelinha 28,5. Parágrafo justificado
 * com a última linha à esquerda, data à direita, linha de base 18 abaixo do topo da linha. Assinatura ocupa 112.
 * Página do PDF enviado vai sozinha numa página.
 */
final class DiagramacaoDePaginaInteira
{
    private const TAMANHO = 19.0;
    private const ENTRELINHA = 28.5;
    private const ALTURA_DA_ASSINATURA = 112.0;
    private const BASE_DA_LINHA = 18.0;
    private const NEGRITOS = ['t', 's', 'h', 'l'];

    /**
     * @param list<array<string, int|string>> $blocos
     *
     * @return list<list<ElementoDaPagina>>
     */
    public function diagramar(array $blocos): array
    {
        $paginacao = new Paginacao(MolduraDaFolha::TOPO, MolduraDaFolha::BASE);

        foreach ($blocos as $bloco) {
            $tipo = (string) $bloco['k'];
            if ($tipo === 'pg') {
                if (!$paginacao->noTopo()) {
                    $paginacao->nova();
                }
                $paginacao->por(new PaginaDoPdfEnviado((int) ($bloco['n'] ?? 1)));
                $paginacao->nova();
                continue;
            }

            if ($tipo === 'a') {
                $paginacao->garantir(self::ALTURA_DA_ASSINATURA);
                $centro = MolduraDaFolha::X + MolduraDaFolha::L / 2;
                $paginacao->por(new LugarDeAssinatura((int) ($bloco['i'] ?? 0), (string) ($bloco['r'] ?? ''), $paginacao->y, $centro, 200, false));
                $paginacao->y += self::ALTURA_DA_ASSINATURA;
                continue;
            }

            $this->texto($paginacao, $tipo, (string) ($bloco['x'] ?? ''));
        }

        return $paginacao->paginas();
    }

    private function texto(Paginacao $paginacao, string $tipo, string $texto): void
    {
        $negrito = \in_array($tipo, self::NEGRITOS, true);
        $tamanho = $tipo === 't' ? 26.0 : ($negrito ? 20.0 : self::TAMANHO);
        $entrelinha = $tipo === 't' ? 36.0 : self::ENTRELINHA;
        $alinhamento = match ($tipo) {
            't', 's' => Alinhamento::Centro,
            'd' => Alinhamento::Direita,
            default => Alinhamento::Esquerda,
        };
        $espaco = MedidorDeTexto::largura(' ', Fonte::arial($negrito), $tamanho);
        $linhas = TextoComNegrito::quebrar(TextoComNegrito::palavras($texto), $tamanho, MolduraDaFolha::L, $espaco, $negrito);

        foreach ($linhas as $i => $linha) {
            $paginacao->garantir($entrelinha);
            $justificar = $tipo === 'p' && $i < \count($linhas) - 1;
            $paginacao->por(new LinhaDeTexto($linha, MolduraDaFolha::X, MolduraDaFolha::L, $paginacao->y + self::BASE_DA_LINHA, $tamanho, $espaco, $alinhamento, $justificar, $negrito));
            $paginacao->y += $entrelinha;
        }

        $paginacao->y += match ($tipo) {
            't' => 2,
            's' => 22,
            'h' => 4,
            default => 12,
        };
    }
}
