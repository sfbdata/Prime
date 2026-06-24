<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Exception\ObservacaoDetalhesNaoEditavelException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edição controlada de uma observação da aba Detalhes.
 *
 * Salvaguardas: só o autor da observação pode editá-la, e apenas dentro de uma
 * janela curta após a criação — o suficiente para corrigir erros de escrita.
 */
final class EditarObservacaoDetalhesUseCase
{
    /** Janela em que a observação ainda pode ser editada, contada da criação. */
    private const JANELA = 'PT24H';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function podeEditar(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
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

    public function executar(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant, string $conteudo): void
    {
        if (!$this->podeEditar($observacao, $usuario, $tenant)) {
            throw new ObservacaoDetalhesNaoEditavelException('Esta observação não pode ser editada.');
        }

        $conteudo = trim($conteudo);

        if ($conteudo === '' || mb_strlen($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $observacao->setConteudo($conteudo);
        $observacao->setEditadaEm(new \DateTimeImmutable());

        $this->em->flush();
    }
}
