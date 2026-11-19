<?php

declare(strict_types=1);

namespace App\Cliente\UseCase;

use App\Cliente\Entity\Cliente;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Shared\Service\DigitoVerificador;

/**
 * O CPF ou CNPJ já é de um cadastro do escritório que a pessoa vê? É o aviso ao sair do campo de documento no cadastro
 * (`cliente_cadastro_documento`, `pasta_cadastro_novo_documento`, `cliente_cadastro_novo_documento`) e a ação
 * `conferir_documento` da ferramenta `ler_clientes` do conector (spec mcp-remoto-oauth, 6.4).
 *
 * Aceita o documento com ou sem máscara; só 11 dígitos (CPF) ou 14 (CNPJ) procuram. A busca filtra pelo escritório
 * dado, sem depender do TenantFilter. O achado só volta se não for o próprio cliente que está sendo editado e se a pessoa
 * pode VER o cliente achado (`canAccessResource('cliente', view)`): quem não vê recebe nulo, como se não houvesse.
 *
 * Não confere a guarda da rota (o cliente aberto, a pasta, a capacidade de cadastrar): quem chama confere antes.
 */
final class ConferirDocumentoDoClienteUseCase
{
    public function __construct(
        private readonly ClientePFRepository $pessoasFisicas,
        private readonly ClientePJRepository $pessoasJuridicas,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    public function executar(string $documento, User $quemOlha, Tenant $escritorio, ?int $excetoClienteId = null): ?Cliente
    {
        $digitos = DigitoVerificador::digitos($documento);
        $achado = match (\strlen($digitos)) {
            11 => $this->pessoasFisicas->findOneByCpfDoTenant($digitos, $escritorio),
            14 => $this->pessoasJuridicas->findOneByCnpjDoTenant($digitos, $escritorio),
            default => null,
        };

        if ($achado === null || ($excetoClienteId !== null && $achado->getId() === $excetoClienteId)) {
            return null;
        }

        return $this->permissoes->canAccessResource($quemOlha, $escritorio, AccessRequest::RESOURCE_CLIENTE, (int) $achado->getId(), AccessRequest::ACTION_VIEW)
            ? $achado
            : null;
    }
}
