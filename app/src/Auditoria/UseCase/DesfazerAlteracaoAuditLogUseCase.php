<?php

declare(strict_types=1);

namespace App\Auditoria\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\AuditLogRepository;
use App\Repository\UserTenantRepository;
use App\Shared\Contract\Descartavel;
use App\Shared\Contract\TenantAware;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;

/**
 * Desfaz um `update` gravado no audit_log, devolvendo cada campo do diff ao valor `from`.
 *
 * Tudo ou nada (D-AUDIT-UNDO): o valor gravado no log é a forma NORMALIZADA pelo
 * `AuditLogSubscriber` (enum pelo `->value`, data em ATOM, associação como `{class, id, label}`),
 * não o objeto. Antes de tocar na entidade, cada campo é convertido pelo tipo da metadata Doctrine
 * e conferido contra a assinatura do setter. Se UM campo não se desfaz — sem setter, coleção,
 * valor irrecuperável (enum no formato antigo), associação que sumiu ou é de outro escritório —
 * a operação inteira é recusada e nada muda. Antes, o campo era pulado em silêncio e o resultado
 * dizia "sucesso"; enum e data iam crus ao setter tipado e davam TypeError (500).
 */
final class DesfazerAlteracaoAuditLogUseCase
{
    /** Entidades de risco BAIXO — únicas reversíveis. */
    private const ENTIDADES_REVERSIVEIS = [
        \App\Pasta\Entity\Pasta::class,
        \App\Pasta\Entity\PastaDocumento::class,
        \App\Pasta\Entity\PastaSecao::class,
        \App\Pasta\Entity\PastaChecklistItem::class,
        \App\Pasta\Entity\PastaMensagem::class,
        \App\Pasta\Entity\PastaObservacaoDetalhes::class,
        \App\Entity\Tarefa\Tarefa::class,
        \App\Entity\Tarefa\TarefaMensagem::class,
        \App\Entity\ServiceDesk\Chamado::class,
        \App\Entity\ServiceDesk\ChamadoAnexo::class,
        \App\Entity\ServiceDesk\ChamadoInteracao::class,
        \App\Entity\Agenda\Evento::class,
        \App\Entity\Agenda\LegendaCor::class,
        \App\Entity\Notificacao::class,
        \App\Entity\Tenant\Cargo::class,
        \App\Entity\Tenant\Lotacao::class,
        \App\Entity\Tenant\Sede::class,
    ];

    /**
     * Campos da lixeira (D7): a ida e a volta não se desfazem por aqui — as entidades não têm
     * setter para eles de propósito, então o desfazer "daria certo" sem mudar nada. O caminho é
     * a lixeira da pasta (restaurar), e a mensagem aponta para lá.
     */
    private const CAMPOS_DA_LIXEIRA = ['excluidoEm', 'excluidoPor'];

    /**
     * Associações de hierarquia: a guarda de ciclo mora nos UseCases de mover
     * (`MoverPastaSecaoUseCase`), não no setter — gravar direto pelo setter poderia fechar um ciclo.
     */
    private const CAMPOS_DE_HIERARQUIA = [
        \App\Pasta\Entity\PastaSecao::class => ['pai'],
    ];

    /**
     * Comprimento do texto que o `AuditLogSubscriber::normalizeValue()` grava ao cortar:
     * `mb_substr($value, 0, MAX_STRING_LENGTH) . '…'`, com MAX_STRING_LENGTH = 500,
     * dá 501 caracteres. Se o limite de lá mudar, este tem de mudar junto.
     */
    private const COMPRIMENTO_TEXTO_TRUNCADO = 501;

    private const TIPOS_DATA_MUTAVEL = [
        Types::DATE_MUTABLE,
        Types::DATETIME_MUTABLE,
        Types::DATETIMETZ_MUTABLE,
        Types::TIME_MUTABLE,
    ];

    private const TIPOS_DATA_IMUTAVEL = [
        Types::DATE_IMMUTABLE,
        Types::DATETIME_IMMUTABLE,
        Types::DATETIMETZ_IMMUTABLE,
        Types::TIME_IMMUTABLE,
    ];

