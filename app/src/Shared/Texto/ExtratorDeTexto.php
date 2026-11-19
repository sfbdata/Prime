<?php

declare(strict_types=1);

namespace App\Shared\Texto;

/**
 * O extrator de texto comum do BlueJus (spec mcp-remoto-oauth 6.5): recebe os bytes de um arquivo e o formato que o
 * upload declarou e devolve só o TEXTO, normalizado e com teto de caracteres.
 *
 * O tipo declarado nunca basta: o CONTEÚDO tem de bater com o formato (assinatura, estrutura do pacote). XML e KML
 * são lidos como texto puro, nunca interpretados (nenhuma entidade é resolvida). As mensagens das exceções são fixas
 * e nunca levam o nome nem o conteúdo do arquivo.
 */
final class ExtratorDeTexto
{
    public const MAXIMO_DE_CARACTERES = 60_000;

    public function __construct(
        private readonly ExtratorDeTextoDocx $docx,
    ) {
    }

    /**
     * @throws ArquivoSemTextoException
     */
    public function extrair(string $bytes, FormatoDeTexto $formato, int $maximoDeCaracteres = self::MAXIMO_DE_CARACTERES): TextoExtraido
    {
        if ($bytes === '' || \strlen($bytes) > $formato->tamanhoMaximo()) {
            throw new ArquivoSemTextoException($bytes === '' ? 'O arquivo não tem texto para ler.' : 'O arquivo é grande demais.');
        }

        $cortadoNaOrigem = false;
        if ($formato === FormatoDeTexto::Docx) {
            $texto = $this->docx->extrair($bytes);
            if (str_ends_with($texto, ExtratorDeTextoDocx::AVISO_DE_CORTE)) {
                $texto = substr($texto, 0, -\strlen(ExtratorDeTextoDocx::AVISO_DE_CORTE));
                $cortadoNaOrigem = true;
            }
        } else {
            $texto = $this->lerBruto($bytes, $formato);
        }

        return $this->cortar($this->normalizar($texto), max(1, $maximoDeCaracteres), $cortadoNaOrigem);
    }

    private function lerBruto(string $bytes, FormatoDeTexto $formato): string
    {
        return match ($formato) {
            FormatoDeTexto::Doc => (new LeitorDeTextoDoDoc())->ler($bytes),
            FormatoDeTexto::Odt => (new LeitorDeTextoDoOdt())->ler($bytes),
            FormatoDeTexto::Rtf => $this->rtf($bytes),
            FormatoDeTexto::PecaDoEditor => $this->html($bytes),
            default => $this->textoPuro($bytes),
        };
    }

    private function rtf(string $bytes): string
    {
        if (!str_starts_with(ltrim($bytes, " \t\r\n"), '{\\rtf')) {
            throw new ArquivoSemTextoException('O arquivo não é um RTF válido.');
        }

        return (new LeitorDeTextoDoRtf())->ler($bytes);
    }

    /** TXT, CSV, XML e KML: texto puro, em UTF-8, UTF-16 com BOM ou Windows-1252. Nunca é interpretado. */
    private function textoPuro(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_contains($bytes, "\0")) {
            throw new ArquivoSemTextoException('O arquivo não é um arquivo de texto.');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /** HTML da peça do editor: tira imagem, script, estilo e comentário; blocos viram linhas; sem DOMDocument. */
    private function html(string $bytes): string
    {
        if (str_contains($bytes, "\0") || !mb_check_encoding($bytes, 'UTF-8')) {
            throw new ArquivoSemTextoException('O arquivo não é uma peça válida.');
        }

        $html = $bytes;
        $etapas = [
            ['~<!--.*?-->~s', ''],
            ['~<script\b.*?</script\s*>~is', ''],
            ['~<style\b.*?</style\s*>~is', ''],
            ['~<img\b[^>]*>~i', ''],
            ['~</t[dh]\s*>~i', "\t"],
            ['~</(?:p|div|li|h[1-6]|tr|blockquote)\s*>|<br\s*/?>~i', "\n"],
        ];
        foreach ($etapas as [$padrao, $troca]) {
            $html = preg_replace($padrao, $troca, $html);
            if ($html === null) {
                throw new ArquivoSemTextoException('O arquivo não é uma peça válida.');
            }
        }

        return str_replace("\u{A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalizar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        foreach (['/[\x00-\x08\x0B-\x1F]/', '/[ \t]+$/m', "/\n{3,}/"] as $indice => $padrao) {
            $texto = preg_replace($padrao, $indice === 2 ? "\n\n" : '', $texto);
            if ($texto === null) {
                throw new ArquivoSemTextoException('O arquivo não é um arquivo de texto.');
            }
        }

        $texto = trim($texto);
        if ($texto === '') {
            throw new ArquivoSemTextoException('O arquivo não tem texto para ler.');
        }

        return $texto;
    }

    private function cortar(string $texto, int $maximo, bool $cortadoNaOrigem): TextoExtraido
    {
        if (mb_strlen($texto) > $maximo) {
            return new TextoExtraido(mb_substr($texto, 0, $maximo), true);
        }

        return new TextoExtraido($texto, $cortadoNaOrigem);
    }
}
