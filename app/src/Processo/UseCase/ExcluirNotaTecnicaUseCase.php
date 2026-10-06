<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Exception\NotaTecnicaNaoExcluivelException;
use App\Processo\Repository\NotaTecnicaRepository;

/**
 * Exclusão controlada de uma nota técnica — apaga de fato, como as observações da pasta
 * (`ExcluirMensagemPastaUseCase`): não há lápide porque a janela é de 15 minutos e serve só para
 * desfazer um engano recém-cometido.
 *
 * Salvaguardas: só o autor, dentro da janela (`JanelaDeEdicaoDeComentario`).
 */
final class ExcluirNotaTecnicaUseCase
{
    public function __construct(
        private readonly NotaTecnicaRepository $repository,
        private readonly JanelaDeEdicaoDeComentario $janela,
    ) {}

    public function podeExcluir(NotaTecnica $nota, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($nota->getTenant() !== $tenant) {
            return false;
        }

        if (!$nota->pertenceAo($usuario)) {
            return false;
        }

        return $this->janela->estaAberta($nota->getCriadaEm(), $agora);
    }

    public function executar(NotaTecnica $nota, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($nota, $usuario, $tenant)) {
            throw new NotaTecnicaNaoExcluivelException('Esta nota técnica não pode ser excluída.');
        }

        $this->repository->remover($nota, flush: true);
    }
}
