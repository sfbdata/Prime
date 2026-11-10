<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;
use App\Shared\Http\NomeSeguroDeArquivo;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O PDF assinado que o signatário gov.br ou ICP enviou pela tela pública (etapa 5, bloco 5C), já conferido no que vem
 * antes do motor: a pré-conferência e a assinatura com certificado leem o upload pelo mesmo caminho.
 *
 * Só PDF pelo conteúdo e até 20 MB, o teto do motor (E5-8); outra coisa é `ilegivel`, sem ler o arquivo grande. Os
 * bytes são os que o motor e o validador conferem e os que ficam guardados, com o sha256 deles.
 *
 * O nome vem do navegador: sai em UTF-8 válido, sem barra nem caractere de controle e com até 255 caracteres (o teto
 * do nome da versão guardada).
 */
final readonly class PdfAssinadoEnviado
{
    public const NOME_MAXIMO = 255;
    private const TIPO_DO_PDF = 'application/pdf';

    private function __construct(
        public string $bytes,
        public string $nome,
        public string $sha256,
    ) {
    }

    /** @throws AcaoRecusada `ilegivel`: upload que falhou, que não é PDF ou que passa de 20 MB */
    public static function de(UploadedFile $pdf): self
    {
        if (!$pdf->isValid() || (int) $pdf->getSize() > LimitesDaConferencia::PDF_MAXIMO_EM_BYTES || $pdf->getMimeType() !== self::TIPO_DO_PDF) {
            throw new AcaoRecusada(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::ILEGIVEL));
        }
        $bytes = $pdf->getContent();

        return new self($bytes, self::nomeSeguro($pdf->getClientOriginalName()), hash('sha256', $bytes));
    }

    /** O nome do arquivo como a tela e a versão guardada o mostram. */
    public static function nomeSeguro(string $nomeDoArquivo): string
    {
        return NomeSeguroDeArquivo::paraDownload(mb_substr(mb_scrub($nomeDoArquivo, 'UTF-8'), 0, self::NOME_MAXIMO));
    }
}
