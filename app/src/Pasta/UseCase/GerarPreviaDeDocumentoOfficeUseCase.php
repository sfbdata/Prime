<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\PreviaDeDocumentoOffice;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use Psr\Log\LoggerInterface;

/**
 * Prévia em HTML de um DOCX ou XLSX da pasta (visualizador do Peticionar, F9). O arquivo é
 * materializado numa área temporária privada desta execução e a conversão roda sobre ela.
 * Devolve nulo quando o tipo não tem prévia, o arquivo é grande demais ou a conversão falha:
 * a tela então oferece só o download.
 */
final class GerarPreviaDeDocumentoOfficeUseCase
{
    public const TAMANHO_MAXIMO = 10 * 1024 * 1024;

    private const FINALIDADE_DA_AREA = 'previa';

    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly PreviaDeDocumentoOffice $previa,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws ArquivoNaoEncontrado quando o arquivo da peça sumiu do acervo */
    public function executar(PastaDocumento $documento): ?string
    {
        $mime = $documento->getMimeType();
        if (!PreviaDeDocumentoOffice::aceita($mime) || $documento->getTamanhoBytes() > self::TAMANHO_MAXIMO) {
            return null;
        }

        $conteudo = $this->armazenamento->ler(ChavesDePasta::documento($documento));
        if (\strlen($conteudo) > self::TAMANHO_MAXIMO) {
            return null;
        }

        $area = AreaTemporariaPrivada::criar(self::FINALIDADE_DA_AREA);
        try {
            $caminho = $area->gravar($conteudo, $mime === PreviaDeDocumentoOffice::MIME_XLSX ? 'xlsx' : 'docx');

            if (!$this->previa->pacoteSeguro($caminho)) {
                $this->logger->warning('Prévia recusada: pacote Office com tamanho descompactado acima do teto.', ['documento' => $documento->getId()]);

                return null;
            }

            return $this->previa->converter($caminho, $mime);
        } catch (\Throwable $e) {
            $this->logger->warning('Prévia de documento Office falhou.', ['documento' => $documento->getId(), 'erro' => $e->getMessage()]);

            return null;
        } finally {
            $area->liberar();
        }
    }
}
