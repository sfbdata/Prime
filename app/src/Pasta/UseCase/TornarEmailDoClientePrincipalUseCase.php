<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Exception\EmailDoClienteEmConflitoException;
use App\Cliente\Exception\EmailDoClienteNaoEncontradoException;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Cliente\Service\CadernoDeEmailsDoCliente;
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
     * @throws EmailDoClienteEmConflitoException    outra tela gravou ao mesmo tempo
     */
    public function executar(Pasta $pasta, int $emailId, User $usuario, Tenant $tenant): void
    {
        $agora = $this->relogio->now();
        $this->emails->alterar($pasta, $tenant, static function (CadernoDeEmailsDoCliente $caderno) use ($emailId, $usuario, $agora): void {
            $caderno->tornarPrincipal($caderno->item($emailId, $usuario, $agora));
        });
    }
}
