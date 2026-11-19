<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\ClienteNaoVinculadoAPastaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\AcessoAPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tirar um cliente da pasta (aba Partes, lixeira da linha do cliente; `pasta_cliente_desvincular`). O cadastro do
 * cliente continua existindo; só o vínculo sai.
 *
 * História: o cliente foi vinculado à pasta errada. A advogada o desvincula; se ele era o principal, o sistema promove
 * outro na hora ({@see Pasta::removeCliente()}: o de cadastro mais antigo entre os que sobraram), para a pasta com
 * cliente nunca ficar sem principal, e a tela mostra quem foi promovido.
 *
 * Guardas, sem sessão (pessoa e escritório por parâmetro): a pasta é do escritório
 * ({@see PastaDeOutroEscritorioException}); a pessoa pode EDITAR a pasta ({@see AcessoAPasta::podeEditar()},
 * {@see SemPermissaoParaEditarPastaException}); o cliente é do escritório ({@see ClienteNaoEncontradoException}) e está
 * na pasta ({@see ClienteNaoVinculadoAPastaException}).
 *
 * No conector (spec mcp-remoto-oauth, 6.6) desvincular é exclusão e vai por proposta; a recusa do envio automático
 * (o promovido com o envio ligado) é do conector, antes de chamar aqui.
 */
final class DesvincularClienteDaPastaUseCase
{
    public function __construct(
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return Cliente|null o cliente promovido a principal, quando o desvinculado era o principal e sobrou alguém; senão null
     *
     * @throws PastaDeOutroEscritorioException
     * @throws SemPermissaoParaEditarPastaException
     * @throws ClienteNaoEncontradoException
     * @throws ClienteNaoVinculadoAPastaException
     */
    public function executar(Pasta $pasta, Cliente $cliente, User $usuario, Tenant $tenant): ?Cliente
    {
        $this->exigirEdicaoDaPasta($pasta, $usuario, $tenant);
        if ($cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw new ClienteNaoEncontradoException((int) $cliente->getId());
        }
        if (!$pasta->getClientes()->contains($cliente)) {
            throw new ClienteNaoVinculadoAPastaException();
        }

        $eraPrincipal = $pasta->getClientePrincipal()?->getId() === $cliente->getId();
        $pasta->removeCliente($cliente);
        $this->em->flush();

        return $eraPrincipal ? $pasta->getClientePrincipal() : null;
    }

    private function exigirEdicaoDaPasta(Pasta $pasta, User $usuario, Tenant $tenant): void
    {
        if ($pasta->getTenant()?->getId() !== $tenant->getId()) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeEditar($usuario, $pasta)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
    }
}
