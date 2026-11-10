<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;

/**
 * Cópia do PDF já assinado com o carimbo visível das assinaturas eletrônicas do BlueJus (etapa 5, bloco 5B). Porta do
 * `carimbar` do protótipo (bj-assina-pdf.js:23) e das linhas montadas no desenho (dc:384): uma anotação /Stamp
 * /Name/BlueJus no canto inferior esquerdo da última página, num acréscimo incremental (o original fica intacto no
 * começo do resultado), com tabela `xref` clássica ou fluxo /XRef, conforme o último índice do arquivo.
 *
 * Só carimba o que o motor permite (sem DocMDP 1 ou 2, sem cifra, sem assinatura alterada) e só entrega depois de o
 * motor conferir o resultado: o original é o começo dele, o número de assinaturas é o mesmo e cada assinatura íntegra
 * continua íntegra. Nunca grava nada: recebe e devolve bytes. Nenhuma exceção sai daqui.
 *
 * A recusa leva só o motivo, sem o " Use o original." do aviso: quem o acrescenta é o JS da tela (o `presCarimbo` do
 * desenho, dc:384: `r.motivo + ' Use o original.'`).
 */
final class CarimboVisivel
{
    public const SEM_ASSINATURA = 'Ainda não há assinatura eletrônica feita pelo BlueJus para carimbar.';
    public const PROTEGIDO_POR_SENHA = 'Arquivo protegido por senha.';
    public const ALTERACOES_BLOQUEADAS = 'O primeiro signatário bloqueou alterações neste PDF.';
    public const ESTRUTURA_NAO_RECONHECIDA = 'Estrutura do PDF não reconhecida.';
    public const PAGINA_NAO_LOCALIZADA = 'Página não localizada no PDF.';
    public const ANOTACOES_NAO_LOCALIZADAS = 'Anotações não localizadas.';
    public const PRESERVACAO_NAO_CONFIRMADA = 'Verificação final não confirmou a preservação.';
    /** O motivo de uma falha inesperada (o JS da tela acrescenta " Use o original.", como em toda recusa). */
    public const FALHA = 'Não foi possível carimbar.';

    /** Assinaturas no carimbo, como o `slice(0, 6)` do desenho; a linha do código vem depois delas. */
    public const ASSINATURAS_MAXIMAS = 6;

    public function __construct(
        private readonly ConferenciaDePdf $conferencia = new ConferenciaDePdf(),
        private readonly LocalizadorDaUltimaPagina $localizador = new LocalizadorDaUltimaPagina(),
    ) {
    }

    /**
     * @param list<CarimboVisivelAssinatura> $assinaturas as assinaturas BlueJus, na ordem dos signatários
     * @param string                         $codigo      o código de validação do documento
     */
    public function carimbar(string $pdf, array $assinaturas, string $codigo): CarimboVisivelResultado
    {
        if ($assinaturas === []) {
            return CarimboVisivelResultado::recusado(self::SEM_ASSINATURA);
        }

        try {
            return CarimboVisivelResultado::carimbado($this->comCarimbo($pdf, self::linhas($assinaturas, $codigo)));
        } catch (CarimboVisivelRecusa $recusa) {
            return CarimboVisivelResultado::recusado($recusa->getMessage());
        } catch (\Throwable) {
            return CarimboVisivelResultado::recusado(self::FALHA);
        }
    }

    /**
     * As linhas do carimbo (dc:384): até seis assinaturas e, por último, a do código.
     *
     * @param list<CarimboVisivelAssinatura> $assinaturas
     *
     * @return list<string>
     */
    public static function linhas(array $assinaturas, string $codigo): array
    {
        $linhas = array_map(
            static fn (CarimboVisivelAssinatura $assinatura): string => $assinatura->linha(),
            \array_slice($assinaturas, 0, self::ASSINATURAS_MAXIMAS),
        );
        $linhas[] = 'Código ' . $codigo . '. Confira em bluejus.com.br.';

        return $linhas;
    }

