<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Enum\ReenvioDeConfirmacao;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Service\CadastroMailer;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * "Reenviar o link de confirmação" pedido pela própria pessoa (desenho 10, junto da recusa por e-mail a confirmar).
 * Grava um link novo com a mesma validade e o mesmo token do cadastro e envia pelo CadastroMailer.
 *
 * Diferente do ReemitirConfirmacaoDeEmailUseCase (lembrete automático único da 5d, que não envia nada): aqui o link
 * nasce com `lembrete = false`, então não gasta o lembrete da conta. O teto de frequência é do controller, não daqui.
 * Os links anteriores continuam valendo até vencer: qualquer um deles confirma a conta.
 */
final class ReenviarConfirmacaoDeEmailUseCase
{
    public function __construct(
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly CadastroMailer $cadastroMailer,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $user): ReenvioDeConfirmacao
    {
        if ($user->isEmailConfirmado()) {
            return ReenvioDeConfirmacao::JaConfirmado;
        }

        $agora = $this->relogio->now();
        $token = bin2hex(random_bytes(32));
        $this->confirmacaoEmailRepository->salvar(new ConfirmacaoEmail(
            user: $user,
            tokenHash: ConfirmacaoEmail::hashDoToken($token),
            expiraEm: $agora->modify(ConfirmacaoEmail::VALIDADE),
            criadoEm: $agora,
        ), flush: true);

        try {
            $this->cadastroMailer->enviarConfirmacaoDeEmail($user, $token);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao reenviar o e-mail de confirmação da conta.', [
                'exception' => $e,
                'userId' => $user->getId(),
            ]);

            return ReenvioDeConfirmacao::FalhaNoEnvio;
        }

        return ReenvioDeConfirmacao::Enviado;
    }
}