    public const MENSAGEM_ITEM_NA_LIXEIRA = 'Este item está na lixeira da pasta; restaure-o antes de desfazer a alteração.';
    public const MENSAGEM_ALTERACAO_DA_LIXEIRA = 'A ida e a volta da lixeira não se desfazem por aqui: use a lixeira da pasta.';
    public const MENSAGEM_CAMPO_NAO_REVERSIVEL = 'O campo "%s" não se desfaz automaticamente. Nada foi alterado.';
    public const MENSAGEM_VALOR_IRRECUPERAVEL = 'O valor anterior do campo "%s" não pôde ser recuperado do registro de auditoria. Nada foi alterado.';
    public const MENSAGEM_ASSOCIACAO_INDISPONIVEL = 'O valor anterior do campo "%s" não existe mais ou não pertence a este escritório. Nada foi alterado.';
    public const MENSAGEM_VALOR_TRUNCADO = 'O valor anterior foi gravado truncado na auditoria; não dá para restaurá-lo com segurança. Nada foi alterado.';
    public const MENSAGEM_HIERARQUIA = 'O campo "%s" muda a hierarquia de pastas e não se desfaz por aqui: use a ação de mover. Nada foi alterado.';
    public const MENSAGEM_VALOR_RECUSADO ='O valor anterior não é mais aceito pelo cadastro. Nada foi alterado.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AcessoALixeira $lixeira,
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    public function podeReverter(AuditLog $log): bool
    {
        return $log->getAction() === 'update'
            && in_array($log->getEntityClass(), self::ENTIDADES_REVERSIVEIS, true)
            && $log->getEntityId() !== null;
    }

