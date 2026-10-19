<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * O clique no link de confirmação do e-mail de uma conta que já existe (desenho 10). Idempotente: o segundo clique,
 * em qualquer link da conta, é sucesso sem novo evento. EmailDaContaConfirmado sai depois do commit, uma vez só.
 */
final class ConfirmarEmailDaContaUseCase
{
    public const ERRO_LINK = 'Este link de confirmação é inválido ou expirou.';

    public function __construct(
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $relogio,
    ) {}

    public function executar(string $token): void
    {
        $confirmacao = $this->confirmacaoEmailRepository->encontrarPorToken($token);

        if ($confirmacao === null) {
            throw new \DomainException(self::ERRO_LINK);
        }

        $user = $confirmacao->getUser();

        // Já confirmado vem antes do vencimento: quem clica de novo depois de 7 dias ouve a verdade (está confirmado).
        if ($user->isEmailConfirmado()) {
            return;
        }

        $agora = $this->relogio->now();

        if ($confirmacao->isExpirado($agora)) {
            throw new \DomainException(self::ERRO_LINK);
        }

        $user->confirmarEmail();
        $confirmacao->marcarUsado($agora);
        $this->confirmacaoEmailRepository->salvar($confirmacao, flush: true);
        $this->despacharEvento((int) $user->getId());
    }

    /** O e-mail já está confirmado no banco: um ouvinte com defeito não pode virar erro na tela. */
    private function despacharEvento(int $userId): void
    {
        try {
            $this->eventDispatcher->dispatch(new EmailDaContaConfirmado($userId));
        } catch (\Throwable $e) {
            $this->logger->error('Um ouvinte de EmailDaContaConfirmado falhou.', ['exception' => $e, 'userId' => $userId]);
        }
    }
}
