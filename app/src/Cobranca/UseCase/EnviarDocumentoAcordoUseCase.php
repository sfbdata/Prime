<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Enum\CategoriaDocumentoAcordo;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Repository\AcordoDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Envia (faz upload de) um documento para um Acordo (Ajuste #4): termo de acordo, contrato e
 * outros arquivos relevantes no nível do acordo. Exibido na aba "Documentos" da tela do acordo,
 * lista simples e cronológica (sem drill-down).
 *
 * Espelha `EnviarDocumentoUseCase` (documentos de Caso): mesma whitelist de MIME + limites
 * (`EnviarDocumentoUseCase::MIME_LIMITS`, fonte ÚNICA — sem duplicar) e o arquivo físico é salvo
 * no MESMO diretório flat dos documentos de caso — `<cobrancasUploadsDir>/<tenantId>/<hash>`
 * (decisão deliberada: a purga (`PurgarEscritorioUseCase::removerDiretorioDeTenant`) só varre esse
 * diretório flat e não é recursiva; um diretório novo deixaria PII órfã em disco ao purgar o
 * tenant). `caminhoArquivo` guarda só o hash.
 */
final class EnviarDocumentoAcordoUseCase
{
    public function __construct(
        private readonly AcordoDocumentoRepository $documentoRepository,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    public function executar(
        Acordo $acordo,
        UploadedFile $file,
        CategoriaDocumentoAcordo $categoria,
        ?string $observacao,
        Tenant $tenant,
    ): AcordoDocumento {
        // Guarda multi-tenant: só se anexa documento a acordo do próprio escritório.
        if ($acordo->getTenant() !== $tenant) {
            throw new AccessDeniedException('Acordo não pertence ao tenant do usuário.');
        }

        $mimeType = $file->getMimeType() ?? '';

        if (!array_key_exists($mimeType, EnviarDocumentoUseCase::MIME_LIMITS)) {
            throw new TipoArquivoNaoPermitidoException($mimeType);
        }

        $tamanho = (int) $file->getSize();
        $limite  = EnviarDocumentoUseCase::MIME_LIMITS[$mimeType];

        if ($tamanho > $limite) {
            throw new ArquivoMuitoGrandeException($file->getClientOriginalName(), $limite);
        }

        // Isolamento físico por tenant no disco — MESMO diretório flat dos documentos de caso
        // (a purga não é recursiva; não criar subdiretório novo).
        $diretorio = $this->cobrancasUploadsDir . '/' . $tenant->getId();

        $hash = $this->storage->salvar($file, $diretorio);

        $documento = new AcordoDocumento();
        $documento->setAcordo($acordo);
        $documento->setTenant($tenant);
        $documento->setTitulo($file->getClientOriginalName());
        $documento->setCategoria($categoria);
        $documento->setObservacao(($observacao !== null && $observacao !== '') ? $observacao : null);
        $documento->setCaminhoArquivo($hash);
        $documento->setNomeOriginal($file->getClientOriginalName());
        $documento->setMimeType($mimeType);
        $documento->setTamanhoBytes($tamanho);

        $this->documentoRepository->salvar($documento, flush: true);

        return $documento;
    }
}
