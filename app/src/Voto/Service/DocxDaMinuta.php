<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Voto\Exception\ModeloDeAtaInvalidoException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O .docx da minuta de ata (desenho 18, bj-voto-ata.js `gerar`). Com modelo (papel timbrado do usuário), o corpo entra no
 * `<w:body>` do modelo, antes do `<w:sectPr>` final, e o resto do pacote (cabeçalho, rodapé, estilos, imagens) fica como
 * veio; sem modelo, gera um .docx simples. O modelo chega em bytes, nunca é guardado: o zip passa só pela área
 * temporária privada (o ZipArchive só abre arquivo), apagada no `finally`.
 *
 * Defesas do modelo: zip de verdade com `word/document.xml`, no máximo {@see TAMANHO_MAXIMO_DO_MODELO} bytes, no máximo
 * {@see MAXIMO_DE_ENTRADAS} entradas, `document.xml` de até {@see TAMANHO_MAXIMO_DO_DOCUMENTO} bytes (a leitura é limitada,
 * não confia no tamanho declarado) e sem `<!DOCTYPE`/`<!ENTITY` (o texto do modelo não é interpretado como XML aqui).
 */
final class DocxDaMinuta
{
    public const TAMANHO_MAXIMO_DO_MODELO = 2_500_000;
    public const TAMANHO_MAXIMO_DO_DOCUMENTO = 20 * 1024 * 1024;
    public const MAXIMO_DE_ENTRADAS = 2000;

    private const NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    private const SECAO = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1418" w:right="1418" w:bottom="1418" w:left="1701" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr>';
    private const CABECALHO_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    public function __construct(private readonly TranslatorInterface $tradutor)
    {
    }

    /**
     * @param string  $corpo   WordprocessingML do corpo (ConversorDaMinutaParaDocx)
     * @param ?string $modelo  bytes do .docx do usuário, ou null para o documento simples
     *
     * @throws ModeloDeAtaInvalidoException
     */
    public function montar(string $corpo, ?string $modelo = null): string
    {
        $area = AreaTemporariaPrivada::criar('minutadeata');
        try {
            return null === $modelo ? $this->simples($corpo, $area) : $this->noModelo($corpo, $modelo, $area);
        } finally {
            $area->liberar();
        }
    }

    private function simples(string $corpo, AreaTemporariaPrivada $area): string
    {
        $caminho = $area->caminho() . '/minuta.docx';
        $zip = new \ZipArchive();
        if (true !== $zip->open($caminho, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Não foi possível montar o .docx da minuta.');
        }
        $zip->addFromString('[Content_Types].xml', self::CABECALHO_XML . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', self::CABECALHO_XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', self::CABECALHO_XML . '<w:document ' . self::NS . '><w:body>' . $corpo . self::SECAO . '</w:body></w:document>');
        $zip->close();

        return $this->ler($caminho);
    }

    private function noModelo(string $corpo, string $modelo, AreaTemporariaPrivada $area): string
    {
        if (\strlen($modelo) > self::TAMANHO_MAXIMO_DO_MODELO) {
            throw $this->erro('modelo_grande');
        }
        $caminho = $area->gravar($modelo, 'docx');
        $zip = new \ZipArchive();
        if (!str_starts_with($modelo, "PK\x03\x04") || true !== $zip->open($caminho)) {
            throw $this->erro('modelo_invalido');
        }
        try {
            $documento = $this->lerODocumento($zip);
            $zip->addFromString('word/document.xml', $this->comOCorpo($documento, $corpo));
        } finally {
            $zip->close();
        }

        return $this->ler($caminho);
    }

    private function lerODocumento(\ZipArchive $zip): string
    {
        if ($zip->numFiles > self::MAXIMO_DE_ENTRADAS) {
            throw $this->erro('modelo_invalido');
        }
        $fluxo = $zip->getStream('word/document.xml');
        if (false === $fluxo) {
            throw $this->erro('modelo_sem_documento');
        }
        $xml = stream_get_contents($fluxo, self::TAMANHO_MAXIMO_DO_DOCUMENTO + 1);
        fclose($fluxo);
        if (!\is_string($xml) || \strlen($xml) > self::TAMANHO_MAXIMO_DO_DOCUMENTO) {
            throw $this->erro('modelo_nao_reconhecido');
        }

        return $xml;
    }

    /** O corpo no lugar do conteúdo do `<w:body>`, antes do `<w:sectPr>` final (o que guarda cabeçalho, rodapé e página). */
    private function comOCorpo(string $documento, string $corpo): string
    {
        $fim = strrpos($documento, '</w:body>');
        if (1 !== preg_match('/<w:body(?:\s[^>]*)?>/', $documento, $m, \PREG_OFFSET_CAPTURE) || false === $fim
            || 1 === preg_match('/<!(?:DOCTYPE|ENTITY)/i', $documento)) {
            throw $this->erro('modelo_nao_reconhecido');
        }
        $inicio = $m[0][1] + \strlen($m[0][0]);
        $secao = $fim <= $inicio ? null : self::inicioDoSectPrFinal(substr($documento, 0, $fim), $inicio);
        if (null === $secao) {
            throw $this->erro('modelo_nao_reconhecido');
        }

        return substr($documento, 0, $inicio) . $corpo . substr($documento, $secao);
    }

    /**
     * Onde começa o `<w:sectPr>` filho direto do body: o último elemento antes de `</w:body>`. Casa só `<w:sectPr` seguido
     * de espaço, `>` ou `/` (nunca `<w:sectPrChange`), acompanha o aninhamento (um sectPr dentro de sectPrChange não conta)
     * e recusa o sectPr de quebra de seção dentro de parágrafo, que não vem colado no fim do body.
     */
    private static function inicioDoSectPrFinal(string $ateOFimDoBody, int $aPartirDe): ?int
    {
        $texto = rtrim($ateOFimDoBody);
        preg_match_all('#<(/?)w:sectPr(?=[\s>/])[^>]*?(/?)>#', $texto, $achados, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE, $aPartirDe);
        $profundidade = 0;
        $inicioDoAtual = null;
        $ultimo = null;
        foreach ($achados as $a) {
            $fechando = '/' === $a[1][0];
            $autoFechado = '/' === $a[2][0];
            if (!$fechando && 0 === $profundidade) {
                $inicioDoAtual = $a[0][1];
            }
            $profundidade += $fechando ? -1 : ($autoFechado ? 0 : 1);
            if (0 === $profundidade && null !== $inicioDoAtual) {
                $ultimo = [$inicioDoAtual, $a[0][1] + \strlen($a[0][0])];
                $inicioDoAtual = null;
            }
        }

        return null !== $ultimo && $ultimo[1] === \strlen($texto) ? $ultimo[0] : null;
    }

    private function ler(string $caminho): string
    {
        // Lê o zip que acabou de montar na área privada (o ZipArchive só grava em arquivo).
        return (new SplFileInfo($caminho, '', basename($caminho)))->getContents();
    }

    private function erro(string $chave): ModeloDeAtaInvalidoException
    {
        return new ModeloDeAtaInvalidoException($this->tradutor->trans('voto.assembleia.gestor.minuta.' . $chave));
    }
}
