<?php

namespace App\Service\Audit;

use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\ConversaDeChat;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\Invitation;
use App\Entity\Tenant\Tenant;
use App\Recompensa\Entity\DadosDeRecebimentoDeRecompensa;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\ConteudoParticular;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use App\Service\Tenant\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::onFlush)]
class AuditLogSubscriber
{
    private const MAX_STRING_LENGTH = 500;
    private const ROTULO_PARTICULAR = 'Compromisso particular';
    private const MAX_COLLECTION_ITEMS = 100;

    private const IGNORED_FIELDS = [
        'password',
        'invitationToken',
        // Refresh token OAuth do Drive por tenant — já cifrado, nunca vai para o audit_log.
        'refreshTokenCifrado',
    ];

    /**
     * Campos ignorados só numa entidade: o nome é genérico demais para valer em todas. O token do link do convite e o
     * hash dele não vão para o audit_log (achado de auditoria interna, spec seguranca-a-convites, raiz C).
     */
    private const IGNORED_FIELDS_POR_CLASSE = [
        Invitation::class => ['token', 'tokenHash'],
        // O snapshot do Termo de Voluntariado guarda CPF e RG do voluntário: fica fora da auditoria, que registra só
        // "termo enviado/aceito" (código, currículo, quando, quem). O user-agent do aceite também não é diff útil.
        TermoDeVoluntariado::class => ['campos', 'aceiteIp', 'aceiteUserAgent'],
        // CPF ou CNPJ e chave PIX de quem recebe a recompensa (F4): a trilha registra que mudou e quem mudou, nunca o valor.
        DadosDeRecebimentoDeRecompensa::class => ['documento', 'chavePix'],
        // Meu Escritório: a chave da logo e o carimbo de "alterado por/em" não são diff útil; o nome, CNPJ, OAB, e-mail,
        // telefone e endereço seguem na trilha como os demais campos do Tenant.
        Tenant::class => ['logoChave', 'alteradoEm', 'alteradoPor'],
        // Chat I.A: o título da conversa pode conter dado de cliente (nasce da pergunta) e o nome do arquivo anexado
        // também. A trilha guarda quem criou, renomeou (a marca `tituloManual`), fixou, trocou o modelo e excluiu,
        // nunca o texto. `atualizadaEm` muda a cada pergunta e só faria ruído.
        ConversaDeChat::class => ['titulo', 'atualizadaEm'],
        AnexoDeChat::class => ['nome', 'chave'],
    ];

    /**
     * Entidades cujo rótulo (título, nome) nunca vai para o audit_log, nem quando outra entidade as referencia
     * (o anexo aponta a conversa, que tem `getTitulo`). Fica só a classe e o id.
     */
    private const SEM_ROTULO = [ConversaDeChat::class, AnexoDeChat::class];

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly EntityLabelResolver $entityLabelResolver,
        private readonly TenantContext $tenantContext,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        $auditMetadata = $entityManager->getClassMetadata(AuditLog::class);
        $loggedFingerprints = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if (!$this->shouldAudit($entity)) {
                continue;
            }

            $payload = $this->semConteudoParticular($entity, [], [
                'after' => $this->normalizeEntityState($entity, $unitOfWork),
            ], 'after');

