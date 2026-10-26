<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\Exception\SelecaoDeDocumentosInvalidaException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Pasta\Entity\PastaDocumento;

/**
 * A regra do "Ler N documentos" (PR F da spec ia-anthropic-ligar): o que pode sair para o provedor, conferido
 * na solicitação (com o que o banco sabe do documento, antes de ir à fila) e de novo no worker (com o arquivo
 * de verdade, {@see \App\Inteligencia\Contexto\LeitorDeDocumentosDaPasta}).
 *
 * Limites (decisão do coordenador, 24/10/2026, mais os tetos da API da Anthropic):
 * - no máximo {@see MAXIMO_POR_ANALISE} documentos por análise;
 * - só PDF e imagem (JPEG, PNG, GIF, WEBP), que a API lê inline (bloco `document` e `image` em base64);
 * - PDF até {@see TAMANHO_MAXIMO_DO_PDF}, e os PDFs da análise com no máximo {@see PAGINAS_MAXIMAS} páginas somadas
 *   (na solicitação, as que o upload contou; no worker, a contagem que falta é feita pelo arquivo, e sem contagem
 *   o documento é recusado);
 * - imagem até {@see TAMANHO_MAXIMO_DA_IMAGEM}: a API recusa a imagem cuja base64 passe de 5 MB;
 * - todos juntos até {@see TAMANHO_MAXIMO_TOTAL}: a requisição inteira (base64 de tudo) tem teto de 32 MB.
 *
 * E o aceite: só com o aceite do escritório dado na versão do texto que cita os documentos
 * ({@see \App\Inteligencia\Enum\VersaoDoAceiteDaIa::ComDocumentos} ou maior).
 */
final class RegraDaLeituraDeDocumentos
{
    public const MAXIMO_POR_ANALISE = 3;
    public const MEGABYTE = 1024 * 1024;
    public const TAMANHO_MAXIMO_DO_PDF = 10 * self::MEGABYTE;
    public const TAMANHO_MAXIMO_DA_IMAGEM = 3 * self::MEGABYTE;
    public const TAMANHO_MAXIMO_TOTAL = 20 * self::MEGABYTE;
    /** Páginas somadas dos PDFs de uma análise. */
    public const PAGINAS_MAXIMAS = 100;
    /** Até onde o cabeçalho `%PDF-` pode estar. */
    public const INICIO_DO_PDF = 1024;

    /** Grafias que o upload já gravou para os tipos aceitos. */
    private const SINONIMOS = ['image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'application/x-pdf' => 'application/pdf'];

    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
    ) {
    }

    /** O aceite do escritório cobre a leitura de documentos (foi dado com o texto que a cita)? */
    public function aceiteCobre(Tenant $tenant): bool
    {
        return $this->configuracoes->findDoTenant($tenant)?->aceiteCobreDocumentos() ?? false;
    }

    /**
     * Confere os documentos pedidos pelo que o banco sabe deles (tipo declarado, tamanho, páginas), antes de
     * qualquer gasto. O worker confere de novo pelo arquivo.
     *
     * @param list<PastaDocumento> $documentos
     *
     * @throws SelecaoDeDocumentosInvalidaException
     */
    public function conferir(array $documentos): void
    {
        if (\count($documentos) > self::MAXIMO_POR_ANALISE) {
            throw SelecaoDeDocumentosInvalidaException::muitosParaLer(self::MAXIMO_POR_ANALISE);
        }

        $total = 0;
        $paginas = 0;
        foreach ($documentos as $documento) {
            $nome = $documento->getNomeOriginal();
            $tipo = self::tipoDeclarado($documento->getMimeType());
            if ($tipo === null) {
                throw SelecaoDeDocumentosInvalidaException::formatoNaoLido($nome);
            }
            $limite = self::limiteDe($tipo);
            if ($documento->getTamanhoBytes() > $limite) {
                throw SelecaoDeDocumentosInvalidaException::grandeDemais($nome, intdiv($limite, self::MEGABYTE));
            }
            $paginas += $tipo === AnexoDeDocumento::PDF ? ($documento->getPaginas() ?? 0) : 0;
            $total += $documento->getTamanhoBytes();
        }

        if ($paginas > self::PAGINAS_MAXIMAS) {
            throw SelecaoDeDocumentosInvalidaException::paginasDemais(self::PAGINAS_MAXIMAS);
        }

        if ($total > self::TAMANHO_MAXIMO_TOTAL) {
            throw SelecaoDeDocumentosInvalidaException::totalGrandeDemais(intdiv(self::TAMANHO_MAXIMO_TOTAL, self::MEGABYTE));
        }
    }

    /** O tipo aceito que o upload declarou (normalizado), ou nulo se não é PDF nem imagem lida. */
    public static function tipoDeclarado(string $mimeType): ?string
    {
        $tipo = strtolower(trim($mimeType));
        $tipo = self::SINONIMOS[$tipo] ?? $tipo;

        return $tipo === AnexoDeDocumento::PDF || \in_array($tipo, AnexoDeDocumento::IMAGENS, true) ? $tipo : null;
    }

    /** O teto de bytes do tipo (aceito). */
    public static function limiteDe(string $tipo): int
    {
        return $tipo === AnexoDeDocumento::PDF ? self::TAMANHO_MAXIMO_DO_PDF : self::TAMANHO_MAXIMO_DA_IMAGEM;
    }

    /**
     * O tipo pelo CONTEÚDO (assinatura do arquivo), sem confiar no que o upload declarou: é ele que vai como
     * `media_type`. Nulo = não é PDF nem uma das imagens aceitas. As imagens têm a assinatura no byte zero; o PDF
     * pode trazer lixo antes do cabeçalho, e o `%PDF-` vale nos primeiros {@see INICIO_DO_PDF} bytes (como os
     * leitores de PDF aceitam).
     */
    public static function tipoPeloConteudo(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            \strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_contains(substr($bytes, 0, self::INICIO_DO_PDF), '%PDF-') => AnexoDeDocumento::PDF,
            default => null,
        };
    }
}
