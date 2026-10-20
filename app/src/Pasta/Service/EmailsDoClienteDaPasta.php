<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\EmailDoClienteEmConflitoException;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Cliente\Repository\ClienteEmailRepository;
use App\Cliente\Service\CadernoDeEmailsDoCliente;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * O caderno de e-mails do cliente DA PASTA (o cliente principal; spec push-avisar-cliente §1: a lista
 * é por cliente e vale em todas as pastas dele). Confere que pasta e cliente são do escritório: nada
 * aqui depende do TenantFilter.
 *
 * As escritas passam por {@see self::alterar()}: transação com o cliente travado (FOR UPDATE) ANTES de
 * ler o caderno, o principal antigo desmarcado antes do flush (índice único parcial) e a violação de
 * UNIQUE traduzida em {@see EmailDoClienteEmConflitoException} (409).
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

    /** Leitura (sem trava): o cartão e a conferência dos destinatários do envio. */
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

    /**
     * Uma escrita nos e-mails do cliente, inteira numa transação com o cliente travado.
     *
     * @template T
     *
     * @param callable(CadernoDeEmailsDoCliente): T $operacao
     *
     * @return T
     *
     * @throws EmailDoClienteRecusadoException    pasta sem cliente (ou a regra que a operação lançar)
     * @throws EmailDoClienteEmConflitoException o banco recusou por UNIQUE (corrida)
     */
    public function alterar(Pasta $pasta, Tenant $tenant, callable $operacao): mixed
    {
        $cliente = $this->cliente($pasta, $tenant) ?? throw new EmailDoClienteRecusadoException(self::ERRO_SEM_CLIENTE);

        try {
            return $this->emails->comClienteTravado($cliente, function () use ($tenant, $cliente, $operacao): mixed {
                $caderno = new CadernoDeEmailsDoCliente($tenant, $cliente, $this->emails->listarDoCliente($tenant, $cliente));
                $resultado = $operacao($caderno);
                $this->emails->limparPrincipaisExceto($cliente, $caderno->principal()?->getId());
                $this->emails->gravar($caderno->novas(), $caderno->apagadas());

                return $resultado;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new EmailDoClienteEmConflitoException($e);
        }
    }
}