            $audit = $this->buildAuditLog('create', $entity, $unitOfWork, $this->wrapAuditPayload($payload));
            $this->persistAuditIfUnique($entityManager, $unitOfWork, $auditMetadata, $audit, $loggedFingerprints);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$this->shouldAudit($entity)) {
                continue;
            }

            $changeSet = $this->normalizeChangeSet($unitOfWork->getEntityChangeSet($entity), $entity);
            if ($changeSet === []) {
                continue;
            }

            $audit = $this->buildAuditLog('update', $entity, $unitOfWork, $this->wrapAuditPayload(
                $this->semConteudoParticular($entity, $unitOfWork->getEntityChangeSet($entity), ['changes' => $changeSet], 'changes'),
            ));
            $this->persistAuditIfUnique($entityManager, $unitOfWork, $auditMetadata, $audit, $loggedFingerprints);
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (!$this->shouldAudit($entity)) {
                continue;
            }

            // O `before` é o estado do banco: a decisão olha também a visibilidade de lá.
            $doBanco = array_map(static fn (mixed $valor): array => [$valor, $valor], $unitOfWork->getOriginalEntityData($entity));
            $payload = $this->semConteudoParticular($entity, $doBanco, [
                'before' => $this->normalizeEntityState($entity, $unitOfWork),
            ], 'before');

            $audit = $this->buildAuditLog('delete', $entity, $unitOfWork, $this->wrapAuditPayload($payload));
            $this->persistAuditIfUnique($entityManager, $unitOfWork, $auditMetadata, $audit, $loggedFingerprints);
        }

        foreach ($unitOfWork->getScheduledCollectionUpdates() as $collection) {
            $this->auditCollectionChange($entityManager, $unitOfWork, $auditMetadata, $loggedFingerprints, $collection);
        }

        foreach ($unitOfWork->getScheduledCollectionDeletions() as $collection) {
            $this->auditCollectionChange($entityManager, $unitOfWork, $auditMetadata, $loggedFingerprints, $collection);
        }
    }

    /**
     * @param array<string, true> $loggedFingerprints
     */
    private function auditCollectionChange(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        ClassMetadata $auditMetadata,
        array &$loggedFingerprints,
        mixed $collection
    ): void {
        if (!$collection instanceof PersistentCollection) {
            return;
        }

        $owner = $collection->getOwner();
        if (!is_object($owner) || !$this->shouldAudit($owner)) {
            return;
        }

        $mapping = $collection->getMapping();
        $isManyToMany = isset($mapping['type']) && (int) $mapping['type'] === ClassMetadata::MANY_TO_MANY;
        if (!$isManyToMany) {
            return;
        }

        $isOwningSide = (bool) ($mapping['isOwningSide'] ?? false);
        $isBidirectionalOwningSide = $isOwningSide
            && isset($mapping['inversedBy'])
            && is_string($mapping['inversedBy'])
            && $mapping['inversedBy'] !== '';

        if ($isBidirectionalOwningSide) {
            return;
        }

        $fieldName = isset($mapping['fieldName']) && is_string($mapping['fieldName']) ? $mapping['fieldName'] : 'association';
        $changes = [];

        foreach ($collection->getInsertDiff() as $insertedItem) {
            if (!is_object($insertedItem)) {
                continue;
            }

            $normalized = $this->normalizeAssociationItem($insertedItem, $unitOfWork);
            $itemId = $normalized['id'] ?? null;
            $key = is_string($itemId) && $itemId !== ''
                ? sprintf('%s[+:%s]', $fieldName, $itemId)
                : sprintf('%s[+:%s]', $fieldName, spl_object_id($insertedItem));

            $changes[$key] = [
                'from' => null,
                'to' => $normalized,
            ];
        }

        foreach ($collection->getDeleteDiff() as $deletedItem) {
            if (!is_object($deletedItem)) {
                continue;
            }

            $normalized = $this->normalizeAssociationItem($deletedItem, $unitOfWork);
            $itemId = $normalized['id'] ?? null;
            $key = is_string($itemId) && $itemId !== ''
                ? sprintf('%s[-:%s]', $fieldName, $itemId)
                : sprintf('%s[-:%s]', $fieldName, spl_object_id($deletedItem));

            $changes[$key] = [
                'from' => $normalized,
                'to' => null,
            ];
        }

        if ($changes === []) {
            return;
        }

        $associationEvents = [];
        foreach ($changes as $fieldKey => $diff) {
            $isAdd = str_contains($fieldKey, '[+:');
            $isRemove = str_contains($fieldKey, '[-:');

            $associationEvents[] = [
                'field' => $fieldName,
                'operation' => $isAdd ? 'add' : ($isRemove ? 'remove' : 'change'),
                'owner' => $this->donoDaColecao($owner, $unitOfWork),
                'related' => is_array($diff['to'] ?? null) ? $diff['to'] : (is_array($diff['from'] ?? null) ? $diff['from'] : null),
            ];
        }

        $audit = $this->buildAuditLog('update', $owner, $unitOfWork, $this->wrapAuditPayload([
            'changes' => $changes,
            'association_events' => $associationEvents,
        ]));

        $this->persistAuditIfUnique($entityManager, $unitOfWork, $auditMetadata, $audit, $loggedFingerprints);
    }

    private function normalizeAssociationItem(object $entity, UnitOfWork $unitOfWork): array
    {
        return [
            'class' => $entity::class,
            'id' => $this->resolveEntityId($entity, $unitOfWork),
            'label' => $this->resolveEntityLabel($entity),
        ];
    }

    private function resolveEntityLabel(object $entity): ?string
    {
        // O rótulo de um compromisso "Só eu" é o título: não vai para a auditoria (nem como dono de uma
        // mudança de participantes, nem como item relacionado de outra entidade).
        if ($entity instanceof ConteudoParticular && $entity->ehParticular()) {
            return self::ROTULO_PARTICULAR;
        }

        foreach (self::SEM_ROTULO as $classe) {
            if ($entity instanceof $classe) {
                return null;
            }
        }

        return $this->entityLabelResolver->resolve($entity);
    }

    /** Dono de uma mudança de coleção: "Só eu" antes ou depois desta mudança leva o rótulo particular. */
    private function donoDaColecao(object $owner, UnitOfWork $unitOfWork): array
    {
        $dono = $this->normalizeAssociationItem($owner, $unitOfWork);
        if ($owner instanceof ConteudoParticular && $owner->ehParticular($unitOfWork->getEntityChangeSet($owner))) {
            $dono['label'] = self::ROTULO_PARTICULAR;
        }

        return $dono;
    }

    /**
     * Tira do registro os campos particulares (antes e depois) de uma entidade "Só eu", ou que era "Só eu"
     * antes desta mudança. A ação, quem e quando continuam; a marca `conteudo_particular` diz que algo foi
     * omitido (o desfazer só lê `changes`, então os campos que ficaram continuam desfazíveis).
     *
     * @param array<string, mixed> $mudancasDoDoctrine
     * @param array<string, mixed> $diff
     *
     * @return array<string, mixed>
     */
    private function semConteudoParticular(object $entity, array $mudancasDoDoctrine, array $diff, string $chave): array
    {
        if (!$entity instanceof ConteudoParticular || !$entity->ehParticular($mudancasDoDoctrine)) {
            return $diff;
        }
        $campos = is_array($diff[$chave] ?? null) ? $diff[$chave] : [];
        foreach ($entity->camposParticulares() as $campo) {
            unset($campos[$campo]);
        }
        $diff[$chave] = $campos;
        $diff['conteudo_particular'] = true;

        return $diff;
    }

    private function wrapAuditPayload(array $diff): array
    {
        return [
            'diff' => $diff,
            'context' => $this->buildContext(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        $user = $this->security->getUser();
        $request = $this->requestStack->getMainRequest();

        $route = null;
        $ipAddress = null;
        $userAgent = null;
        $module = null;

        if ($request instanceof Request) {
            $routeValue = $request->attributes->get('_route');
            $route = is_string($routeValue) ? $routeValue : null;
            $ipAddress = $request->getClientIp();
            $userAgent = $request->headers->get('User-Agent');

            if (is_string($route) && $route !== '') {
                $module = explode('_', $route)[0] ?? null;
            }
        }

        $roles = ($user && method_exists($user, 'getRoles')) ? $user->getRoles() : null;

        return array_filter([
            'module' => is_string($module) && $module !== '' ? $module : null,
            'route' => $route,
            'ip' => $ipAddress,
            'user_agent' => is_string($userAgent) && $userAgent !== '' ? $userAgent : null,
            'actor_roles' => is_array($roles) && $roles !== [] ? array_values(array_unique($roles)) : null,
            'origin' => 'web',
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, true> $loggedFingerprints
     */
    private function persistAuditIfUnique(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        ClassMetadata $auditMetadata,
        AuditLog $audit,
        array &$loggedFingerprints
    ): void {
        $fingerprint = $this->computeAuditFingerprint($audit);
        if (isset($loggedFingerprints[$fingerprint])) {
            return;
        }

        $loggedFingerprints[$fingerprint] = true;

        $entityManager->persist($audit);
        $unitOfWork->computeChangeSet($auditMetadata, $audit);

        $this->writeAuditChannel($audit);
    }

    private function computeAuditFingerprint(AuditLog $audit): string
    {
        $payload = [
            'action' => $audit->getAction(),
            'entity_class' => $audit->getEntityClass(),
            'entity_id' => $audit->getEntityId(),
            'changes' => $audit->getChanges(),
            'actor_user_id' => $audit->getActorUserId(),
            'route' => $audit->getRoute(),
        ];

        return sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function shouldAudit(object $entity): bool
    {
        return $entity instanceof Auditavel;
    }

    private function buildAuditLog(string $action, object $entity, UnitOfWork $unitOfWork, array $changes): AuditLog
    {
        $user = $this->security->getUser();
        $request = $this->requestStack->getMainRequest();

        $actorUserId = ($user && method_exists($user, 'getId')) ? $user->getId() : null;
        $actorEmail = ($user && method_exists($user, 'getUserIdentifier')) ? $user->getUserIdentifier() : null;
        // Ato de plataforma grava com o escritório do escopo, nunca com o da sessão: sem escritório
        // (promover/remover Master) ou com a conta alvo (suspender/reativar conta pelo Master).
        $tenantId = $this->escopoDePlataforma->estaLigado()
            ? $this->escopoDePlataforma->contaAlvo()
            : $this->tenantContext->getCurrentTenant()?->getId();

        return (new AuditLog())
            ->setAction($action)
            ->setEntityClass($entity::class)
            ->setEntityId($this->resolveEntityId($entity, $unitOfWork))
            ->setChanges($changes)
            ->setActorUserId(is_int($actorUserId) ? $actorUserId : null)
            ->setActorEmail(is_string($actorEmail) ? $actorEmail : null)
            ->setTenantId(is_int($tenantId) ? $tenantId : null)
            ->setIpAddress($request?->getClientIp())
            ->setRoute($request?->attributes->get('_route'));
    }

    private function resolveEntityId(object $entity, UnitOfWork $unitOfWork): ?string
    {
        try {
            $identifier = $unitOfWork->getEntityIdentifier($entity);
        } catch (EntityNotFoundException) {
            if (method_exists($entity, 'getId')) {
                $id = $entity->getId();

                return is_scalar($id) ? (string) $id : null;
            }

            return null;
        }

        if ($identifier === [] && method_exists($entity, 'getId')) {
            $id = $entity->getId();
            return is_scalar($id) ? (string) $id : null;
        }

        if (count($identifier) === 1) {
            $id = array_values($identifier)[0];
            return is_scalar($id) ? (string) $id : null;
        }

        if ($identifier === []) {
            return null;
        }

        return json_encode($this->normalizeValue($identifier), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeEntityState(object $entity, UnitOfWork $unitOfWork): array
    {
        $data = $unitOfWork->getOriginalEntityData($entity);

        if ($data === []) {
            $data = get_object_vars($entity);
        }

        $normalized = [];

        foreach ($data as $field => $value) {
            if ($this->isIgnoredField($field, $entity)) {
                continue;
            }

            $normalized[$field] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeChangeSet(array $changeSet, object $entity): array
    {
        $normalized = [];

        foreach ($changeSet as $field => [$oldValue, $newValue]) {
            if ($this->isIgnoredField((string) $field, $entity)) {
                continue;
            }

            $from = $this->normalizeValue($oldValue);
            $to = $this->normalizeValue($newValue);

            if ($from === $to) {
                continue;
            }

            $normalized[(string) $field] = [
                'from' => $from,
                'to' => $to,
            ];
        }

        return $normalized;
    }

    private function isIgnoredField(string $field, object $entity): bool
    {
        if (in_array($field, self::IGNORED_FIELDS, true)) {
            return true;
        }
        foreach (self::IGNORED_FIELDS_POR_CLASSE as $classe => $campos) {
            if ($entity instanceof $classe && in_array($field, $campos, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_STRING_LENGTH
                ? mb_substr($value, 0, self::MAX_STRING_LENGTH).'…'
                : $value;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if ($count >= self::MAX_COLLECTION_ITEMS) {
                    $result['_truncated'] = true;
                    break;
                }

                $result[$key] = $this->normalizeValue($item);
                $count++;
            }

            return $result;
        }

        if ($value instanceof \Traversable) {
            $result = [];
            $count = 0;

            foreach ($value as $item) {
                if ($count >= self::MAX_COLLECTION_ITEMS) {
                    $result[] = ['_truncated' => true];
                    break;
                }

                $result[] = $this->normalizeValue($item);
                $count++;
            }

            return $result;
        }

        // Enum vai pelo VALOR ('encerrada', 'urgente'): no ramo de objeto abaixo virava
        // {class, id: null, label: null}, que não diz qual caso foi gravado.
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            return [
                'class' => $value::class,
                'id' => method_exists($value, 'getId') && is_scalar($value->getId()) ? (string) $value->getId() : null,
                'label' => $this->resolveEntityLabel($value),
            ];
        }

        return (string) $value;
    }

    private function writeAuditChannel(AuditLog $audit): void
    {
        $this->auditLogger->info('entity_audit', [
            'action' => $audit->getAction(),
            'entity_class' => $audit->getEntityClass(),
            'entity_id' => $audit->getEntityId(),
            'actor_user_id' => $audit->getActorUserId(),
            'tenant_id' => $audit->getTenantId(),
        ]);
    }
}