<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\AssinaturaEletronica\Pdf\Diagramacao\ElementoDaPagina;
use App\AssinaturaEletronica\Pdf\Diagramacao\LinhaDeTexto;
use App\AssinaturaEletronica\Pdf\Diagramacao\LugarDeAssinatura;
use App\AssinaturaEletronica\Pdf\Diagramacao\PaginaDoPdfEnviado;
use App\AssinaturaEletronica\Pdf\Diagramacao\TextoComNegrito;

/**
 * Pinta os elementos de uma página do conteúdo (o `pg.forEach(o => …)` do `pdf()`, dc:405): linhas de texto, lugares
 * de assinatura e a página do PDF enviado.
 */
final class DesenhoDoConteudo
{
    private const TEXTO = '#12242f';

    /** @param list<ElementoDaPagina> $elementos */
    public function desenhar(Tela $tela, array $elementos, RecursosDaFolha $recursos): void
    {
        foreach ($elementos as $elemento) {
            if ($elemento instanceof LinhaDeTexto) {
                $this->linha($tela, $elemento);
            } elseif ($elemento instanceof LugarDeAssinatura) {
                $this->assinatura($tela, $elemento, $recursos);
            } elseif ($elemento instanceof PaginaDoPdfEnviado) {
                $this->paginaEnviada($tela, $elemento, $recursos);
            }
        }
    }

    /** Justificada: o espaço entre palavras estica até a margem; à direita e ao centro, a linha toda se desloca. */
    private function linha(Tela $tela, LinhaDeTexto $linha): void
    {
        $larguras = array_map(static fn ($p): float => TextoComNegrito::largura($p, $linha->tamanho, $linha->tudoNegrito), $linha->palavras);
        $soma = array_sum($larguras);
        $vaos = \count($larguras) - 1;
        $espaco = $linha->justificar && $vaos > 0 ? ($linha->largura - $soma) / $vaos : $linha->espaco;
        $x = match ($linha->alinhamento) {
            Alinhamento::Esquerda => $linha->x0,
            Alinhamento::Direita => $linha->x0 + $linha->largura - $soma - $linha->espaco * $vaos,
            Alinhamento::Centro => $linha->x0 + ($linha->largura - $soma - $linha->espaco * $vaos) / 2,
        };

        $cor = Cor::hex(self::TEXTO);
        foreach ($linha->palavras as $i => $palavra) {
            $fonte = Fonte::arial($linha->tudoNegrito || $palavra->negrito);
            $tela->texto($palavra->texto, $x, $linha->base, $fonte, $linha->tamanho, $cor);
            $x += $larguras[$i] + $espaco;
        }
    }

    /**
     * Rubrica (altura 54, largura até 240) 2 abaixo do topo, linha em +60 com 0,9 de espessura, nome negrito 15 em
     * +78 e "Assinado em" negrito 12,5 verde em +95. Mini: 34, +38, 13,5 em +52 e 11 em +66. Pendente: só linha e nome.
     */
    private function assinatura(Tela $tela, LugarDeAssinatura $lugar, RecursosDaFolha $recursos): void
    {
        [$alturaDaRubrica, $linha, $nome, $data] = $lugar->mini ? [34, 38, 52, 66] : [54, 60, 78, 95];
        $signatario = $recursos->folha->signatario($lugar->indice);
        $assinou = $signatario !== null && $signatario->assinou();

        $rubrica = $assinou ? $recursos->rubrica($lugar->indice) : null;
        if ($rubrica !== null) {
            // Como o desenho: a largura para em 240 e a altura fica, mesmo que a rubrica estique.
            $largura = min(240, imagesx($rubrica) * $alturaDaRubrica / imagesy($rubrica));
            $tela->imagem($rubrica, $lugar->centro - $largura / 2, $lugar->y + 2, $largura, $alturaDaRubrica);
        }

        $cor = Cor::hex(self::TEXTO);
        $tela->retangulo($lugar->centro - $lugar->meiaLargura, $lugar->y + $linha, $lugar->meiaLargura * 2, .9, $cor);
        $rotulo = trim($lugar->rotulo) !== '' ? $lugar->rotulo : ($signatario !== null ? $signatario->nome : TextosDaFolha::ASSINATURA);
        $fonte = $lugar->mini ? Fonte::Sans : Fonte::SansNegrito;
        $tela->texto(TextoComNegrito::semMarcas($rotulo), $lugar->centro, $lugar->y + $nome, $fonte, $lugar->mini ? 13.5 : 15, $cor, Alinhamento::Centro);

        if ($assinou && $signatario->assinadoEm !== null) {
            $texto = sprintf(TextosDaFolha::ASSINADO_EM, TextosDaFolha::dataHora($signatario->assinadoEm));
            $tela->texto($texto, $lugar->centro, $lugar->y + $data, Fonte::SansNegrito, $lugar->mini ? 11 : 12.5, Cor::hex('#1e7b45'), Alinhamento::Centro);
        }
    }

    /**
     * A página cabe em L x (BASE - TOPO), centrada, a partir de TOPO - 16, com contorno azul a 30%. O contorno tem a
     * espessura que o canvas do desenho deixou do fundo: 0,7 (a borda ondulada); na folha branca, o padrão 1.
     */
    private function paginaEnviada(Tela $tela, PaginaDoPdfEnviado $pagina, RecursosDaFolha $recursos): void
    {
        $imagem = $recursos->paginaDoPdfEnviado($pagina->numero);

        try {
            $escala = min(MolduraDaFolha::L / imagesx($imagem), (MolduraDaFolha::BASE - MolduraDaFolha::TOPO) / imagesy($imagem));
            $largura = imagesx($imagem) * $escala;
            $altura = imagesy($imagem) * $escala;
            $x = MolduraDaFolha::X + (MolduraDaFolha::L - $largura) / 2;
            $tela->imagem($imagem, $x, MolduraDaFolha::TOPO - 16, $largura, $altura);
            $espessura = $recursos->folha->folhaBranca ? 1.0 : .7;
            $tela->contorno($x, MolduraDaFolha::TOPO - 16, $largura, $altura, $espessura, Cor::hex(MolduraDaFolha::NV, .3));
        } finally {
            imagedestroy($imagem);
        }
    }
}
