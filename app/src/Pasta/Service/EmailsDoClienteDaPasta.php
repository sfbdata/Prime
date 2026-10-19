<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Cliente\Repository\ClienteEmailRepository;
use App\Cliente\Service\CadernoDeEmailsDoCliente;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * O caderno de e-mails do cliente DA PASTA (o cliente principal; spec push-avisar-cliente §1: a lista
 * é por cliente e vale em todas as pastas dele). Confere que pasta e cliente são do escritório: nada
 * aqui depende do TenantFilter.
 */
final class EmailsDoClienteDaPasta
{
    public const ERRO_SEM_CLIENTE = 'A pasta não tem cliente vinculado.';

    public function __construct(
        private readonly ClienteEmailRepository $emails,
    ) {
    }

    /** O cliente principal da pasta, se for do escritório. */
    public function cliente(Pasta $pasta, Tenant $tenant): ?Cliente
    {
        $cliente = $pasta->getTenant() === $tenant ? $pasta->getClientePrincipal() : null;

        return $cliente !== null && $cliente->getTenant() === $tenant ? $cliente : null;
    }

    public function caderno(Pasta $pasta, Tenant $tenant): ?CadernoDeEmailsDoCliente
    {
        $cliente = $this->cliente($pasta, $tenant);

        return $cliente === null ? null : new CadernoDeEmailsDoCliente($tenant, $cliente, $this->emails->listarDoCliente($tenant, $cliente));
    }

    /** @throws EmailDoClienteRecusadoException pasta sem cliente */
    public function exigirCaderno(Pasta $pasta, Tenant $tenant): CadernoDeEmailsDoCliente
    {
        return $this->caderno($pasta, $tenant) ?? throw new EmailDoClienteRecusadoException(self::ERRO_SEM_CLIENTE);
    }

    public function gravar(CadernoDeEmailsDoCliente $caderno, bool $flush): void
    {
        $this->emails->gravar($caderno->novas(), $caderno->apagadas(), $flush);
    }
}
