<?php

declare(strict_types=1);

namespace App\Agenda\EventListener;

use App\Agenda\Entity\Evento;
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
 * a agenda Google de quem o criou é sincronizada logo em seguida, se ela estiver conectada. A exclusão de
 * um compromisso que já está no Google fica anotada para a sincronia apagar lá. Anota no onFlush e age no
 * postFlush, depois de o banco aceitar (um flush que falha não apaga nada no Google).
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
                $this->anotarPessoa($entidade);
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entidade) {
            if ($entidade instanceof Evento && $this->anotarPessoa($entidade) && null !== $entidade->getGoogleId()) {
                $this->exclusoes[] = ['usuario' => (int) $entidade->getCriador()?->getId(), 'tenant' => (int) $entidade->getTenant()?->getId(), 'google' => $entidade->getGoogleId()];
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

    private function anotarPessoa(Evento $evento): bool
    {
        $usuario = $evento->getCriador()?->getId();
        $tenant = $evento->getTenant()?->getId();
        if (null === $usuario || null === $tenant) {
            return false;
        }
        $this->pessoas[$usuario . ':' . $tenant] = ['usuario' => $usuario, 'tenant' => $tenant];

        return true;
    }

    /** Em SQL, sem passar pelo Doctrine: estamos dentro do ciclo de flush. */
    private function conexaoDe(int $usuario, int $tenant): ?int
    {
        $id = $this->banco->fetchOne('SELECT id FROM agenda_google_conexao WHERE usuario_id = :u AND tenant_id = :t', ['u' => $usuario, 't' => $tenant]);

        return false === $id ? null : (int) $id;
    }
}
