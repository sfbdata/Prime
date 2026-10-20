<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * Os documentos do cadastro que ESTE usuário vê na tela (janela de Cadastro e página do cliente). O anexo próprio
 * aparece sempre; o VINCULADO do menu Documentos só aparece com o documento da pasta vivo (fora da lixeira) e para
 * quem pode ver aquela pasta: o arquivo é o da pasta. As pendências do cadastro não passam por aqui (contam o vínculo
 * disponível, qualquer que seja quem olha).
 */
final class DocumentosVisiveisDoCadastro
{
    public function __construct(
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /** @return list<ClienteDocumento> */
    public function de(Cliente $cliente, User $usuario, ?Tenant $tenant): array
    {
        return array_values(array_filter(
            $cliente->getDocumentos()->toArray(),
            fn (ClienteDocumento $d): bool => $this->visivel($d, $usuario, $tenant),
        ));
    }

    private function visivel(ClienteDocumento $documento, User $usuario, ?Tenant $tenant): bool
    {
        if (!$documento->isVinculado()) {
            return true;
        }
        $pastaId = $documento->estaDisponivel() ? $documento->getPastaDocumento()?->getPasta()?->getId() : null;

        return $pastaId !== null
            && $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW);
    }
}
