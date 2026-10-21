<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\Service\HistoricoDoCliente;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Novo cliente" da janela de Cadastro (desenho 1.2.3, `cadAbrir` sem cadastro; E12i).
 *
 * História: a pasta foi aberta só com o nome do cliente e a ação. A advogada clica em Cadastro, a janela abre como
 * novo cadastro com o nome da pasta já escrito; ela confere, digita o CPF e salva. O cliente é criado no cadastro do
 * escritório, ligado à pasta (principal, se a pasta ainda não tinha) e o Registro ganha "Cadastro do cliente:
 * cadastro de FULANO criado.", e o Histórico do cadastro nasce com "<quem> criou o cadastro". Como no editar,
 * pendência não impede: só nome e CPF/CNPJ válido são exigidos, e CPF de outro cadastro do escritório recusa
 * oferecendo usar o existente. Precisa poder EDITAR a pasta (a mesma regra do "Cadastrar cliente" de sempre).
 */
final class CriarClienteDaPastaUseCase
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly CamposDoCadastro $campos,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
        private readonly HistoricoDoCliente $historico,
    ) {
    }

    /** @param array<string, string> $campos */
    public function executar(Pasta $pasta, string $tipo, array $campos, User $usuario, Tenant $tenant): Cliente
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }
        if ($pasta->estaExcluida()) {
            throw new \DomainException('Pasta excluída não recebe cliente novo.');
        }

        $cliente = $tipo === 'pj' ? self::pjEmBranco() : self::pfEmBranco();
        $cliente->setTenant($tenant);
        $cliente->setCriadoPor($usuario);
        // Nome e documento são exigidos mesmo que não venham no envio (vazio = "Informe…").
        $obrigatorios = $tipo === 'pj' ? ['razao_social' => '', 'cnpj' => ''] : ['nome' => '', 'cpf' => ''];
        $this->campos->aplicar($cliente, $campos + $obrigatorios, $tenant);

        $this->em->persist($cliente);
        $pasta->addCliente($cliente);
        $this->historico->cadastroCriado($cliente, $usuario, $tenant);
        $this->registro->registrar($pasta, $usuario, 'cadastro', 'cadastro de ' . $cliente->getNomeExibicao() . ' criado.');
        $this->em->flush();

        return $cliente;
    }

    private static function pfEmBranco(): ClientePF
    {
        $c = new ClientePF();
        $c->setNomeCompleto('')->setCpf('')->setRg('')->setRgOrgaoExpedidor('');
        $c->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('');

        return $c;
    }

    private static function pjEmBranco(): ClientePJ
    {
        $c = new ClientePJ();
        $c->setRazaoSocial('')->setCnpj('')->setEnderecSede('')->setRepresentanteLegal('')
            ->setRepresentanteCpf('')->setRepresentanteRg('')->setRepresentanteCargo('');
        $c->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('');

        return $c;
    }
}
