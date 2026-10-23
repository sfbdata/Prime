<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\PrazoDoComentario;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Exception\NotaTecnicaNaoExcluivelException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\Repository\NotificacaoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão controlada de uma nota técnica — apaga de fato, como as observações da pasta
 * (`ExcluirMensagemPastaUseCase`): não há lápide porque a janela é de 15 minutos e serve só para
 * desfazer um engano recém-cometido.
 *
 * Salvaguardas: só o autor, dentro da janela (`JanelaDeEdicaoDeComentario`).
 *
 * NOT-01: os avisos de menção da nota (`TIPO_NOTA_TECNICA_MENCAO`, link
 * `pasta_show#nota-tecnica-<id>`) saem junto, na MESMA transação, só deste escritório e só os da
 * âncora exata (`#nota-tecnica-12` não leva o `-123`). A nota é do processo, então o aviso pode
 * apontar para qualquer pasta que o vincule — por isso a âncora, e não a pasta, é o critério.
 */
final class ExcluirNotaTecnicaUseCase
{
    public function __construct(
        private readonly NotaTecnicaRepository $repository,
        private readonly PrazoDoComentario $prazo,
        private readonly NotificacaoRepository $notificacoes,
        private readonly EntityManagerInterface $em,
    ) {}

    public function podeExcluir(NotaTecnica $nota, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($nota->getTenant() !== $tenant) {
            return false;
        }

        // Autor dentro da janela, OU o poder sem prazo (Master; primário com a opção ligada).
        return $this->prazo->libera($usuario, $tenant, $nota->pertenceAo($usuario), $nota->getCriadaEm(), $agora);
    }

    public function executar(NotaTecnica $nota, User $usuario, Tenant $tenant): void
    {
        $this->exigir($nota, $usuario, $tenant);

        // O id sai ANTES do remove: depois do flush o Doctrine o zera na entidade.
        $id = $nota->getId();

        $this->em->wrapInTransaction(function () use ($nota, $tenant, $id): void {
            $this->repository->remover($nota, flush: true);

            if ($id !== null) {
                $this->notificacoes->excluirPelaAncoraDoLink($tenant, [Notificacao::TIPO_NOTA_TECNICA_MENCAO], '#nota-tecnica-' . $id);
            }
        });
    }

    /** A checagem do podeExcluir() e, quando foi o poder sem prazo que liberou, o rastro na auditoria. */
    private function exigir(NotaTecnica $nota, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($nota, $usuario, $tenant)) {
            throw new NotaTecnicaNaoExcluivelException('Esta nota técnica não pode ser excluída.');
        }
        $this->prazo->registrar(AuditoriaDaEdicaoSemPrazo::EXCLUIR, $usuario, $tenant, $nota->pertenceAo($usuario), $nota->getCriadaEm(), $nota, $nota->getAutor());
    }
}
