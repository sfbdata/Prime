<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\DocumentoDuplicadoOutput;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Service\PermissionChecker;

/**
 * Depois de um upload: "já existe um arquivo idêntico neste escritório? Onde?"
 *
 * Quem: o usuário que acabou de enviar o documento. O quê: ser avisado — sem bloqueio — de que
 * o mesmo conteúdo já está em outra pasta (ou nesta), para não acumular cópias. O aviso é
 * informativo: o documento já foi gravado quando esta consulta roda.
 *
 * Isolamento, nesta ordem:
 *   1. o documento é do escritório da sessão — senão {@see PastaDeOutroEscritorioException};
 *   2. a consulta é por `(tenant, sha256)`, filtro explícito no repositório;
 *   3. só entram pastas que o usuário PODE VER (`PermissionChecker::canAccessResource` com
 *      `view`): um arquivo idêntico numa pasta restrita não é revelado — nem o título, nem o
 *      número da pasta. A verificação é uma por pasta, não por documento.
 *
 * Documento sem `sha256` (NULL) não tem com o que ser comparado: devolve vazio.
 */
final class ListarDuplicadosDoDocumentoUseCase
{
    public function __construct(
        private readonly PastaDocumentoRepository $repository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /** @return list<DocumentoDuplicadoOutput> */
    public function executar(PastaDocumento $documento, User $usuario, Tenant $tenant): array
    {
        if ($documento->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Documento não encontrado.');
        }

        $sha256 = $documento->getSha256();
        if ($sha256 === null) {
            return [];
        }

        $candidatos = $this->repository->comOMesmoConteudo($tenant, $sha256, $documento->getId());

        /** @var array<int, bool> $podeVer decisão por pasta, para não repetir a consulta de permissão */
        $podeVer    = [];
        $duplicados = [];

        foreach ($candidatos as $candidato) {
            $pastaId = $candidato->getPasta()?->getId();
            if ($pastaId === null) {
                continue;
            }

            $podeVer[$pastaId] ??= $this->permissionChecker->canAccessResource(
                $usuario,
                $tenant,
                AccessRequest::RESOURCE_PASTA,
                $pastaId,
                AccessRequest::ACTION_VIEW,
            );

            if (!$podeVer[$pastaId]) {
                continue;
            }

            $duplicados[] = DocumentoDuplicadoOutput::de($candidato);
        }

        return $duplicados;
    }
}