    public function executar(int $auditLogId, int $tenantId): DesfazerResultado
    {
        $log = $this->auditLogRepository->find($auditLogId);

        if ($log === null || $log->getTenantId() !== $tenantId) {
            return new DesfazerResultado(false, 'Registro não encontrado.');
        }

        if (!$this->podeReverter($log)) {
            return new DesfazerResultado(false, 'Esta alteração não pode ser desfeita automaticamente.');
        }

        $changes = $log->getChanges() ?? [];
        $diff = $changes['diff']['changes'] ?? [];

        if (is_array($diff) && array_intersect(self::CAMPOS_DA_LIXEIRA, array_keys($diff)) !== []) {
            return new DesfazerResultado(false, self::MENSAGEM_ALTERACAO_DA_LIXEIRA);
        }

        $entity = $this->em->find($log->getEntityClass(), $log->getEntityId());

        if ($entity === null) {
            // O `find()` passa pelo LixeiraFilter: um documento/subpasta na lixeira "não existe"
            // para ele. Distinguir aqui evita o diagnóstico errado — a linha está lá, só escondida.
            if ($this->estaNaLixeira($log->getEntityClass(), $log->getEntityId())) {
                return new DesfazerResultado(false, self::MENSAGEM_ITEM_NA_LIXEIRA);
            }

            return new DesfazerResultado(false, 'Entidade não existe mais.');
        }

        // Defesa em profundidade: o log é do tenant, mas a entidade também tem de ser — o
        // `find()` pode vir do identity map, que não passa pelo TenantFilter.
        if ($entity instanceof TenantAware && $entity->getTenant()?->getId() !== $tenantId) {
            return new DesfazerResultado(false, 'Registro não encontrado.');
        }

        if (!is_array($diff) || $diff === []) {
            return new DesfazerResultado(false, 'Nada a reverter.');
        }

        $metadata = $this->em->getClassMetadata($log->getEntityClass());

        // Fase 1: preparar TODOS os campos sem tocar na entidade. Qualquer recusa sai daqui.
        /** @var list<array{0: string, 1: mixed}> $aplicacoes */
        $aplicacoes = [];

        foreach ($diff as $campo => $fieldDiff) {
            $campo = (string) $campo;
            $from = is_array($fieldDiff) ? ($fieldDiff['from'] ?? null) : null;

            // O subscriber corta texto longo e marca com `…`: restaurar gravaria o texto cortado.
            // Só o comprimento exato do corte conta — texto curto terminado em "…" (o celular troca
            // "..." por "…") é valor legítimo e se desfaz normalmente.
            if (is_string($from) && mb_strlen($from) === self::COMPRIMENTO_TEXTO_TRUNCADO && str_ends_with($from, '…')) {
                return new DesfazerResultado(false, self::MENSAGEM_VALOR_TRUNCADO);
            }

            $preparado = $this->prepararCampo($entity, $metadata, $campo, $from, $tenantId);

            if ($preparado instanceof DesfazerResultado) {
                return $preparado;
            }

            $aplicacoes[] = $preparado;
        }

        // Fase 2: aplicar. Os tipos já foram conferidos; resta a regra do próprio setter
        // (ex.: guarda de ciclo). Se ela recusar no meio, a entidade volta ao estado do banco.
        try {
            foreach ($aplicacoes as [$setter, $valor]) {
                $entity->$setter($valor);
            }
        } catch (\TypeError|\ValueError|\LogicException) {
            $this->em->refresh($entity);

            return new DesfazerResultado(false, self::MENSAGEM_VALOR_RECUSADO);
        }

        $this->em->flush();

        return new DesfazerResultado(sucesso: true);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array{0: string, 1: mixed}|DesfazerResultado o par [setter, valor] ou a recusa
     */
    private function prepararCampo(object $entity, ClassMetadata $metadata, string $campo, mixed $from, int $tenantId): array|DesfazerResultado
    {
        $naoReversivel = new DesfazerResultado(false, sprintf(self::MENSAGEM_CAMPO_NAO_REVERSIVEL, $campo));

        // Item de coleção (`campo[+:id]` / `campo[-:id]`): não há setter de um item só.
        if (str_contains($campo, '[+:') || str_contains($campo, '[-:')) {
            return $naoReversivel;
        }

        foreach (self::CAMPOS_DE_HIERARQUIA as $classe => $campos) {
            if ($entity instanceof $classe && in_array($campo, $campos, true)) {
                return new DesfazerResultado(false, sprintf(self::MENSAGEM_HIERARQUIA, $campo));
            }
        }

        $setter = $this->resolverSetter($entity, $campo);

        if ($setter === null) {
            return $naoReversivel;
        }

        if ($metadata->hasField($campo)) {
            [$ok, $valor] = $this->converterCampo($metadata->getFieldMapping($campo), $from);
        } elseif ($metadata->hasAssociation($campo)) {
            if (!$metadata->isSingleValuedAssociation($campo)) {
                return $naoReversivel;
            }

            $associacao = $this->resolverAssociacao($metadata->getAssociationTargetClass($campo), $from, $tenantId);

            if ($associacao instanceof DesfazerResultado) {
                return new DesfazerResultado(false, sprintf((string) $associacao->erro, $campo));
            }

            [$ok, $valor] = [true, $associacao[0]];
        } else {
            return $naoReversivel;
        }

        if (!$ok || !$this->setterAceita($setter, $valor)) {
            return new DesfazerResultado(false, sprintf(self::MENSAGEM_VALOR_IRRECUPERAVEL, $campo));
        }

        return [$setter->getName(), $valor];
    }

    private function resolverSetter(object $entity, string $campo): ?\ReflectionMethod
    {
        $nome = 'set' . ucfirst($campo);

        if (!method_exists($entity, $nome)) {
            return null;
        }

        $setter = new \ReflectionMethod($entity, $nome);

        if (!$setter->isPublic() || $setter->isStatic()
            || $setter->getNumberOfParameters() < 1 || $setter->getNumberOfRequiredParameters() > 1) {
            return null;
        }

        return $setter;
    }

    /**
     * Converte o valor normalizado do log para o tipo PHP do campo.
     *
     * @return array{0: bool, 1: mixed} [conseguiu, valor]
     */
    private function converterCampo(FieldMapping $mapping, mixed $from): array
    {
        if ($from === null) {
            return [true, null];
        }

        if ($mapping->enumType !== null) {
            return $this->converterEnum($mapping->enumType, $from);
        }

        $tipo = $mapping->type;

        if (in_array($tipo, self::TIPOS_DATA_IMUTAVEL, true) || in_array($tipo, self::TIPOS_DATA_MUTAVEL, true)) {
            if (!is_string($from) || trim($from) === '') {
                return [false, null];
            }

            try {
                $data = in_array($tipo, self::TIPOS_DATA_IMUTAVEL, true)
                    ? new \DateTimeImmutable($from)
                    : new \DateTime($from);
            } catch (\Exception) {
                return [false, null];
            }

            return [true, $data];
        }

        return match ($tipo) {
            Types::INTEGER, Types::SMALLINT => match (true) {
                is_int($from) => [true, $from],
                is_string($from) && preg_match('/^-?\d+$/', $from) === 1 => [true, (int) $from],
                default => [false, null],
            },
            Types::BIGINT => is_int($from) || (is_string($from) && preg_match('/^-?\d+$/', $from) === 1)
                ? [true, $from]
                : [false, null],
            Types::FLOAT => is_int($from) || is_float($from) || (is_string($from) && is_numeric($from))
                ? [true, (float) $from]
                : [false, null],
            Types::DECIMAL => is_int($from) || is_float($from) || (is_string($from) && is_numeric($from))
                ? [true, (string) $from]
                : [false, null],
            Types::BOOLEAN => is_bool($from) ? [true, $from] : [false, null],
            Types::STRING, Types::TEXT, Types::GUID, Types::ASCII_STRING => is_scalar($from) && !is_bool($from)
                ? [true, (string) $from]
                : [false, null],
            // json e tipos próprios: vão como estão; a assinatura do setter decide.
            default => [true, $from],
        };
    }

    /**
     * Formato novo: o `->value`. Formato antigo (antes do subscriber gravar o valor):
     * `{class, id: null, label}` — só se recupera se o `label` identificar um caso.
     *
     * @return array{0: bool, 1: mixed}
     */
    private function converterEnum(string $enumClasse, mixed $from): array
    {
        if (!is_a($enumClasse, \BackedEnum::class, true)) {
            return [false, null];
        }

        if (is_array($from)) {
            $label = $from['label'] ?? null;

            if (!is_string($label) || $label === '') {
                return [false, null];
            }

            foreach ($enumClasse::cases() as $caso) {
                $rotulo = method_exists($caso, 'label') ? $caso->label() : null;

                if ((string) $caso->value === $label || $caso->name === $label || $rotulo === $label) {
                    return [true, $caso];
                }
            }

            return [false, null];
        }

        if (!is_int($from) && !is_string($from)) {
            return [false, null];
        }

        foreach ($enumClasse::cases() as $caso) {
            if ((string) $caso->value === (string) $from) {
                return [true, $caso];
            }
        }

        return [false, null];
    }

    /**
     * Associação a um só: o log guarda `{class, id, label}`. A referência é recarregada pelo
     * id da classe ALVO da metadata (não pela classe gravada, que pode ser um proxy) e só vale
     * se pertencer ao mesmo escritório.
     *
     * @return array{0: ?object}|DesfazerResultado o valor (com `%s` na mensagem de erro para o campo)
     */
    private function resolverAssociacao(string $classeAlvo, mixed $from, int $tenantId): array|DesfazerResultado
    {
        if ($from === null) {
            return [null];
        }

        $id = is_array($from) ? ($from['id'] ?? null) : null;

        if (!is_scalar($id) || (string) $id === '') {
            return new DesfazerResultado(false, self::MENSAGEM_VALOR_IRRECUPERAVEL);
        }

        $relacionado = $this->em->find($classeAlvo, $id);

        if ($relacionado === null || !$this->pertenceAoTenant($relacionado, $tenantId)) {
            return new DesfazerResultado(false, self::MENSAGEM_ASSOCIACAO_INDISPONIVEL);
        }

        return [$relacionado];
    }

    /**
     * Prova positiva de posse: sem ela, recusa. O `find()` pode devolver do identity map, que
     * não passa pelo TenantFilter — por isso a conferência é explícita.
     */
    private function pertenceAoTenant(object $relacionado, int $tenantId): bool
    {
        if ($relacionado instanceof Tenant) {
            return $relacionado->getId() === $tenantId;
        }

        if ($relacionado instanceof TenantAware) {
            return $relacionado->getTenant()?->getId() === $tenantId;
        }

        if ($relacionado instanceof User) {
            $tenant = $this->em->find(Tenant::class, $tenantId);

            return $tenant instanceof Tenant
                && $this->userTenantRepository->findPorUserETenant($relacionado, $tenant) !== null;
        }

        return false;
    }

    /** Confere o valor contra o tipo do 1º parâmetro do setter, para a fase de aplicar não dar TypeError. */
    private function setterAceita(\ReflectionMethod $setter, mixed $valor): bool
    {
        $tipo = $setter->getParameters()[0]->getType();

        if ($tipo === null) {
            return true;
        }

        if ($valor === null) {
            return $tipo->allowsNull();
        }

        $tipos = $tipo instanceof \ReflectionUnionType ? $tipo->getTypes() : [$tipo];

        foreach ($tipos as $t) {
            if ($t instanceof \ReflectionNamedType && $this->valorDoTipo($t->getName(), $valor, $setter->getDeclaringClass()->getName())) {
                return true;
            }
        }

        return false;
    }

    private function valorDoTipo(string $nome, mixed $valor, string $classeDeclarante): bool
    {
        return match ($nome) {
            'mixed' => true,
            'null' => false,
            'int' => is_int($valor),
            'float' => is_float($valor) || is_int($valor),
            'string' => is_string($valor),
            'bool' => is_bool($valor),
            'true' => $valor === true,
            'false' => $valor === false,
            'array', 'iterable' => is_array($valor),
            'object' => is_object($valor),
            'self', 'static' => $valor instanceof $classeDeclarante,
            default => $valor instanceof $nome,
        };
    }

    /** Só para entidades `Descartavel`; nas outras o `find()` nulo é mesmo "não existe mais". */
    private function estaNaLixeira(string $classe, string $id): bool
    {
        if (!is_a($classe, Descartavel::class, true)) {
            return false;
        }

        $item = $this->lixeira->comLixeiraVisivel(fn (): ?object => $this->em->find($classe, $id));

        return $item instanceof Descartavel && $item->estaNaLixeira();
    }
}
