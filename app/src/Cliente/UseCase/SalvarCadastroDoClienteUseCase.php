<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Service\CamposDoCadastro;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Salvar" da janela Cadastro do cliente, aberta pelo botão Cadastro da pasta (desenho 1.2.3, `cadSalvar`; E12f).
 *
 * História: a advogada abre a pasta de Gleisson, vê o ponto laranja no Cadastro e completa estado civil, número e
 * bairro. Ao salvar, o cadastro mestre do cliente muda (vale para todas as pastas dele), o cabeçalho passa a mostrar o
 * nome salvo e o Registro desta pasta ganha "Cadastro do cliente: GLEISSON BRUNO GABRIEL: estado civil, número,
 * bairro alterado(s).". Pendência não impede salvar ("Cadastro salvo. Ainda há pendências."); impedem: nome vazio,
 * CPF/CNPJ inválido e CPF/CNPJ que já é de outro cadastro do escritório (a tela oferece abrir ou usar esse cadastro).
 *
 * Só mexe no campo que veio no envio: campo ausente fica como está. Precisa poder EDITAR o cliente.
 */
final class SalvarCadastroDoClienteUseCase
{
    public const ESTADOS_CIVIS = CamposDoCadastro::ESTADOS_CIVIS;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly CamposDoCadastro $campos,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, string> $campos chaves de {@see CamposDoCadastro::ROTULOS}
     *
     * @return list<string> rótulos dos campos que mudaram (vazio = nada mudou, nada gravado)
     */
    public function executar(Cliente $cliente, array $campos, User $usuario, Tenant $tenant, ?Pasta $pasta = null): array
    {
        if ($cliente->getTenant()?->getId() !== $tenant->getId()) {
            throw new ClienteNaoEncontradoException((int) $cliente->getId());
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $cliente->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarClienteException('Sem permissão para editar este cliente.');
        }

        $alterados = $this->campos->aplicar($cliente, $campos, $tenant);
        if ($alterados === []) {
            return [];
        }

        $cliente->setModificadoEm(new \DateTimeImmutable());
        if ($pasta !== null && $pasta->getTenant()?->getId() === $tenant->getId() && $pasta->getClientes()->contains($cliente)) {
            $this->registro->registrar($pasta, $usuario, 'cadastro', $cliente->getNomeExibicao() . ': ' . implode(', ', $alterados) . ' alterado(s).');
        }
        $this->em->flush();

        return $alterados;
    }
}
