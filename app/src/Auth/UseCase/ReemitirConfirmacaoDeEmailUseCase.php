<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\ReemissaoDeConfirmacaoOutput;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gera o link do lembrete único de "e-mail não confirmado" para a varredura do Comercial (5d), que escolhe as contas
 * por UserRepository::semConfirmacaoDeEmailParaLembrete e envia com o texto dela. Aqui NÃO sai e-mail.
 *
 * O link novo nasce com `lembrete = true`: é ele que tira a conta da fila e barra um segundo lembrete.
 */
final class ReemitirConfirmacaoDeEmailUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $relogio,
    ) {}

    public function executar(int $userId): ReemissaoDeConfirmacaoOutput
    {
        $user = $this->contaPendente($userId);
        $agora = $this->relogio->now();
        $token = bin2hex(random_bytes(32));

        $this->confirmacaoEmailRepository->salvar(new ConfirmacaoEmail(
            user: $user,
            tokenHash: ConfirmacaoEmail::hashDoToken($token),
            expiraEm: $agora->modify(ConfirmacaoEmail::VALIDADE),
            lembrete: true,
            criadoEm: $agora,
        ), flush: true);

        return new ReemissaoDeConfirmacaoOutput(
            email: (string) $user->getEmail(),
            nome: (string) $user->getFullName(),
            url: $this->urlGenerator->generate(
                'auth_cadastro_confirmar_email',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );
    }

    private function contaPendente(int $userId): User
    {
        $user = $this->userRepository->find($userId);

        if ($user === null) {
            throw new \DomainException('Conta não encontrada.');
        }

        if ($user->isEmailConfirmado()) {
            throw new \DomainException('O e-mail desta conta já está confirmado.');
        }

        if ($this->confirmacaoEmailRepository->existeLembreteDe($user)) {
            throw new \DomainException('O lembrete desta conta já foi enviado.');
        }

        return $user;
    }
}
