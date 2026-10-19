<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Enum\RotuloDoEmail;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Cliente\Service\CadernoDeEmailsDoCliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * "+ Adicionar" do cartão Avisar cliente (spec push-avisar-cliente §2; desenho `add`, dc L.5254-5255).
 *
 * História: quem edita a pasta cadastra um e-mail do cliente para mantê-lo informado. Validações na
 * ordem do desenho ("Digite um e-mail válido.", "Este e-mail já está cadastrado.", "Limite de 5
 * e-mails por cliente."); o primeiro vira principal; nasce recebendo avisos. A linha "E-mail
 * cadastrado" entra no histórico de envios da pasta. Devolve o aviso "E-mail cadastrado: {e}".
 *
 * A permissão (EDITAR a pasta) e o CSRF são do controller; a lápide, do PastaSomenteLeituraListener.
 */
final class CadastrarEmailDoClienteUseCase
{
    public function __construct(
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly EnvioAoClienteRepository $envios,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws EmailDoClienteRecusadoException sem cliente, e-mail inválido, repetido ou acima do limite */
    public function executar(Pasta $pasta, string $email, RotuloDoEmail $rotulo, User $usuario, Tenant $tenant): string
    {
        $caderno = $this->emails->exigirCaderno($pasta, $tenant);
        $agora = $this->relogio->now();
        $endereco = $caderno->adicionar($email, $rotulo, $usuario, $agora);

        $this->emails->gravar($caderno, false);
        $this->envios->salvar(new EnvioAoCliente($tenant, $pasta, $this->emails->cliente($pasta, $tenant), TipoDeEnvioAoCliente::Cadastro, [$endereco], $usuario, $agora));
        $this->gravarSemCorrida();

        return 'E-mail cadastrado: ' . $endereco;
    }

    /** Duas abas cadastrando o mesmo endereço ao mesmo tempo: o UNIQUE (cliente, email) decide. */
    private function gravarSemCorrida(): void
    {
        try {
            $this->envios->flush();
        } catch (UniqueConstraintViolationException) {
            throw new EmailDoClienteRecusadoException(CadernoDeEmailsDoCliente::ERRO_REPETIDO);
        }
    }
}
