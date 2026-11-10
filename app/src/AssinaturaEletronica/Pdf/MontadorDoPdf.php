<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\AssinaturaEletronica\Pdf\Diagramacao\DiagramacaoDeDuasColunas;
use App\AssinaturaEletronica\Pdf\Diagramacao\DiagramacaoDePaginaInteira;
use App\AssinaturaEletronica\Pdf\Diagramacao\ElementoDaPagina;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;

/**
 * Monta o PDF de uma {@see FolhaParaMontar} (porta do `pdf(d, modo)`, dc:392-427; E3-1): diagrama o conteúdo, pinta
 * cada página na GD (moldura, conteúdo, rodapé), passa as páginas de {@see PaginasDepoisDoConteudo} e escreve o PDF.
 * Uma página por vez: gerada, vira JPEG e é liberada antes da seguinte.
 *
 * Folha branca (E3-3): só o fundo das páginas do conteúdo fica branco; cabeçalho e rodapé com QR seguem, e as páginas
 * depois do conteúdo mantêm o fundo.
 */
final class MontadorDoPdf
{
    public const TITULO = 'Documento BlueJus %s';

    public function __construct(
        private readonly string $ghostscriptBin,
        private readonly MolduraDaFolha $moldura = new MolduraDaFolha(),
        private readonly DiagramacaoDePaginaInteira $paginaInteira = new DiagramacaoDePaginaInteira(),
        private readonly DiagramacaoDeDuasColunas $duasColunas = new DiagramacaoDeDuasColunas(),
        private readonly CabecalhoDeDuasColunas $cabecalhoDeDuasColunas = new CabecalhoDeDuasColunas(),
        private readonly DesenhoDoConteudo $conteudo = new DesenhoDoConteudo(),
    ) {
    }

    /**
     * Os bytes do PDF.
     *
     * @throws FalhaAoMontarPdf
     */
    public function montar(FolhaParaMontar $folha): string
    {
        $pdf = '';
        foreach ($this->emPedacos($folha) as $pedaco) {
            $pdf .= $pedaco;
        }

        return $pdf;
    }

    /**
     * O PDF em pedaços (início, uma página por pedaço, fim), para quem quiser gravar sem juntar tudo na memória.
     *
     * @return \Generator<int, string>
     *
     * @throws FalhaAoMontarPdf
     */
    public function emPedacos(FolhaParaMontar $folha): \Generator
    {
        $recursos = new RecursosDaFolha($folha, new RasterDoPdfEnviado($this->ghostscriptBin, $folha->pdfEnviado));
        $escritor = new EscritorDePdf();

        try {
            $paginas = $this->diagramar($folha);
            $total = \count($paginas) + $folha->paginasDepois->quantas();

            yield $escritor->inicio();
            foreach ($paginas as $i => $elementos) {
                yield $this->paginaDoConteudo($escritor, $recursos, $elementos, $i + 1, $total);
            }
            for ($j = 0; $j < $folha->paginasDepois->quantas(); ++$j) {
                yield $this->paginaDepois($escritor, $recursos, $j, \count($paginas) + $j + 1, $total);
            }
            yield $escritor->fim(sprintf(self::TITULO, $folha->codigoImpresso()));
        } finally {
            $recursos->liberar();
        }
    }

    /**
     * As páginas do conteúdo, sem as vazias do fim (`while (!ops[ops.length - 1].length) ops.pop()`). Sem conteúdo
     * nenhum, fica uma página com moldura e rodapé: o PDF nunca sai sem página.
     *
     * @return list<list<ElementoDaPagina>>
     */
    private function diagramar(FolhaParaMontar $folha): array
    {
        $paginas = $folha->duasColunas()
            ? $this->duasColunas->diagramar($folha->blocos)
            : $this->paginaInteira->diagramar($folha->blocos);

        while ($paginas !== [] && $paginas[array_key_last($paginas)] === []) {
            array_pop($paginas);
        }

        return $paginas === [] ? [[]] : $paginas;
    }

    /** @param list<ElementoDaPagina> $elementos */
    private function paginaDoConteudo(EscritorDePdf $escritor, RecursosDaFolha $recursos, array $elementos, int $numero, int $total): string
    {
        $folha = $recursos->folha;
        $duas = $folha->duasColunas();
        $tela = Tela::pagina($folha->folhaBranca);

        try {
            $this->moldura->fundoECabecalho($tela, $recursos, $numero, $folha->folhaBranca, $duas);
            if ($duas) {
                $this->cabecalhoDeDuasColunas->desenhar($tela, $recursos);
            }
            $this->conteudo->desenhar($tela, $elementos, $recursos);
            $this->moldura->rodape($tela, $recursos, $numero, $total, $duas);

            return $escritor->pagina($tela->gd());
        } finally {
            $tela->liberar();
        }
    }

    private function paginaDepois(EscritorDePdf $escritor, RecursosDaFolha $recursos, int $indice, int $numero, int $total): string
    {
        $tela = Tela::pagina(false);

        try {
            $this->moldura->fundoECabecalho($tela, $recursos, $numero, false, false);
            $recursos->folha->paginasDepois->desenhar($indice, $tela, $recursos);
            $this->moldura->rodape($tela, $recursos, $numero, $total, false);

            return $escritor->pagina($tela->gd());
        } finally {
            $tela->liberar();
        }
    }
}
