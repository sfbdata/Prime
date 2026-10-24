<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Service\PermissionChecker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Qual foto o círculo do cabeçalho da pasta mostra, para quem pede (a mesma regra do
 * `pasta/_cabecalho_foto_cliente.html.twig`, para a resposta JSON da troca e da remoção):
 *
 *  1. a do cliente principal, se ele tem foto e quem pede VÊ o cliente (`origem` = `cliente`);
 *  2. senão a da pasta, se ela tem (`origem` = `pasta`): a pasta sem cliente cadastrado, ou com cliente que ainda
 *     não tem foto própria (vincular o cliente não apaga a foto da pasta);
 *  3. senão nenhuma (o ícone de pessoa).
 */
final class FotoDoCabecalhoDaPasta
{
    public const ORIGEM_CLIENTE = 'cliente';
    public const ORIGEM_PASTA = 'pasta';

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return array{url: ?string, origem: ?string} */
    public function para(Pasta $pasta, User $usuario, Tenant $tenant): array
    {
        $cliente = $pasta->getClientePrincipal();
        if ($cliente !== null && $cliente->getFoto() !== null
            && $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, (int) $cliente->getId(), AccessRequest::ACTION_VIEW)) {
            return ['url' => $this->urls->generate('cliente_foto', [
                'id'   => $cliente->getId(),
                'nome' => $cliente->getFoto(),
                'v'    => $cliente->getFotoAtualizadaEm()?->getTimestamp(),
            ]), 'origem' => self::ORIGEM_CLIENTE];
        }
        if ($pasta->getFoto() !== null) {
            return ['url' => $this->urls->generate('pasta_foto', [
                'id'   => $pasta->getId(),
                'nome' => $pasta->getFoto(),
                'v'    => $pasta->getFotoAtualizadaEm()?->getTimestamp(),
            ]), 'origem' => self::ORIGEM_PASTA];
        }

        return ['url' => null, 'origem' => null];
    }
}
