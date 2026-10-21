<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\Service\HistoricoDoCliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Cadastrar cliente" do modal Nova carteira de cobrança (decisão do dono, 21/10/2026).
 *
 * História: a carteira precisa de um cliente credor, mas o cadastro de cliente só existia pela pasta. Quem gerencia
 * carteiras abre a mesma janela de Cadastro sem pasta, preenche o que tem (nada é obrigatório) e salva; o cliente
 * nasce no cadastro do escritório, sem pasta, e o Histórico do cadastro ganha "<quem> criou o cadastro". CPF/CNPJ
 * informado precisa ser válido e não pode ser de outro cadastro do escritório.
 */
final class CriarClienteAvulsoUseCase
{
    public const CAPACIDADE = 'resources.carteira.gerenciar';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly CamposDoCadastro $campos,
        private readonly EntityManagerInterface $em,
        private readonly HistoricoDoCliente $historico,
    ) {
    }

    /** @param array<string, string> $campos */
    public function executar(string $tipo, array $campos, User $usuario, Tenant $tenant): Cliente
    {
        if (!$this->permissionChecker->hasPermission($usuario, $tenant, self::CAPACIDADE)) {
            throw new SemPermissaoParaEditarClienteException('Sem permissão para cadastrar cliente.');
        }

        $cliente = $tipo === 'pj' ? CriarClienteDaPastaUseCase::pjEmBranco() : CriarClienteDaPastaUseCase::pfEmBranco();
        $cliente->setTenant($tenant);
        $cliente->setCriadoPor($usuario);
        $this->campos->aplicar($cliente, $campos, $tenant);

        $this->em->persist($cliente);
        $this->historico->cadastroCriado($cliente, $usuario, $tenant);
        $this->em->flush();

        return $cliente;
    }
}
