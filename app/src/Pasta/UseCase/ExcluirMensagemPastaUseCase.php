<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\MensagemPastaNaoExcluivelException;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão controlada de uma mensagem do chat da pasta.
 *
 * Salvaguardas: só o autor da mensagem pode excluí-la, e apenas dentro de uma
 * janela curta após a criação (`JanelaDeEdicaoDeComentario`, 15 minutos) — o suficiente para desfazer um engano.
 */
final class ExcluirMensagemPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JanelaDeEdicaoDeComentario $janela,
    ) {}

    public function podeExcluir(PastaMensagem $mensagem, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($mensagem->getTenant() !== $tenant) {
            return false;
        }

        if (!$mensagem->pertenceAo($usuario)) {
            return false;
        }

        return $this->janela->estaAberta($mensagem->getCriadaEm(), $agora);
    }

    public function executar(PastaMensagem $mensagem, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($mensagem, $usuario, $tenant)) {
            throw new MensagemPastaNaoExcluivelException('Esta mensagem não pode ser excluída.');
        }

        $this->em->remove($mensagem);
        $this->em->flush();
    }
}
