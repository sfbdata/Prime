<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\AssinaturaEletronica\Pdf\Diagramacao\DiagramacaoDeDuasColunas;
use App\AssinaturaEletronica\Pdf\Diagramacao\DiagramacaoDePaginaInteira;
use App\AssinaturaEletronica\Pdf\Diagramacao\ElementoDaPagina;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Monta o PDF de uma {@see FolhaParaMontar} (porta do `pdf(d, modo)`, dc:392-427; E3-1): diagrama o conteúdo, pinta
 * cada página na GD (moldura, conteúdo, rodapé), passa as páginas de {@see PaginasDepoisDoConteudo} e escreve o PDF.
 * Uma página por vez: gerada, vira JPEG e é liberada antes da seguinte.
 *
 * Folha branca (E3-3, `limpaP` em dc:408 e o `pe()` que sai cedo em dc:411): as páginas do conteúdo ficam com fundo
 * branco e só o cabeçalho, sem rodapé; as páginas depois do conteúdo mantêm fundo e rodapé.
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
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * O PDF em pedaços: o início, uma página por pedaço e o fim, na ordem do arquivo.
     *
     * Quem grava ou entrega (armazenamento, resposta de download) consome pedaço a pedaço e nunca junta tudo numa
     * string. O porquê é memória: cada página é uma JPEG de 2480 x 3507 px, e a string do PDF inteiro soma todas elas
     * ao pico da montagem. Medido com 28 páginas e limite de 128 MB: juntando, o pico foi de 122 MB e a montagem
     * falhou; consumindo os pedaços, o mesmo documento ficou em 66 MB.
     *
     * O perfil só muda a JPEG de cada página ({@see PerfilDoPdf}); o desenho da página é sempre o mesmo.
     *
     * @return \Generator<int, string>
     *
     * @throws FalhaAoMontarPdf
     */
    public function emPedacos(FolhaParaMontar $folha, PerfilDoPdf $perfil = PerfilDoPdf::Desenho): \Generator
    {
        $recursos = new RecursosDaFolha($folha, new RasterDoPdfEnviado($this->ghostscriptBin, $folha->pdfEnviado, $this->logger, $folha->hash));
        $escritor = new EscritorDePdf($perfil);

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
     * As páginas do conteúdo, sem as vazias do fim (`while (!ops[ops.length - 1].length) ops.pop()`, dc:406).
     *
     * Sem conteúdo nenhum, o desenho fica com `ops` vazio e `total = 1`: com páginas depois do conteúdo (o
     * manifesto), o PDF sai só com elas. Sem páginas depois só acontece na folha branca de rascunho (contrato em
     * {@see FolhaParaMontar}); ali o desenho sairia sem página nenhuma, e aqui fica uma página da folha branca, com
     * fundo branco, só o cabeçalho e sem rodapé (desvio aceito), para o PDF nunca sair vazio.
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

        if ($paginas === [] && $folha->paginasDepois->quantas() === 0) {
            return [[]];
        }

        return $paginas;
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
            if (!$folha->folhaBranca) {
                $this->moldura->rodape($tela, $recursos, $numero, $total, $duas);
            }

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