    /** @param list<string> $linhas */
    private function comCarimbo(string $pdf, array $linhas): string
    {
        $antes = $this->conferencia->conferir($pdf);
        self::exigirQuePermite($antes);
        $indice = CarimboVisivelIndice::ler($pdf) ?? throw new CarimboVisivelRecusa(self::ESTRUTURA_NAO_RECONHECIDA);
        $pagina = $this->localizador->localizar($pdf, $indice->numeroDaRaiz, $indice->geracaoDaRaiz)
            ?? throw new CarimboVisivelRecusa(self::PAGINA_NAO_LOCALIZADA);

        $carimbado = $pdf . CarimboVisivelAcrescimo::escrever($pdf, self::objetos($pagina, $indice->primeiroLivre, $linhas), $indice, $indice->primeiroLivre + 3);
        if (!self::preservou($antes, $this->conferencia->conferir($carimbado, $pdf))) {
            throw new CarimboVisivelRecusa(self::PRESERVACAO_NAO_CONFIRMADA);
        }

        return $carimbado;
    }

    /**
     * O `ana.carimbo` do protótipo. O PDF que o motor não consegue ler (vazio, acima do teto, sem cabeçalho) recusa
     * como estrutura não reconhecida, onde o protótipo pararia no `startxref`.
     */
    private static function exigirQuePermite(ResultadoDaConferencia $antes): void
    {
        if ($antes->situacao === SituacaoDaConferencia::Ilegivel) {
            throw new CarimboVisivelRecusa(self::ESTRUTURA_NAO_RECONHECIDA);
        }
        if (!$antes->permiteCarimbo) {
            throw new CarimboVisivelRecusa($antes->cifrado ? self::PROTEGIDO_POR_SENHA : self::ALTERACOES_BLOQUEADAS);
        }
    }

    /** A verificação final do protótipo, pelo motor; o resultado que o motor não lê também recusa. */
    private static function preservou(ResultadoDaConferencia $antes, ResultadoDaConferencia $depois): bool
    {
        if ($depois->situacao === SituacaoDaConferencia::Ilegivel
            || $depois->prefixo !== ConferenciaDoPrefixo::Confere
            || \count($depois->assinaturas) !== \count($antes->assinaturas)) {
            return false;
        }
        foreach ($antes->assinaturas as $i => $assinatura) {
            if ($assinatura->veredito === VereditoDaAssinatura::Integra && $depois->assinaturas[$i]->veredito !== VereditoDaAssinatura::Integra) {
                return false;
            }
        }

        return true;
    }

    /**
     * Anotação, aparência, fonte e página (e o vetor do /Annots indireto), com os números livres a partir do primeiro
     * livre do índice.
     * Geometria do protótipo: canto em (x0 + 20, y0 + 10) da caixa, largura min(380, largura da caixa - 40), altura
     * 12 + 10 por linha.
     *
     * @param list<string> $linhas
     *
     * @return list<array{int, int, string}>
     */
    private static function objetos(CarimboVisivelPagina $pagina, int $primeiroLivre, array $linhas): array
    {
        [$anotacao, $aparencia, $fonte] = [$primeiroLivre, $primeiroLivre + 1, $primeiroLivre + 2];
        [$caixaX0, $caixaY0, $caixaX1] = $pagina->caixa;
        $largura = min(380, $caixaX1 - $caixaX0 - 40);
        $altura = 12 + \count($linhas) * 10;
        $x0 = $caixaX0 + 20;
        $y0 = $caixaY0 + 10;
        [$corpoDaPagina, $vetor] = self::paginaComAnotacao($pagina, $anotacao . ' 0 R');
        $fluxo = self::aparencia($largura, $altura, $linhas);

        $objetos = [
            [$anotacao, 0, '<</Type/Annot/Subtype/Stamp/Name/BlueJus/Rect[' . self::numero($x0) . ' ' . self::numero($y0) . ' '
                . self::numero($x0 + $largura) . ' ' . self::numero($y0 + $altura) . ']/F 4/P ' . $pagina->numero . ' '
                . $pagina->geracao . ' R/Contents(' . self::texto(implode(' ', $linhas)) . ')/AP<</N ' . $aparencia . ' 0 R>>>>'],
            [$aparencia, 0, '<</Type/XObject/Subtype/Form/BBox[0 0 ' . self::numero($largura) . ' ' . $altura . ']/Resources<</Font<</F1 '
                . $fonte . ' 0 R>>>>/Length ' . \strlen($fluxo) . ">>\nstream\n" . $fluxo . "\nendstream"],
            [$fonte, 0, '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>'],
            [$pagina->numero, $pagina->geracao, $corpoDaPagina],
        ];
        if ($vetor !== null) {
            $objetos[] = $vetor;
        }

        return $objetos;
    }

