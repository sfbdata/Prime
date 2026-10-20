<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ChavesDoEnvioAutomatico;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use Psr\Clock\ClockInterface;

/**
 * Um switch do bloco "Envio automático" do cartão Avisar cliente (spec push-envio-automatico §2;
 * desenho `autos.alternar`, dc N:5270).
 *
 * História: quem edita a pasta decide que o cliente dela passa a receber (ou deixa de receber) por
 * e-mail, sozinho, as novas movimentações, as audiências e intimações ou o resumo semanal. A chave é
 * DO CLIENTE (vale em todas as pastas de que ele é o cliente principal), como a lista de e-mails.
 *
 *   1. a pasta tem cliente principal do escritório (senão 422 "A pasta não tem cliente vinculado.");
 *   2. recebe o ESTADO DESEJADO: pedir o que já vale não grava nada;
 *   3. LIGAR exige ao menos um e-mail cadastrado do cliente (422 com a dica do desenho, "Cadastre um
 *      e-mail para ativar."; a tela já abre o formulário). Desligar é sempre aceito;
 *   4. a primeira gravação cria a linha dos padrões (D1: tudo desligado) por INSERT idempotente e
 *      registra quem mexeu.
 *
 * A permissão (EDITAR a pasta) e o CSRF são do controller; a lápide, do PastaSomenteLeituraListener.
 */
final class DefinirEnvioAutomaticoDoClienteUseCase
{
    public const ERRO_SEM_EMAIL = 'Cadastre um e-mail para ativar.';

    public function __construct(
        private readonly EmailsDoClienteDaPasta $emails,
        private readonly EnvioAutomaticoDoClienteRepository $automaticos,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws EmailDoClienteRecusadoException pasta sem cliente ou ligar sem e-mail */
    public function executar(Pasta $pasta, ChaveDoEnvioAutomatico $chave, bool $ligada, User $usuario, Tenant $tenant): void
    {
        $caderno = $this->emails->exigirCaderno($pasta, $tenant);
        $cliente = $this->emails->cliente($pasta, $tenant);
        \assert($cliente !== null);

        $atual = $this->automaticos->doClienteDoTenant($cliente, $tenant);
        if (ChavesDoEnvioAutomatico::da($atual)->ligada($chave) === $ligada) {
            return;
        }
        if ($ligada && $caderno->itens() === []) {
            throw new EmailDoClienteRecusadoException(self::ERRO_SEM_EMAIL);
        }

        $agora = $this->relogio->now();
        $linha = $this->automaticos->garantirDoCliente($cliente, $tenant, $usuario, $agora);
        $linha->definir($chave, $ligada, $usuario, $agora);
        $this->automaticos->salvar($linha, true);
    }
}
