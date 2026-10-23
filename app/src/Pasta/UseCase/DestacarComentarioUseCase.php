<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\DestaqueDoComentarioOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaComentarioDestaque;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Exception\DestaqueDeComentarioRecusadoException;
use App\Pasta\Repository\PastaComentarioDestaqueRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Destacar comentário" (spec pasta-destacar-comentario.md; dc02 L4593-4607). Quem pode editar a pasta escolhe uma
 * cor para um comentário de Dados, Detalhes ou Financeiro; o último destaque da aba prevalece e o anterior é desfeito.
 * Sem cor, tira o destaque daquele comentário (se for ele o destacado). A aba oculta para quem pede é recusada como na
 * barreira (a aba vem no corpo, não no nome da rota). O comentário é lido já com a pasta travada: excluído no meio
 * do caminho, vira 404 em vez de estourar a FK.
 */
final class DestacarComentarioUseCase
{
    private const CLASSES = [
        PastaComentarioDestaque::ABA_DADOS => PastaMensagem::class,
        PastaComentarioDestaque::ABA_DETALHES => PastaObservacaoDetalhes::class,
        PastaComentarioDestaque::ABA_FINANCEIRO => PastaObservacaoFinanceira::class,
    ];

    public function __construct(
        private readonly PastaComentarioDestaqueRepository $destaques,
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissoes,
        private readonly VisibilidadeDasAbasDaPasta $visibilidade,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(Pasta $pasta, string $aba, int $comentarioId, ?string $cor, User $quem, Tenant $tenant): DestaqueDoComentarioOutput
    {
        $this->exigirPastaDoEscritorio($pasta, $tenant);
        $this->exigirEdicao($pasta, $quem, $tenant);
        $this->exigirAbaVisivel($pasta, $aba, $quem);
        $cor = $cor === null || trim($cor) === '' ? null : $this->corValida($cor);

        /** @var DestaqueDoComentarioOutput|null $resultado */
        $resultado = $this->destaques->comTravaDaPasta($pasta, function () use ($pasta, $aba, $comentarioId, $cor, $quem, $tenant): ?DestaqueDoComentarioOutput {
            $comentario = $this->comentarioDaPasta($aba, $comentarioId, $pasta, $tenant);
            if ($comentario === null) {
                return null;
            }

            return $cor === null
                ? $this->tirar($pasta, $aba, $comentario, $tenant)
                : $this->destacar($pasta, $aba, $comentario, $cor, $quem, $tenant);
        });

        // Fora da transação: uma exceção dentro dela fecharia o EntityManager.
        return $resultado ?? throw DestaqueDeComentarioRecusadoException::naoEncontrado();
    }

    private function destacar(Pasta $pasta, string $aba, PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $comentario, string $cor, User $quem, Tenant $tenant): DestaqueDoComentarioOutput
    {
        $atual = $this->destaques->daAba($pasta, $aba, $tenant);
        if ($atual === null) {
            $this->destaques->salvar(new PastaComentarioDestaque($pasta, $comentario, $cor, $quem, $this->relogio->now()));

            return new DestaqueDoComentarioOutput($cor);
        }
        $anterior = $atual->ehDo($comentario) ? null : $atual->comentario();
        $atual->apontarPara($comentario, $cor, $quem, $this->relogio->now());
        $this->destaques->salvar($atual);

        return new DestaqueDoComentarioOutput($cor, $anterior?->getId(), $anterior?->getAutor()?->getFullName());
    }

    private function tirar(Pasta $pasta, string $aba, PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira $comentario, Tenant $tenant): DestaqueDoComentarioOutput
    {
        $atual = $this->destaques->daAba($pasta, $aba, $tenant);
        if ($atual !== null && $atual->ehDo($comentario)) {
            $this->destaques->remover($atual);
        }

        return new DestaqueDoComentarioOutput(null);
    }

    private function exigirPastaDoEscritorio(Pasta $pasta, Tenant $tenant): void
    {
        if ($pasta->getTenant()?->getId() !== $tenant->getId()) {
            throw DestaqueDeComentarioRecusadoException::naoEncontrado();
        }
    }

    /** EDIT na pasta, que já passa pelo cadeado (`PermissionChecker::canAccessResource`). */
    private function exigirEdicao(Pasta $pasta, User $quem, Tenant $tenant): void
    {
        if (!$this->permissoes->canAccessResource($quem, $tenant, 'pasta', (int) $pasta->getId(), 'edit')) {
            throw DestaqueDeComentarioRecusadoException::semPermissao();
        }
    }

    private function exigirAbaVisivel(Pasta $pasta, string $aba, User $quem): void
    {
        if (!isset(self::CLASSES[$aba])) {
            throw DestaqueDeComentarioRecusadoException::invalido('Esta aba não tem comentários para destacar.');
        }
        $daPasta = AbaDaPasta::from($aba);
        if ($this->visibilidade->ocultaPara($pasta, $daPasta, $quem)) {
            throw DestaqueDeComentarioRecusadoException::abaOculta($daPasta->rotulo());
        }
    }

    private function comentarioDaPasta(string $aba, int $id, Pasta $pasta, Tenant $tenant): PastaMensagem|PastaObservacaoDetalhes|PastaObservacaoFinanceira|null
    {
        $comentario = $this->em->getRepository(self::CLASSES[$aba])->findOneBy(['id' => $id, 'pasta' => $pasta, 'tenant' => $tenant]);

        return $comentario instanceof PastaMensagem || $comentario instanceof PastaObservacaoDetalhes || $comentario instanceof PastaObservacaoFinanceira
            ? $comentario
            : null;
    }

    private function corValida(string $cor): string
    {
        try {
            return PastaComentarioDestaque::corValida($cor);
        } catch (\InvalidArgumentException) {
            throw DestaqueDeComentarioRecusadoException::invalido('Escolha uma das cores da paleta.');
        }
    }
}
