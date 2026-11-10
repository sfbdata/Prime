<?php

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Model\AlvoDoAjuste;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JustificativaPonto>
 */
class JustificativaPontoRepository extends ServiceEntityRepository
{
    use ConsultaSemFiltroDeEscritorio;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JustificativaPonto::class);
    }

    /**
     * Quantos registros do escritório ainda apontam para este anexo.
     *
     * É a pergunta que decide se o arquivo físico pode ser apagado, então o escopo importa na
     * direção contrária à intuição: escopo mais LARGO conta mais e apaga MENOS (seguro); escopo
     * mais ESTREITO é o que apagaria arquivo ainda em uso.
     *
     * Contar por tenant é, tecnicamente, o escopo estreito — e o diretório de justificativas é
     * plano, compartilhado por todos os escritórios. É seguro por uma razão específica: o nome do
     * arquivo é `bin2hex(random_bytes(16))`, único globalmente, então dois escritórios não têm
     * como referenciar o mesmo arquivo físico. A purga de escritório não confia só nisso: desde a
     * E2.5 ela só apaga um arquivo plano que nenhum registro de outro escritório referencia.
     *
     * O filtro de tenant é EXPLÍCITO, e não delegado ao TenantFilter do Doctrine, porque esta
     * contagem autoriza um `unlink` e não pode depender de um filtro que só é ligado no
     * `kernel.request`.
     */
    public function contarReferenciasAoAnexo(string $anexoPath, Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.anexoPath = :anexo')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('anexo', $anexoPath)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * O `anexo_path` COMO ESTÁ NO BANCO, por projeção escalar.
     *
     * Existe porque `findLotePorBatchId()` **não** relê os campos de uma entidade que já esteja no
     * identity map: sem `Query::HINT_REFRESH`, o `UnitOfWork` devolve a instância gerenciada com
     * os valores que ela já tinha em memória (ver `UnitOfWork::createEntity`). Como a justificativa
     * chega ao UseCase carregada pelo EntityValueResolver — muito antes da trava —, ler o anexo
     * pelo getter daria o valor de ANTES da trava, que é justamente o que a trava existe para
     * evitar.
     *
     * Uma projeção escalar não hidrata entidade e não consulta o identity map: o valor vem do
     * banco, já sob a trava.
     */
    public function anexoNoBancoPorId(int $id, Tenant $tenant): ?string
    {
        /** @var array<int,array{anexoPath: string|null}> $linhas */
        $linhas = $this->createQueryBuilder('j')
            ->select('j.anexoPath')
            ->andWhere('j.id = :id')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getScalarResult();

        return $linhas[0]['anexoPath'] ?? null;
    }

    /**
     * Os status do lote COMO ESTÃO NO BANCO, com as linhas travadas até o fim da transação.
     *
     * É a pergunta que decide se o atestado do lote pode ser trocado
     * (docs/specs/ponto-troca-de-atestado-analisado.md, R1/R2). Duas propriedades importam:
     *
     *  - projeção escalar: não passa pelo identity map, então o status vem do banco e não da
     *    entidade carregada pelo EntityValueResolver antes da trava — mesmo motivo de
     *    `anexoNoBancoPorId()`;
     *  - `FOR UPDATE`: a análise do admin (`TenantController`) não pega a trava advisory do lote.
     *    Com a linha travada, um UPDATE de status do admin espera este COMMIT, e um que já comitou é
     *    visto por esta leitura.
     *
     * Sem `batchId` (justificativa avulsa, ou dado anterior ao campo) o lote é o próprio registro.
     * Sem transação aberta o Doctrine lança `TransactionRequiredException`: a trava cairia na hora.
     *
     * @return list<string>
     */
    public function statusDoLoteTravado(?string $batchId, int $id, Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('j')
            ->select('j.status')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        if ($batchId !== null && $batchId !== '') {
            $qb->andWhere('j.batchId = :batch')->setParameter('batch', $batchId);
        } else {
            $qb->andWhere('j.id = :id')->setParameter('id', $id);
        }

        /** @var list<array{status: string|null}> $linhas */
        $linhas = $qb->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();

        return array_map(static fn (array $linha): string => (string) $linha['status'], $linhas);
    }

    /**
     * Todos os registros de um lote de abono (mesmo `batchId`) dentro do escritório.
     *
     * O lote nasce como unidade: `PontoController::novaJustificativa` faz UM upload e grava a
     * mesma string em N registros, um por dia. Em produção há um lote de 27 dias com um único
     * atestado. Por isso substituir o anexo tem de atingir o lote inteiro — caso contrário 1 dia
     * ficaria com o arquivo novo e 26 com o antigo.
     *
     * @return JustificativaPonto[]
     */
    public function findLotePorBatchId(string $batchId, Tenant $tenant): array
    {
        /** @var JustificativaPonto[] $lote */
        $lote = $this->createQueryBuilder('j')
            ->andWhere('j.batchId = :batch')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('batch', $batchId)
            ->setParameter('tenant', $tenant)
            ->orderBy('j.data', 'ASC')
            ->getQuery()
            ->getResult();

        return $lote;
    }

    /**
     * Data da justificativa ABONADA mais antiga do colaborador no escritório. Junto com a primeira
     * batida, define quando a folha começa a contar — um abono deferido também é registro de ponto.
     *
     * `$aPartirDe` limita a busca por baixo: serve para procurar o abono mais antigo DENTRO de uma
     * janela, sem que um abono retroativo antigo puxe o início da contagem para trás.
     * Retorna null quando não há nenhuma no recorte.
     */
    public function findDataPrimeiraAbonada(User $user, Tenant $tenant, ?\DateTimeInterface $aPartirDe = null): ?\DateTimeImmutable
    {
        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
        $qb = $this->createQueryBuilder('j')
            ->select('MIN(j.data)')
            ->andWhere('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.status = :status')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'abonado');

        if ($aPartirDe !== null) {
            $qb->andWhere('j.data >= :aPartirDe')->setParameter('aPartirDe', $aPartirDe);
        }

        $primeira = $qb->getQuery()->getSingleScalarResult();

        if ($primeira === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $primeira);
    }

    /**
     * Retorna todas as justificativas do usuário no mês/ano NESTE escritório, ordenadas por data desc.
     *
     * @return JustificativaPonto[]
     */
    public function findByUserAndCompetencia(User $user, Tenant $tenant, int $ano, int $mes): array
    {
        $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        $fim    = $inicio->modify('last day of this month')->setTime(23, 59, 59);

        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO, auditoria 1.13).
        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.data BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fim)
            ->orderBy('j.data', 'DESC')
            // Desempate obrigatório: nada impede duas justificativas na MESMA data (não há constraint
            // única em user+data, e 34 dias em produção têm de 2 a 3 — o caso comum é esquecer duas
            // batidas no mesmo dia). Sem esta segunda chave o SGBD não garante ordem entre elas, e a
            // escolha do dia mudaria de um carregamento para o outro.
            ->addOrderBy('j.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna as justificativas do mês indexadas por 'Y-m-d' (para uso no FolhaPontoBuilder).
     *
     * @return array<string, JustificativaPonto>
     */
    public function findByUserAndCompetenciaIndexed(User $user, Tenant $tenant, int $ano, int $mes): array
    {
        return $this->indexarPorDia($this->findByUserAndCompetencia($user, $tenant, $ano, $mes));
    }

    /**
     * As justificativas de um período inteiro NESTE escritório, indexadas por 'Y-m-d' como as do mês: o banco de horas
     * por lotes lê todos os meses desde o início da contagem numa consulta só (spec ponto-desenho-0811, E17). Mesma
     * ordem de `findByUserAndCompetencia` (id crescente dentro do dia), que é parte do desempate de `indexarPorDia`.
     *
     * @return array<string, JustificativaPonto>
     */
    public function findByUserEPeriodoIndexed(User $user, Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO, auditoria 1.13).
        return $this->indexarPorDia($this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.data BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $inicio->format('Y-m-d'))
            ->setParameter('fim', $fim->format('Y-m-d'))
            ->orderBy('j.data', 'DESC')
            ->addOrderBy('j.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Escolhe UMA justificativa por dia, por mérito e de forma determinística.
     *
     * O cálculo da folha só honra uma justificativa por dia, mas o sistema aceita várias — e aceita
     * de propósito: o caso comum em produção é esquecer duas batidas no mesmo dia e lançar uma
     * justificativa para cada, com o horário de cada uma. Quando as concorrentes se comportam igual
     * (dois esquecimentos, por exemplo) tanto faz quem vence; o problema é o dia que tem uma que
     * abona e outra que não.
     *
     * Precedência: **abonada que perdoa o déficit** > abonada que não perdoa (categoria técnica,
     * falta não justificada e a ligada a uma batida, E12) > pendente/rejeitada. Empate dentro do mesmo nível: vence a ÚLTIMA da
     * ordem recebida — que é a de maior id, porque `findByUserAndCompetencia` ordena por id
     * crescente. Quem chamar com uma lista fora dessa ordem recebe outro empate; a ordenação da
     * consulta é parte da regra, não enfeite.
     *
     * A regra de negócio: se o admin deferiu um atestado médico naquele dia, houve ausência
     * justificada — um "esquecimento de registro" lançado ao lado não apaga isso. Escolher pelo
     * que abona também é o que o sistema fazia antes de `abonaSaldo()` existir (qualquer abonada
     * zerava o negativo), então nenhum dia passa a ser cobrado a mais por causa do desempate.
     *
     * Público e puro para poder ser testado sem banco.
     *
     * @param JustificativaPonto[] $justificativas
     * @return array<string, JustificativaPonto>
     */
    public function indexarPorDia(array $justificativas): array
    {
        $indexed = [];
        foreach ($justificativas as $j) {
            $key = $j->getData()->format('Y-m-d');
            $atual = $indexed[$key] ?? null;

            if ($atual === null || $this->precedencia($j) >= $this->precedencia($atual)) {
                $indexed[$key] = $j;
            }
        }

        return $indexed;
    }

    /**
     * Peso da justificativa na disputa pelo dia. Maior vence; `>=` no chamador faz a de maior id
     * ganhar o empate, já que a consulta vem ordenada por id crescente.
     */
    private function precedencia(JustificativaPonto $justificativa): int
    {
        if ($justificativa->getStatus() !== 'abonado') {
            return 0;
        }

        // A ligada a uma batida (trabalho externo, E12) nunca abona, nem abonada: aprovar só confirma a batida.
        if ($justificativa->analisaUmaBatida()) {
            return 1;
        }

        $tipo = $justificativa->getTipo() !== null
            ? TipoJustificativa::tryFrom($justificativa->getTipo())
            : null;

        // Tipo nulo ou slug desconhecido abona — mesmo default do FolhaPontoBuilder, para que os
        // dois lugares não discordem sobre o que uma justificativa sem tipo faz.
        return ($tipo === null || $tipo->abonaSaldo()) ? 2 : 1;
    }

    /**
     * Retorna todas as justificativas do usuário NESTE escritório, ordenadas por data desc (ficha do funcionário).
     *
     * @return JustificativaPonto[]
     */
    public function findByTenantUser(User $targetUser, Tenant $tenant): array
    {
        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO, auditoria 1.13).
        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('user', $targetUser)
            ->setParameter('tenant', $tenant)
            ->orderBy('j.data', 'DESC')
            ->addOrderBy('j.batchId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As justificativas em análise NESTE escritório, de quem tem vínculo ativo nele (aba "Equipe" da /ponto, spec
     * `ponto-desenho-0811.md` E5, P57), com a pessoa já carregada: data mais nova primeiro, depois o nome. Escritório
     * escrito na consulta, além do filtro.
     *
     * @return list<JustificativaPonto>
     */
    public function aAnalisarNoEscritorio(Tenant $tenant): array
    {
        return $this->emAnaliseNoEscritorio($tenant)
            ->addSelect('u')
            ->orderBy('j.data', 'DESC')
            ->addOrderBy('u.fullName', 'ASC')
            ->addOrderBy('j.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Quantas `aAnalisarNoEscritorio` devolveria, sem as de `$semAsDe` (o número da aba "Equipe"). */
    public function contarAAnalisarNoEscritorio(Tenant $tenant, ?User $semAsDe = null): int
    {
        $qb = $this->emAnaliseNoEscritorio($tenant)->select('COUNT(j.id)');
        if ($semAsDe !== null) {
            $qb->andWhere('j.user <> :semAsDe')->setParameter('semAsDe', $semAsDe);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * As justificativas destes ids NESTE escritório, com a pessoa (abono em lote da aba "Equipe"). Id de outro
     * escritório ou que não existe não volta: escritório escrito na consulta, além do filtro.
     *
     * @param list<int> $ids
     *
     * @return array<int, JustificativaPonto> pelo id
     */
    public function doEscritorioPorIds(array $ids, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<JustificativaPonto> $achadas */
        $achadas = $this->createQueryBuilder('j')
            ->addSelect('u')
            ->join('j.user', 'u')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.id IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $porId = [];
        foreach ($achadas as $justificativa) {
            $porId[(int) $justificativa->getId()] = $justificativa;
        }

        return $porId;
    }

    /** Em análise, no escritório, de quem tem vínculo ativo nele. */
    private function emAnaliseNoEscritorio(Tenant $tenant): QueryBuilder
    {
        return $this->createQueryBuilder('j')
            ->join('j.user', 'u')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.status = :pendente')
            ->andWhere(sprintf(
                'EXISTS (SELECT ut.id FROM %s ut WHERE ut.user = j.user AND ut.tenant = j.tenant AND ut.isActive = true)',
                UserTenant::class,
            ))
            ->setParameter('tenant', $tenant)
            ->setParameter('pendente', 'pendente');
    }

    /**
     * Há justificativa EM ANÁLISE ligada à batida do alvo (E10, decisão C5): a do trabalho externo (E12), presa ao
     * registro original, ou a que gravou a inclusão (o esquecimento). Enquanto ela não for decidida, a ficha não ajusta
     * nem desconsidera a batida. Escritório explícito, além do filtro: é regra da batida.
     */
    public function temEmAnaliseLigadaA(AlvoDoAjuste $alvo): bool
    {
        $consulta = $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.status = :pendente')
            ->setParameter('tenant', $alvo->escritorio)
            ->setParameter('pendente', 'pendente');
        if ($alvo->raizInclusao !== null) {
            $daInclusao = $alvo->raizInclusao->getJustificativa();
            if ($daInclusao === null) {
                return false;
            }
            $consulta->andWhere('j.id = :ligada')->setParameter('ligada', $daInclusao->getId());
        } else {
            $consulta->andWhere('j.registroPonto = :batida')->setParameter('batida', $alvo->raizBatida);
        }

        return (int) $consulta->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Há esquecimento ABONADO da pessoa neste escritório para a batida `$tipo` no dia e na hora de `$quando`
     * (`SaidaDeEsquecimento`, spec ponto-esquecimento-nao-trava R4). Escritório explícito, além do filtro: é regra
     * da batida e não pode depender do filtro estar ligado.
     */
    public function existeEsquecimentoAbonado(User $user, Tenant $tenant, \DateTimeImmutable $quando, string $tipo): bool
    {
        $achadas = $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.tipo = :esquecimento')
            ->andWhere('j.status = :abonado')
            ->andWhere('j.tipoRegistroEsquecido = :tipo')
            ->andWhere('j.data = :dia')
            ->andWhere('j.horaRegistroEsquecido = :hora')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('esquecimento', TipoJustificativa::EsquecimentoRegistro->value)
            ->setParameter('abonado', 'abonado')
            ->setParameter('tipo', $tipo)
            ->setParameter('dia', $quando->format('Y-m-d'))
            ->setParameter('hora', $quando->format('H:i:s'))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $achadas > 0;
    }

    /**
     * Os pedidos de esquecimento da pessoa NAQUELE escritório no período (lista de pendências, spec
     * `ponto-esquecimento-nao-trava.md` R5): esconde a pendência que já tem pedido. Cross-tenant de propósito, como
     * `RegistroPontoRepository::batidasDoPeriodoNoEscritorio`: filtro suspenso e escritório escrito na consulta.
     *
     * @return list<JustificativaPonto>
     */
    public function esquecimentosDoPeriodoNoEscritorio(User $user, Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        return $this->semFiltroDeEscritorio(fn (): array => $this->createQueryBuilder('j')
            ->andWhere('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.tipo = :esquecimento')
            ->andWhere('j.data BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('esquecimento', TipoJustificativa::EsquecimentoRegistro->value)
            ->setParameter('inicio', $inicio->format('Y-m-d'))
            ->setParameter('fim', $fim->format('Y-m-d'))
            ->getQuery()
            ->getResult());
    }

    /**
     * O status do registro COMO ESTÁ NO BANCO, com a linha travada até o fim da transação.
     *
     * É a pergunta que decide se o colaborador ainda pode editar a justificativa
     * (docs/specs/ponto-edicao-justificativa-analisada.md, R2). Duas propriedades importam:
     *
     *  - projeção escalar: não passa pelo identity map, então o status vem do banco e não da
     *    entidade carregada pelo EntityValueResolver no começo da requisição;
     *  - `FOR UPDATE`: a análise do admin (`TenantController`) não pega trava nenhuma. Com a linha
     *    travada, um UPDATE de status do admin espera este COMMIT, e um que já comitou é visto aqui.
     *
     * Devolve null quando o registro não existe NESTE escritório. Sem transação aberta o Doctrine
     * lança `TransactionRequiredException`: a trava cairia na hora.
     */
    public function statusNoBancoTravadoPorId(int $id, Tenant $tenant): ?string
    {
        /** @var list<array{status: string|null}> $linhas */
        $linhas = $this->createQueryBuilder('j')
            ->select('j.status')
            ->andWhere('j.id = :id')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();

        return $linhas === [] ? null : (string) $linhas[0]['status'];
    }
}
