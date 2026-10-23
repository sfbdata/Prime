<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Exception\ObservacaoDetalhesNaoExcluivelException;
use App\Pasta\Service\PrazoDoComentario;
use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\Repository\NotificacaoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão controlada de uma observação da aba Detalhes.
 *
 * Salvaguardas: só o autor da observação pode excluí-la, e apenas dentro de uma
 * janela curta após a criação (`JanelaDeEdicaoDeComentario`, 15 minutos) — o suficiente para desfazer um engano.
 * Edição sem prazo (spec edicao-sem-prazo §1): o Master e o primário (com a opção do escritório) passam por cima
 * do prazo e da autoria, pelo {@see PrazoDoComentario}; o uso do poder fica na auditoria.
 *
 * Como no Registro (NOT-01): a @menção e o "respondeu seu comentário" que ESTE registro gerou (link
 * `pasta_show#detalhes-obs-<id>`) saem junto, na mesma transação — senão a prévia do texto
 * apagado continuaria no sino. Só os deste escritório e só os da âncora exata. As respostas a
 * este registro continuam (a FK é SET NULL), e os avisos delas também.
 */
final class ExcluirObservacaoDetalhesUseCase
{
    /** Os avisos cujo link aponta para o registro (`#detalhes-obs-<id>`). */
    public const TIPOS_DE_AVISO_DO_REGISTRO = [
        Notificacao::TIPO_PASTA_MENCAO_REGISTRO,
        Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrazoDoComentario $prazo,
        private readonly NotificacaoRepository $notificacoes,
    ) {}

    public function podeExcluir(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($observacao->getTenant() !== $tenant) {
            return false;
        }

        // Autor dentro da janela, OU o poder sem prazo (Master; primário com a opção ligada).
        return $this->prazo->libera($usuario, $tenant, $observacao->pertenceAo($usuario), $observacao->getCriadaEm(), $agora);
    }

    public function executar(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant): void
    {
        $this->exigir($observacao, $usuario, $tenant);

        // O id sai ANTES do remove: depois do flush o Doctrine o zera na entidade.
        $id = $observacao->getId();

        $this->em->wrapInTransaction(function () use ($observacao, $tenant, $id): void {
            $this->em->remove($observacao);
            $this->em->flush();

            if ($id !== null) {
                $this->notificacoes->excluirPelaAncoraDoLink($tenant, self::TIPOS_DE_AVISO_DO_REGISTRO, '#detalhes-obs-' . $id);
            }
        });
    }

    /** A checagem do podeExcluir() e, quando foi o poder sem prazo que liberou, o rastro na auditoria (§6). */
    private function exigir(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($observacao, $usuario, $tenant)) {
            throw new ObservacaoDetalhesNaoExcluivelException('Esta observação não pode ser excluída.');
        }
        $this->prazo->registrar(AuditoriaDaEdicaoSemPrazo::EXCLUIR, $usuario, $tenant, $observacao->pertenceAo($usuario), $observacao->getCriadaEm(), $observacao, $observacao->getAutor());
    }
}
