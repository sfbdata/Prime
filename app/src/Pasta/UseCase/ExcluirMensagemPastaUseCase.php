<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\MensagemPastaNaoExcluivelException;
use App\Pasta\Service\PrazoDoComentario;
use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\Repository\NotificacaoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão controlada de uma mensagem do chat da pasta.
 *
 * Salvaguardas: só o autor da mensagem pode excluí-la, e apenas dentro de uma
 * janela curta após a criação (`JanelaDeEdicaoDeComentario`, 15 minutos) — o suficiente para desfazer um engano.
 * Edição sem prazo (spec edicao-sem-prazo §1): o Master e o primário (com a opção do escritório) passam por cima
 * do prazo e da autoria, pelo {@see PrazoDoComentario}; o uso do poder fica na auditoria.
 *
 * NOT-01: os avisos que a mensagem gerou (menção `TIPO_PASTA_MENCAO_REGISTRO`, resposta
 * `TIPO_PASTA_RESPOSTA_REGISTRO` e pasta acompanhada `TIPO_PASTA_ACOMPANHADA`, todos com link
 * `pasta_show#pasta-msg-<id>`) saem junto, na
 * MESMA transação — senão a prévia do texto apagado continuaria no sino e na Timeline de quem
 * recebeu. Só os deste escritório e só os da âncora exata (`#pasta-msg-12` não leva o `-123`).
 * As respostas a esta mensagem continuam (a FK é SET NULL), e os avisos delas também.
 */
final class ExcluirMensagemPastaUseCase
{
    /** Os avisos cujo link aponta para a mensagem (`#pasta-msg-<id>`). */
    public const TIPOS_DE_AVISO_DA_MENSAGEM = [
        Notificacao::TIPO_PASTA_MENCAO_REGISTRO,
        Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO,
        // "Acompanhar alterações": o aviso leva à mensagem; sem ela, o link ficaria quebrado.
        Notificacao::TIPO_PASTA_ACOMPANHADA,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrazoDoComentario $prazo,
        private readonly NotificacaoRepository $notificacoes,
    ) {}

    public function podeExcluir(PastaMensagem $mensagem, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($mensagem->getTenant() !== $tenant) {
            return false;
        }

        if ($mensagem->isDoSistema()) {
            return false;
        }

        // Autor dentro da janela, OU o poder sem prazo (Master; primário com a opção ligada).
        return $this->prazo->libera($usuario, $tenant, $mensagem->pertenceAo($usuario), $mensagem->getCriadaEm(), $agora);
    }

    public function executar(PastaMensagem $mensagem, User $usuario, Tenant $tenant): void
    {
        $this->exigir($mensagem, $usuario, $tenant);

        // O id sai ANTES do remove: depois do flush o Doctrine o zera na entidade.
        $id = $mensagem->getId();

        $this->em->wrapInTransaction(function () use ($mensagem, $tenant, $id): void {
            $this->em->remove($mensagem);
            $this->em->flush();

            if ($id !== null) {
                $this->notificacoes->excluirPelaAncoraDoLink($tenant, self::TIPOS_DE_AVISO_DA_MENSAGEM, '#pasta-msg-' . $id);
            }
        });
    }

    /** A checagem do podeExcluir() e, quando foi o poder sem prazo que liberou, o rastro na auditoria (§6). */
    private function exigir(PastaMensagem $mensagem, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($mensagem, $usuario, $tenant)) {
            throw new MensagemPastaNaoExcluivelException('Esta mensagem não pode ser excluída.');
        }
        $this->prazo->registrar(AuditoriaDaEdicaoSemPrazo::EXCLUIR, $usuario, $tenant, $mensagem->pertenceAo($usuario), $mensagem->getCriadaEm(), $mensagem, $mensagem->getAutor());
    }
}
