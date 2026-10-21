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
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use Psr\Clock\ClockInterface;

/**
 * O X "Remover e-mail" do cartão Avisar cliente (desenho `remover`, dc L.5265; a tela pergunta antes
 * "Remover {email} dos avisos ao cliente?"). Removido o principal, o primeiro que sobra assume.
 * A linha `do_cadastro` só é marcada (continua na ficha do cliente e não volta como item virtual).
 * Os demais saem do banco e, por LGPD, o endereço vira "e-mail removido" nas linhas "E-mail
 * cadastrado" do histórico desta pasta; os envios reais (Análise IA, automático) ficam como prova.
 */
final class RemoverEmailDoClienteUseCase
{
    public function __construct(
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly EnvioAoClienteRepository $envios,
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
        $this->emails->alterar($pasta, $tenant, function (CadernoDeEmailsDoCliente $caderno) use ($pasta, $emailId, $usuario, $tenant, $agora): void {
            $item = $caderno->item($emailId, $usuario, $agora);
            $caderno->remover($item, $agora);
            if (!$item->ehDoCadastro()) {
                $this->envios->anonimizarCadastroDoEmail($tenant, $pasta, $item->getEmail());
            }
        });
    }
}
