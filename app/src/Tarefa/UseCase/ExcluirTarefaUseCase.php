<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Exception\MetaNaoExcluivelException;
use App\Tenant\Enum\PoderSemPrazo;
use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão controlada de uma Meta (Tarefa).
 *
 * Salvaguardas: só o criador da meta pode excluir, e apenas dentro de uma janela
 * curta após a criação. O registro da exclusão no Histórico da pasta (com autor e
 * data/hora) é automático via auditoria (Tarefa é Auditavel).
 *
 * Edição sem prazo (spec `edicao-sem-prazo-master-primario.md`, item 5): o Master e o primário (com a opção do
 * escritório) excluem qualquer meta do escritório, a qualquer momento ({@see PoderDeEdicaoSemPrazo}); o uso do poder
 * fica também na trilha `sp_excluir`, com o papel. A tela pergunta o mesmo (`meta_pode_excluir`, `meta_selo_excluir`).
 */
final class ExcluirTarefaUseCase
{
    /** Janela em que a meta ainda pode ser excluída, contada da criação. */
    private const JANELA_EXCLUSAO = 'PT24H';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PoderDeEdicaoSemPrazo $poder,
        private readonly AuditoriaDaEdicaoSemPrazo $auditoria,
    ) {
    }

    public function podeExcluir(Tarefa $tarefa, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        if ($tarefa->getPasta()->getTenant() !== $tenant) {
            return false;
        }

        return $this->poder->libera($usuario, $tenant, $this->pelaRegraDeSempre($tarefa, $usuario, $agora));
    }

    /** O poder que libera a exclusão quando a regra de sempre não liberaria (o selo da tela). */
    public function seloDaExclusao(Tarefa $tarefa, User $usuario, Tenant $tenant): ?PoderSemPrazo
    {
        if ($tarefa->getPasta()->getTenant() !== $tenant) {
            return null;
        }

        return $this->poder->selo($usuario, $tenant, $this->pelaRegraDeSempre($tarefa, $usuario));
    }

    public function executar(Tarefa $tarefa, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeExcluir($tarefa, $usuario, $tenant)) {
            throw new MetaNaoExcluivelException('Esta meta não pode ser excluída.');
        }
        $this->auditoria->registrar(AuditoriaDaEdicaoSemPrazo::EXCLUIR, $usuario, $tenant, $this->pelaRegraDeSempre($tarefa, $usuario), Tarefa::class, $tarefa->getId(), $tarefa->getCriadoPor()?->getId());

        $this->em->remove($tarefa);
        $this->em->flush();
    }

    /** A regra de sempre: o criador, nas 24 horas depois de criar. */
    private function pelaRegraDeSempre(Tarefa $tarefa, User $usuario, ?\DateTimeImmutable $agora = null): bool
    {
        $criador = $tarefa->getCriadoPor();
        if ($criador === null) {
            return false;
        }
        $mesmaPessoa = $criador === $usuario
            || ($criador->getId() !== null && $criador->getId() === $usuario->getId());

        return $mesmaPessoa && ($agora ?? new \DateTimeImmutable()) <= $tarefa->getDataCriacao()->add(new \DateInterval(self::JANELA_EXCLUSAO));
    }
}
