<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Http\FonteDeUploadHttp;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Confere e grava um arquivo do currículo (spec VS 18.10.26 §4.5, decisão 5). O tamanho e o tipo
 * saem do arquivo recebido, o tipo pelo CONTEÚDO (`finfo`). A foto é decodificada e regravada em JPG
 * 256×256, recortada ao centro como o `tfOnFoto` do desenho (l.1177): o que vai ao disco é só o que o
 * GD desenhou, nunca os bytes enviados. Não faz flush: a transação é do UseCase.
 */
final class ArmazenadorDeArquivosDoCurriculo
{
    private const LADO_DA_FOTO = 256;
    /**
     * Teto de pixels antes de decodificar: uma imagem pequena em bytes pode pedir gigabytes ao GD
     * (~4 bytes por pixel; com `memory_limit` de 128M, 12 milhões já são ~48 MB só da origem).
     */
    private const MAX_PIXELS = 12_000_000;

    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
    ) {
    }

    /** @return array{chave: string, nome: string} a chave gravada e o nome para o download */
    public function gravar(TipoArquivoDoCurriculo $tipo, UploadedFile $arquivo): array
    {
        if (!$arquivo->isValid()) {
            throw new DadosInvalidos('O arquivo não chegou inteiro. Tente de novo.');
        }
        $tamanho = (int) filesize($arquivo->getPathname());
        if ($tamanho > $tipo->limiteEmBytes()) {
            throw new DadosInvalidos($tipo->mensagemDeTamanho());
        }
        $mime = strtolower((string) (new \finfo(\FILEINFO_MIME_TYPE))->file($arquivo->getPathname()));
        $extensao = $tipo->tiposAceitos()[$mime] ?? throw new DadosInvalidos($tipo->mensagemDeTipo());

        if ($tipo === TipoArquivoDoCurriculo::Foto) {
            return ['chave' => $this->gravarFoto($arquivo->getPathname()), 'nome' => $tipo->nomePadrao()];
        }
        $nome = self::nomeParaDownload($arquivo->getClientOriginalName(), $tipo, $extensao);
        $upload = FonteDeUploadHttp::de($arquivo);

        return ['chave' => $upload->gravarEm($this->armazenamento, ChavesDeTrabalhoExterno::novoArquivoDoCurriculo($extensao))->chave->nome, 'nome' => $nome];
    }

    private function gravarFoto(string $caminho): string
    {
        $dimensoes = @getimagesize($caminho);
        if ($dimensoes === false || $dimensoes[0] < 1 || $dimensoes[1] < 1) {
            throw new DadosInvalidos(TipoArquivoDoCurriculo::Foto->mensagemDeTipo());
        }
        if ($dimensoes[0] * $dimensoes[1] > self::MAX_PIXELS) {
            throw new DadosInvalidos('A foto é grande demais. Envie uma imagem menor.');
        }
        $origem = $dimensoes[2] === \IMAGETYPE_PNG ? @imagecreatefrompng($caminho) : @imagecreatefromjpeg($caminho);
        if ($origem === false) {
            throw new DadosInvalidos(TipoArquivoDoCurriculo::Foto->mensagemDeTipo());
        }
        $area = AreaTemporariaPrivada::criar('curriculofoto');
        try {
            $temporario = $area->gravar($this->recortar($origem), 'jpg');

            return $this->armazenamento->gravar(
                ChavesDeTrabalhoExterno::novoArquivoDoCurriculo('jpg'),
                FonteDeConteudo::deArquivoLocal($temporario),
            )->chave->nome;
        } finally {
            $area->liberar();
        }
    }

    /** "Cobrir" o quadrado de 256 px, centralizado (o `Math.max(S / w, S / h)` do desenho). */
    private function recortar(\GdImage $origem): string
    {
        $w = imagesx($origem);
        $h = imagesy($origem);
        $k = max(self::LADO_DA_FOTO / $w, self::LADO_DA_FOTO / $h);
        $destino = imagecreatetruecolor(self::LADO_DA_FOTO, self::LADO_DA_FOTO);
        imagefill($destino, 0, 0, (int) imagecolorallocate($destino, 255, 255, 255));
        $largura = (int) round($w * $k);
        $altura = (int) round($h * $k);
        imagecopyresampled($destino, $origem, intdiv(self::LADO_DA_FOTO - $largura, 2), intdiv(self::LADO_DA_FOTO - $altura, 2), 0, 0, $largura, $altura, $w, $h);
        ob_start();
        imagejpeg($destino, null, 82);

        return (string) ob_get_clean();
    }

    /** O nome original, sem caminho; a extensão é a do conteúdo, não a que veio no nome. */
    private static function nomeParaDownload(string $original, TipoArquivoDoCurriculo $tipo, string $extensao): string
    {
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', pathinfo($original, \PATHINFO_FILENAME)));

        return mb_substr($base !== '' ? $base : $tipo->nomePadrao(), 0, 200) . '.' . $extensao;
    }
}
