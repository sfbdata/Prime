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
use App\Pasta\Service\EnvioAoCliente\EnvioDeEmailAoClienteInterface;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Psr\Clock\ClockInterface;

/**
 * O switch "Recebendo avisos" / "Pausado" de um e-mail do cliente (desenho `alternar`, dc L.5264).
 * Recebe o ESTADO DESEJADO (o clique repetido não desfaz o primeiro).
 *
 * E-mail descadastrado pelo cliente (o "Não quero mais receber" do #87, assunto `push_cliente`, escopo
 * o escritório) fica TRAVADO: o escritório não religa nem mexe; só o cliente, por novo consentimento.
 */
final class DefinirAvisosDoEmailDoClienteUseCase
{
    public const ERRO_DESCADASTRADO = 'O cliente pediu para não receber mais estes avisos. Só ele pode voltar a receber.';

    public function __construct(
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws EmailDoClienteRecusadoException     pasta sem cliente ou e-mail descadastrado
     * @throws EmailDoClienteNaoEncontradoException id que não é deste cliente
     * @throws EmailDoClienteEmConflitoException    outra tela gravou ao mesmo tempo
     */
    public function executar(Pasta $pasta, int $emailId, bool $recebe, User $usuario, Tenant $tenant): void
    {
        $agora = $this->relogio->now();
        $this->emails->alterar($pasta, $tenant, function (CadernoDeEmailsDoCliente $caderno) use ($emailId, $recebe, $usuario, $tenant, $agora): void {
            $item = $caderno->item($emailId, $usuario, $agora);
            if ($this->descadastro->estaDescadastrado($tenant, $item->getEmail(), EnvioDeEmailAoClienteInterface::ASSUNTO_DO_DESCADASTRO)) {
                throw new EmailDoClienteRecusadoException(self::ERRO_DESCADASTRADO);
            }
            $item->definirRecebimento($recebe);
        });
    }
}
