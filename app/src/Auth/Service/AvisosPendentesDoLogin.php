<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\User;
use Psr\Log\LoggerInterface;

/**
 * Fila em memória dos avisos de acesso novo da requisição de login (spec `login-aviso-novo.md`). O UseCase agenda; o
 * `EnviarAvisosPendentesDoLoginSubscriber` envia no `kernel.terminate`, depois da resposta, para o SMTP lento não
 * segurar o login. Falha de envio só vai para o log.
 */
final class AvisosPendentesDoLogin
{
    /** @var list<array{User, \DateTimeImmutable, NavegadorDoAcesso}> */
    private array $fila = [];

    public function __construct(
        private readonly AvisoDeAcessoNovoMailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function agendar(User $user, \DateTimeImmutable $quando, NavegadorDoAcesso $navegador): void
    {
        $this->fila[] = [$user, $quando, $navegador];
    }

    /** Envia e esvazia a fila. Nunca lança. */
    public function enviar(): void
    {
        $fila = $this->fila;
        $this->fila = [];
        foreach ($fila as [$user, $quando, $navegador]) {
            try {
                $this->mailer->avisar($user, $quando, $navegador);
            } catch (\Throwable $e) {
                $this->logger->error('Aviso de acesso novo: o e-mail falhou, o login já seguiu.', ['exception' => $e]);
            }
        }
    }
}
