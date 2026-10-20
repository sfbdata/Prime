<?php

declare(strict_types=1);

namespace App\Agenda\EventListener;

use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Message\SincronizarGoogleDaPessoa;
use App\Agenda\Service\SilencioDaSincronia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Compromisso criado, mudado ou excluído em qualquer tela (Agenda nova, antiga, desfazer da auditoria) =>
 * as agendas Google de quem o criou e dos participantes são sincronizadas logo em seguida, para quem estiver
 * conectado. A exclusão de um compromisso que está na agenda do escritório de alguém fica anotada, por pessoa,
 * para a sincronia apagar lá (a ligação some junto com o compromisso). Anota no onFlush e age no postFlush,
 * depois de o banco aceitar (um flush que falha não apaga nada no Google).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class MudancaNaAgendaParaOGoogleListener
{
    /** Espera curta para juntar várias mudanças seguidas numa sincronia só. */
    private const ESPERA_MS = 3000;

    /** @var array<string, array{usuario: int, tenant: int}> */
    private array $pessoas = [];
    /** @var list<array{usuario: int, tenant: int, google: string}> */
    private array $exclusoes = [];

    public function __construct(
        private readonly GoogleAgendaApi $google,
        private readonly SilencioDaSincronia $silencio,
        private readonly MessageBusInterface $bus,
        private readonly Connection $banco,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        // Um flush que falhou não chega ao postFlush: o que ele anotou não pode vazar para o próximo.
        [$this->pessoas, $this->exclusoes] = [[], []];
        if ($this->silencio->calado() || !$this->google->ligado()) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entidade) {
            if ($entidade instanceof Evento) {
                $this->anotarPessoas($entidade);
            }
        }
        foreach ($uow->getScheduledCollectionUpdates() as $colecao) {
            $dono = $colecao->getOwner();
            if ($dono instanceof Evento) {
                $this->anotarPessoas($dono, $colecao->getDeleteDiff());
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entidade) {
            if ($entidade instanceof Evento && null !== $entidade->getId()) {
                $this->anotarExclusoes((int) $entidade->getId());
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        [$pessoas, $exclusoes] = [$this->pessoas, $this->exclusoes];
        [$this->pessoas, $this->exclusoes] = [[], []];
        foreach ($exclusoes as $e) {
            if (null !== $this->conexaoDe($e['usuario'], $e['tenant'])) {
                $this->banco->executeStatement(
                    'INSERT INTO agenda_google_exclusao (tenant_id, usuario_id, google_id) VALUES (:t, :u, :g) ON CONFLICT (usuario_id, google_id) DO NOTHING',
                    ['t' => $e['tenant'], 'u' => $e['usuario'], 'g' => $e['google']],
                );
            }
        }
        foreach ($pessoas as $p) {
            $conexao = $this->conexaoDe($p['usuario'], $p['tenant']);
            if (null !== $conexao) {
                $this->bus->dispatch(new SincronizarGoogleDaPessoa($conexao), [new DelayStamp(self::ESPERA_MS)]);
            }
        }
    }

    /**
     * O criador, os participantes e quem acabou de sair da lista (a cópia dele tem de sair do Google).
     *
     * @param array<mixed> $quemSaiu
     */
    private function anotarPessoas(Evento $evento, array $quemSaiu = []): void
    {
        $tenant = $evento->getTenant()?->getId();
        if (null === $tenant) {
            return;
        }
        foreach ([$evento->getCriador(), ...$evento->getParticipantes()->toArray(), ...$quemSaiu] as $pessoa) {
            $usuario = $pessoa instanceof User ? $pessoa->getId() : null;
            if (null !== $usuario) {
                $this->pessoas[$usuario . ':' . $tenant] = ['usuario' => $usuario, 'tenant' => $tenant];
            }
        }
    }

    /** Uma exclusão por agenda de pessoa em que o compromisso está. Em SQL: estamos dentro do ciclo de flush. */
    private function anotarExclusoes(int $eventoId): void
    {
        foreach ($this->banco->fetchAllAssociative(
            'SELECT c.usuario_id, c.tenant_id, l.google_id FROM agenda_google_ligacao l JOIN agenda_google_conexao c ON c.id = l.conexao_id WHERE l.evento_id = :e',
            ['e' => $eventoId],
        ) as $linha) {
            $this->exclusoes[] = ['usuario' => (int) $linha['usuario_id'], 'tenant' => (int) $linha['tenant_id'], 'google' => (string) $linha['google_id']];
            $this->pessoas[$linha['usuario_id'] . ':' . $linha['tenant_id']] = ['usuario' => (int) $linha['usuario_id'], 'tenant' => (int) $linha['tenant_id']];
        }
    }

    /** Em SQL, sem passar pelo Doctrine: estamos dentro do ciclo de flush. */
    private function conexaoDe(int $usuario, int $tenant): ?int
    {
        $id = $this->banco->fetchOne('SELECT id FROM agenda_google_conexao WHERE usuario_id = :u AND tenant_id = :t', ['u' => $usuario, 't' => $tenant]);

        return false === $id ? null : (int) $id;
    }
}
