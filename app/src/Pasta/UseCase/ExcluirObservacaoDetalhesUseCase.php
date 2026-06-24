<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Exception\ObservacaoDetalhesNaoExcluivelException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão controlada de uma observação da aba Detalhes.
 *
 * Salvaguardas: só o autor da observação pode excluí-la, e apenas dentro de uma
 * janela curta após a criação — o suficiente para desfazer um engano.
 */
final class ExcluirObservacaoDetalhesUseCase
{
    /** Janela em que a observação ainda pode ser excluída, contada da criação. */
    private const JANELA = 'PT24H';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function podeExcluir(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($observacao->getTenant() !== $tenant) {
            return false;
        }

        if (!$observacao->pertenceAo($usuario)) {
            return false;
        }

        $limite = $observacao->getCriadaEm()->add(new \DateInterval(self::JANELA));

        return $agora <= $limite;
    }

    public function executar(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($observacao, $usuario, $tenant)) {
            throw new ObservacaoDetalhesNaoExcluivelException('Esta observação não pode ser excluída.');
        }

        $this->em->remove($observacao);
        $this->em->flush();
    }
}
