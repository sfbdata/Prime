<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Exception\ObservacaoFinanceiraNaoExcluivelException;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Repository\NotificacaoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão controlada de uma observação da aba Financeiro.
 *
 * Salvaguardas: só o autor da observação pode excluí-la, e apenas dentro de uma
 * janela curta após a criação (`JanelaDeEdicaoDeComentario`, 15 minutos) — o suficiente para desfazer um engano.
 *
 * Como no Registro (NOT-01): o "respondeu seu comentário" que ESTE registro gerou (link
 * `pasta_show#financeiro-obs-<id>`) sai junto, na mesma transação — senão a prévia do texto
 * apagado continuaria no sino. Só os deste escritório e só os da âncora exata. As respostas a
 * este registro continuam (a FK é SET NULL), e os avisos delas também.
 */
final class ExcluirObservacaoFinanceiraUseCase
{
    /** Os avisos cujo link aponta para o registro (`#financeiro-obs-<id>`). */
    public const TIPOS_DE_AVISO_DO_REGISTRO = [
        Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JanelaDeEdicaoDeComentario $janela,
        private readonly NotificacaoRepository $notificacoes,
    ) {}

    public function podeExcluir(PastaObservacaoFinanceira $observacao, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($observacao->getTenant() !== $tenant) {
            return false;
        }

        if (!$observacao->pertenceAo($usuario)) {
            return false;
        }

        return $this->janela->estaAberta($observacao->getCriadaEm(), $agora);
    }

    public function executar(PastaObservacaoFinanceira $observacao, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($observacao, $usuario, $tenant)) {
            throw new ObservacaoFinanceiraNaoExcluivelException('Esta observação não pode ser excluída.');
        }

        // O id sai ANTES do remove: depois do flush o Doctrine o zera na entidade.
        $id = $observacao->getId();

        $this->em->wrapInTransaction(function () use ($observacao, $tenant, $id): void {
            $this->em->remove($observacao);
            $this->em->flush();

            if ($id !== null) {
                $this->notificacoes->excluirPelaAncoraDoLink($tenant, self::TIPOS_DE_AVISO_DO_REGISTRO, '#financeiro-obs-' . $id);
            }
        });
    }
}
