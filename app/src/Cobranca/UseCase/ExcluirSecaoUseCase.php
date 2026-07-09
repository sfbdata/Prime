<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui uma seção de documentos de um Caso de Cobrança (SPEC §15, Etapa 6).
 *
 * Decisão de negócio: excluir a seção EXCLUI seus documentos (espelha ExcluirPastaSecaoUseCase do
 * domínio Pasta). Antes de remover a linha, os arquivos físicos de cada documento são apagados do
 * disco — o diretório efetivo por tenant é `cobrancasUploadsDir/<tenantId>` (isolamento físico, padrão
 * M5); `caminhoArquivo` guarda só o hash. Depois de remover a seção, os documentos caem no banco por
 * cascade:['remove']. Só uma seção do próprio escritório pode ser excluída (guarda multi-tenant, IDOR).
 */
final class ExcluirSecaoUseCase
{
    public function __construct(
        private readonly CobrancaSecaoRepository $secaoRepository,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    public function executar(CobrancaSecao $secao, Tenant $tenant): void
    {
        // Guarda multi-tenant: não se exclui seção de outro escritório.
        if ($secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        // Diretório físico isolado por tenant (padrão M5).
        $diretorio = $this->cobrancasUploadsDir . '/' . $tenant->getId();

        // Apaga os arquivos físicos ANTES de remover as linhas — a cascade do banco só derruba os
        // registros, não os arquivos em disco.
        foreach ($secao->getDocumentos() as $documento) {
            $caminho = $this->storage->caminho($diretorio, $documento->getCaminhoArquivo());

            if ($this->storage->existe($caminho)) {
                $this->storage->excluir($caminho);
            }
        }

        // Remove a seção; os documentos caem por cascade:['remove'].
        $this->secaoRepository->remover($secao, true);
    }
}
