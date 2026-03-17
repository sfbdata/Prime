<?php

namespace App\Service\Audit;

use App\Entity\Audit\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::onFlush)]
class AuditLogSubscriber
{
    private const MAX_STRING_LENGTH = 500;
    private const MAX_COLLECTION_ITEMS = 100;

    private const IGNORED_FIELDS = [
        'password',
        'invitationToken',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly EntityLabelResolver $entityLabelResolver,
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

            $payload = [
                'after' => $this->normalizeEntityState($entity, $unitOfWork),
            ];

            $audit = $this->buildAuditLog('create', $entity, $unitOfWork, $this->wrapAuditPayload($payload));
            $this->persistAuditIfUnique($entityManager, $unitOfWork, $auditMetadata, $audit, $loggedFingerprints);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$this->shouldAudit($entity)) {
                continue;
            }

            $changeSet = $this->normalizeChangeSet($unitOfWork->getEntityChangeSet($entity));
            if ($changeSet === []) {
                continue;
            }

            $audit = $this->buildAuditLog('update', $entity, $unitOfWork, $this->wrapAuditPayload([
                'changes' => $changeSet,
            ]));
            $this->persistAuditIfUnique($entityManager, $unitOfWork, $auditMetadata, $audit, $loggedFingerprints);
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (!$this->shouldAudit($entity)) {
                continue;
            }

            $payload = [
                'before' => $this->normalizeEntityState($entity, $unitOfWork),
            ];

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
                'owner' => $this->normalizeAssociationItem($owner, $unitOfWork),
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
        return $this->entityLabelResolver->resolve($entity);
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
        if ($entity instanceof AuditLog) {
            return false;
        }

        return str_starts_with($entity::class, 'App\\Entity\\');
    }

    private function buildAuditLog(string $action, object $entity, UnitOfWork $unitOfWork, array $changes): AuditLog
    {
        $user = $this->security->getUser();
        $request = $this->requestStack->getMainRequest();

        $actorUserId = ($user && method_exists($user, 'getId')) ? $user->getId() : null;
        $actorEmail = ($user && method_exists($user, 'getUserIdentifier')) ? $user->getUserIdentifier() : null;
        $tenantId = null;

        if ($user && method_exists($user, 'getTenant') && $user->getTenant() && method_exists($user->getTenant(), 'getId')) {
            $tenantId = $user->getTenant()->getId();
        }

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
            if ($this->isIgnoredField($field)) {
                continue;
            }

            $normalized[$field] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeChangeSet(array $changeSet): array
    {
        $normalized = [];

        foreach ($changeSet as $field => [$oldValue, $newValue]) {
            if ($this->isIgnoredField((string) $field)) {
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

    private function isIgnoredField(string $field): bool
    {
        return in_array($field, self::IGNORED_FIELDS, true);
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