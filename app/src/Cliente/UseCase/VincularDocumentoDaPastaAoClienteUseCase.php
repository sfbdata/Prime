<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\DocumentoDaPastaNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Service\HistoricoDoCliente;
use App\Cliente\Service\RotulosDaInteligenciaCadastral;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Repository\ClienteDocumentoRepository;
use App\Service\PermissionChecker;

/**
 * "Vincular ao cadastro" (Inteligência cadastral, F-A; desenho 02, l.778 e 3387).
 *
 * Quem: quem pode EDITAR o cliente e VER a pasta do documento. O quê: o documento de identificação ou comprovante que
 * está numa pasta do cliente passa a valer no cadastro, SEM cópia do arquivo ({@see ClienteDocumento::vinculadoA}).
 * Fica no Histórico do cliente, só com rótulos. Vincular de novo o mesmo documento não duplica (devolve false).
 * Qualquer documento que não seja um "Encontrado" do cliente dá {@see DocumentoDaPastaNaoEncontradoException} (404).
 */
final class VincularDocumentoDaPastaAoClienteUseCase
{
    public function __construct(
        private readonly PastaDocumentoRepository $documentosDaPasta,
        private readonly ClienteDocumentoRepository $documentosDoCliente,
        private readonly PermissionChecker $permissoes,
        private readonly HistoricoDoCliente $historico,
    ) {
    }

    /** @return bool true quando criou o vínculo; false quando ele já existia */
    public function executar(Cliente $cliente, int $documentoId, User $usuario, Tenant $tenant): bool
    {
        $this->exigirEdicaoDoCliente($cliente, $usuario, $tenant);
        $origem = $this->carregarDocumentoDoCliente($cliente, $documentoId, $usuario, $tenant);
        if (\in_array((int) $origem->getId(), $this->documentosDoCliente->idsDePastaDocumentoVinculados($cliente, $tenant), true)) {
            return false;
        }
        $vinculo = ClienteDocumento::vinculadoA($origem, $cliente, $tenant);
        $cliente->getDocumentos()->add($vinculo);
        $this->documentosDoCliente->salvar($vinculo);
        $this->historico->documentoVinculado($vinculo, $usuario);
        $this->documentosDoCliente->salvar($vinculo, flush: true);

        return true;
    }

    private function exigirEdicaoDoCliente(Cliente $cliente, User $usuario, Tenant $tenant): void
    {
        if ($cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw new ClienteNaoEncontradoException((int) $cliente->getId());
        }
        if (!$this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $cliente->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarClienteException('Sem permissão para editar este cliente.');
        }
    }

    private function carregarDocumentoDoCliente(Cliente $cliente, int $documentoId, User $usuario, Tenant $tenant): PastaDocumento
    {
        $documento = $this->documentosDaPasta->findByIdAndTenant($documentoId, $tenant);
        $pasta = $documento?->getPasta();
        $valido = $documento !== null && $pasta !== null
            && !$documento->estaNaLixeira()
            && \array_key_exists($documento->getCategoria(), RotulosDaInteligenciaCadastral::CATEGORIAS)
            && $pasta->getTenant()?->getId() === $tenant->getId()
            && $pasta->getClientes()->contains($cliente)
            && $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW);
        if (!$valido) {
            throw new DocumentoDaPastaNaoEncontradoException();
        }

        return $documento;
    }
}
