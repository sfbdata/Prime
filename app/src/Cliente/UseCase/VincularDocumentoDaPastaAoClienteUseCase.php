<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\DocumentoDaPastaNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\DocumentoVinculadoRepository;
use App\Cliente\Service\HistoricoDoCliente;
use App\Cliente\Service\RegrasDosEncontrados;
use App\Cliente\Service\RotulosDaInteligenciaCadastral;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Service\PermissionChecker;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Vincular ao cadastro" (Inteligência cadastral, F-A; desenho 02, l.778 e 3387).
 *
 * Quem: quem pode EDITAR o cliente e VER a pasta do documento. O quê: o documento de identificação ou comprovante que
 * está numa pasta do cliente passa a valer no cadastro, SEM cópia do arquivo ({@see ClienteDocumento::vinculadoA}).
 * Fica no Histórico do cliente, só com rótulos. Vincular de novo o mesmo documento não duplica (devolve false), nem
 * quando dois pedidos chegam juntos: o índice único `uniq_cliente_documento_vinculo` recusa o segundo, e a recusa
 * vira false (a transação dele volta inteira, histórico junto). Qualquer documento que não seja um "Encontrado" do
 * cliente (pasta na lápide inclusive) dá {@see DocumentoDaPastaNaoEncontradoException} (404). As regras da tela valem
 * aqui também ({@see RegrasDosEncontrados}): cliente sem CPF/CNPJ válido não tem o bloco, e a categoria que o cadastro já
 * mostra a quem pede não é "Encontrado".
 */
final class VincularDocumentoDaPastaAoClienteUseCase
{
    public function __construct(
        private readonly PastaDocumentoRepository $documentosDaPasta,
        private readonly DocumentoVinculadoRepository $vinculados,
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissoes,
        private readonly HistoricoDoCliente $historico,
        private readonly RegrasDosEncontrados $regras,
    ) {
    }

    /** @return bool true quando criou o vínculo; false quando ele já existia */
    public function executar(Cliente $cliente, int $documentoId, User $usuario, Tenant $tenant): bool
    {
        $this->exigirEdicaoDoCliente($cliente, $usuario, $tenant);
        $origem = $this->carregarDocumentoDoCliente($cliente, $documentoId, $usuario, $tenant);
        if (\in_array((int) $origem->getId(), $this->vinculados->idsDePastaDocumentoVinculados($cliente, $tenant), true)) {
            return false;
        }
        $this->exigirRegrasDaTela($cliente, $origem, $usuario, $tenant);

        return $this->gravarVinculo(ClienteDocumento::vinculadoA($origem, $cliente, $tenant), $usuario);
    }

    private function gravarVinculo(ClienteDocumento $vinculo, User $usuario): bool
    {
        $vinculo->getCliente()?->getDocumentos()->add($vinculo);
        $this->em->persist($vinculo);
        $this->historico->documentoVinculado($vinculo, $usuario);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return false; // outro pedido vinculou o mesmo documento entre a conferência e o flush
        }

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

    /** As regras da tela ({@see RegrasDosEncontrados}): sem CPF/CNPJ válido ou com a categoria já no cadastro, não é "Encontrado". */
    private function exigirRegrasDaTela(Cliente $cliente, PastaDocumento $origem, User $usuario, Tenant $tenant): void
    {
        if (RegrasDosEncontrados::documentoValido($cliente) === null
            || \in_array($origem->getCategoria(), $this->regras->categoriasNoCadastro($cliente, $usuario, $tenant), true)) {
            throw new DocumentoDaPastaNaoEncontradoException();
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
            && !$pasta->estaExcluida()
            && $pasta->getClientes()->contains($cliente)
            && $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW);
        if (!$valido) {
            throw new DocumentoDaPastaNaoEncontradoException();
        }

        return $documento;
    }
}
