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
 * O X "Remover e-mail" do cartão Avisar cliente (desenho `remover`, dc L.5265; a tela pergunta antes
 * "Remover {email} dos avisos ao cliente?"). Removido o principal, o primeiro que sobra assume.
 * O e-mail do cadastro só é marcado (continua na ficha do cliente e não volta como item virtual).
 */
final class RemoverEmailDoClienteUseCase
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
        $agora = $this->relogio->now();
        $caderno->remover($caderno->item($emailId, $usuario, $agora), $agora);
        $this->emails->gravar($caderno, true);
    }
}