    /**
     * O conteúdo da aparência, igual ao do protótipo: fundo 0.95 0.98 1, borda 0.04 0.44 0.63 de 0.7, Helvetica 7.6
     * na cor 0.05 0.2 0.31, uma linha a cada 10 pontos a partir de 6 da esquerda e 11 do topo.
     *
     * @param list<string> $linhas
     */
    private static function aparencia(int|float $largura, int $altura, array $linhas): string
    {
        $textos = array_map(static fn (string $linha): string => '(' . self::texto($linha) . ') Tj', $linhas);

        return 'q 0.95 0.98 1 rg 0 0 ' . self::numero($largura) . ' ' . $altura . ' re f 0.04 0.44 0.63 RG 0.7 w 0.4 0.4 '
            . self::numero($largura - 0.8) . ' ' . self::numero($altura - 0.8) . ' re S BT /F1 7.6 Tf 0.05 0.2 0.31 rg 6 '
            . ($altura - 11) . ' Td ' . implode(' 0 -10 Td ', $textos) . ' ET Q';
    }

    /**
     * A página com a referência da anotação no /Annots, na ordem do protótipo: vetor direto ganha a referência no fim;
     * referência a um vetor reescreve o vetor (a página vai igual); sem /Annots, entra `/Annots [ref]` logo depois do
     * `<<` que abre o dicionário (o de um comentário antes dele não conta). Outro valor no /Annots, ou vetor indireto
     * que não foi achado, recusa.
     *
     * @return array{string, array{int, int, string}|null} o corpo da página e o vetor reescrito, se houver
     */
    private static function paginaComAnotacao(CarimboVisivelPagina $pagina, string $referencia): array
    {
        $corpo = $pagina->corpo;
        if ($pagina->anotacoesDiretas !== null && $pagina->trechoDasAnotacoes !== null) {
            [$inicio, $fim] = $pagina->trechoDasAnotacoes;

            return [substr_replace($corpo, '/Annots [' . $pagina->anotacoesDiretas . ' ' . $referencia . ']', $inicio, $fim - $inicio), null];
        }
        if ($pagina->referenciaDasAnotacoes !== null) {
            return [$corpo, self::vetorComAnotacao($pagina->referenciaDasAnotacoes, $pagina->vetorDasAnotacoes, $referencia)];
        }
        if ($pagina->trechoDasAnotacoes !== null) {
            throw new CarimboVisivelRecusa(self::ANOTACOES_NAO_LOCALIZADAS);
        }

        return [substr_replace($corpo, '<</Annots [' . $referencia . ']', $pagina->aberturaDoDicionario, 2), null];
    }

    /**
     * @param array{int, int} $objeto
     *
     * @return array{int, int, string}
     */
    private static function vetorComAnotacao(array $objeto, ?string $vetor, string $referencia): array
    {
        if ($vetor === null || !str_starts_with($vetor, '[') || !str_ends_with($vetor, ']')) {
            throw new CarimboVisivelRecusa(self::ANOTACOES_NAO_LOCALIZADAS);
        }

        return [$objeto[0], $objeto[1], substr($vetor, 0, -1) . ' ' . $referencia . ']'];
    }

    /**
     * O `pdfTx` do protótipo: cada caractere fora de 0x20 a 0xFF vira `?` (dois, fora do plano básico, como nas duas
     * unidades UTF-16 do JavaScript), o resto vai em Latin-1 (WinAnsi) e `\`, `(` e `)` são escapados.
     */
    private static function texto(string $texto): string
    {
        $latin = '';
        foreach (mb_str_split(mb_scrub($texto, 'UTF-8'), 1, 'UTF-8') as $caractere) {
            $codigo = mb_ord($caractere, 'UTF-8');
            $latin .= match (true) {
                $codigo >= 0x20 && $codigo <= 0xFF => \chr($codigo),
                $codigo > 0xFFFF => '??',
                default => '?',
            };
        }

        return strtr($latin, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /** Número do PDF: inteiro sem casas; fração com até seis casas, sem zeros no fim (379.2, como o JavaScript). */
    private static function numero(int|float $valor): string
    {
        $texto = rtrim(rtrim(\sprintf('%.6F', $valor), '0'), '.');

        return $texto === '-0' ? '0' : $texto;
    }
}
