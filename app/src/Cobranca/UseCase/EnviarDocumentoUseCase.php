<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Enum\CategoriaDocumentoCobranca;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\SecaoNaoEncontradaException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\FonteDeUploadHttp;
use App\Shared\Service\CompressaoDeArquivoArmazenado;
use App\Shared\Service\ResultadoCompressao;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Envia (faz upload de) um documento para um Caso de Cobrança (SPEC §15, invariável 25). O documento
 * pertence ao Caso — NUNCA à Pasta — e pode existir antes de qualquer judicialização. A organização em
 * seções é opcional: quando informada, a seção precisa ser do mesmo tenant e do mesmo caso.
 *
 * A mecânica de arquivo é 100% reusada do Shared (§24): o arquivo físico é gravado em
 * `cobrancas/<tenantId>/<hash>` (isolamento físico por tenant no disco, padrão M5) e o `caminhoArquivo`
 * do documento guarda apenas o hash. A whitelist de MIME + limites de tamanho espelha o upload de peças
 * da Pasta (App\Pasta\UseCase\UploadPecaUseCase), mantendo o mesmo contrato de tipos aceitos.
 */
final class EnviarDocumentoUseCase
{
    /**
     * Whitelist de MIME => limite de bytes. Espelha App\Pasta\UseCase\UploadPecaUseCase::MIME_LIMITS
     * (mesmos tipos e limites aceitos no upload de peças da Pasta). PÚBLICA (só a visibilidade — o
     * comportamento é idêntico) para ser a fonte ÚNICA reusada pelos UseCases de documento de
     * Carteira e Acordo (Ajustes #4/#5) — evita duplicar/desviar a lista.
     */
    public const MIME_LIMITS = [
        'image/png'                                                                          => 3 * 1024 * 1024,
        'image/jpeg'                                                                         => 3 * 1024 * 1024,
        'application/pdf'                                                                    => 10 * 1024 * 1024,
        'application/vnd.google-earth.kml+xml'                                               => 5 * 1024 * 1024,
        'application/xml'                                                                    => 5 * 1024 * 1024,
        'text/xml'                                                                           => 5 * 1024 * 1024,
        'application/vnd.ms-excel'                                                           => 10 * 1024 * 1024,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'                  => 10 * 1024 * 1024,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'            => 10 * 1024 * 1024,
        'application/msword'                                                                 => 10 * 1024 * 1024,
        'audio/mpeg'                                                                         => 10 * 1024 * 1024,
        'audio/ogg'                                                                          => 10 * 1024 * 1024,
        'audio/mp4'                                                                          => 10 * 1024 * 1024,
        'video/mp4'                                                                          => 50 * 1024 * 1024,
        'video/quicktime'                                                                    => 50 * 1024 * 1024,
        'text/plain'                                                                         => 5 * 1024 * 1024,
        'application/zip'                                                                    => 50 * 1024 * 1024,
        'application/pkcs7-signature'                                                        => 5 * 1024 * 1024,
        'audio/opus'                                                                         => 50 * 1024 * 1024,
    ];

    public function __construct(
        private readonly CobrancaDocumentoRepository $documentoRepository,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly CompressaoDeArquivoArmazenado $compressao,
    ) {
    }

    public function executar(
        CasoCobranca $caso,
        ?CobrancaSecao $secao,
        UploadedFile $file,
        CategoriaDocumentoCobranca $categoria,
        ?string $descricao,
        Tenant $tenant,
        bool $reduzirTamanho = false,
    ): CobrancaDocumento {
        // Guarda multi-tenant da seção (quando informada): tem de ser do próprio escritório.
        if ($secao !== null && $secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        // A seção informada precisa ser do MESMO caso — senão é uma seção "estranha" ao caso.
        if ($secao !== null && $secao->getCaso() !== $caso) {
            throw new SecaoNaoEncontradaException((int) $secao->getId());
        }

        // Guarda multi-tenant do caso: só se anexa documento a caso do próprio escritório.
        if ($caso->getTenant() !== $tenant) {
            throw new AccessDeniedException('Caso não pertence ao tenant do usuário.');
        }

        $mimeType = $file->getMimeType() ?? '';

        if (!array_key_exists($mimeType, self::MIME_LIMITS)) {
            throw new TipoArquivoNaoPermitidoException($mimeType);
        }

        $tamanho = (int) $file->getSize();
        $limite  = self::MIME_LIMITS[$mimeType];

        if ($tamanho > $limite) {
            throw new ArquivoMuitoGrandeException($file->getClientOriginalName(), $limite);
        }

        // O escopo da chave sai do CASO, nunca do parâmetro `$tenant` (R1) — a guarda acima já os
        // igualou. O isolamento físico por tenant no disco (padrão M5) é do resolvedor de caminho.
        $upload     = FonteDeUploadHttp::de($file);
        $armazenado = $upload->gravarEm($this->armazenamento, ChavesDeCobranca::novoDocumentoDeCaso($caso, $upload->extensao));
        $hash       = $armazenado->chave->nome;

        // D30: o tamanho vem do storage, medido depois da gravação (igual nos UseCases irmãos
        // de Carteira e Acordo, que espelham este).
        $compressao = ResultadoCompressao::naoComprimido($armazenado->tamanhoBytes);
        if ($reduzirTamanho) {
            $compressao = $this->compressao->comprimir($armazenado->chave, $mimeType);
        }

        $documento = new CobrancaDocumento();
        $documento->setCaso($caso);
        $documento->setSecao($secao);
        $documento->setTenant($tenant);
        $documento->setTitulo($file->getClientOriginalName());
        $documento->setCategoria($categoria);
        $documento->setDescricao(($descricao !== null && $descricao !== '') ? $descricao : null);
        $documento->setCaminhoArquivo($hash);
        $documento->setNomeOriginal($file->getClientOriginalName());
        $documento->setMimeType($mimeType);
        $documento->setTamanhoBytes($compressao->tamanhoFinal);
        $documento->setOrdem($this->documentoRepository->proximaOrdem($caso));

        $this->documentoRepository->salvar($documento, flush: true);

        return $documento;
    }
}
