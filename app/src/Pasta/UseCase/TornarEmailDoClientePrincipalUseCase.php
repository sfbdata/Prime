<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Exception\EmailDoClienteNaoEncontradoException;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use Psr\Clock\ClockInterface;

/**
 * A estrela "Tornar principal" do cartão Avisar cliente (desenho `principal`, dc L.5263): um só
 * principal por cliente. O item virtual do cadastro (id 0) vira linha antes.
 */
final class TornarEmailDoClientePrincipalUseCase
{
    public function __construct(
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws EmailDoClienteRecusadoException     pasta sem cliente
     * @throws EmailDoClienteNaoEncontradoException id que não é deste cliente
     */
    public function executar(Pasta $pasta, int $emailId, User $usuario, Tenant $tenant): void
    {
        $caderno = $this->emails->exigirCaderno($pasta, $tenant);
        $caderno->tornarPrincipal($caderno->item($emailId, $usuario, $this->relogio->now()));
        $this->emails->gravar($caderno, true);
    }
}
