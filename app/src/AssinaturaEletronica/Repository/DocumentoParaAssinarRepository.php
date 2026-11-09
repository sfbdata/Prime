<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Repository;

use App\AssinaturaEletronica\DTO\ResumoDoDocumento;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\CorDaLinha;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\Entity\Tenant\Tenant;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda leitura leva o escritório à mão, além do `TenantFilter`: id de outro escritório volta nulo (404). As variantes
 * "mesmo na Lixeira" enxergam os documentos da Lixeira pela `AcessoALixeira`, com escopo mínimo.
 *
 * @extends ServiceEntityRepository<DocumentoParaAssinar>
 */
class DocumentoParaAssinarRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AcessoALixeira $lixeira,
    ) {
        parent::__construct($registry, DocumentoParaAssinar::class);
    }

    /** Fora da Lixeira. */
    public function doEscritorio(int $id, Tenant $tenant): ?DocumentoParaAssinar
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.id = :id')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function doEscritorioMesmoNaLixeira(int $id, Tenant $tenant): ?DocumentoParaAssinar
    {
        return $this->lixeira->comLixeiraVisivel(fn (): ?DocumentoParaAssinar => $this->doEscritorio($id, $tenant));
    }

    /**
     * Os documentos dos ids pedidos que são deste escritório, estejam ou não na Lixeira. Quem chama compara a contagem
     * para saber se algum id não é dele.
     *
     * @param list<int> $ids
     *
     * @return list<DocumentoParaAssinar>
     */
    public function porIdsMesmoNaLixeira(array $ids, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->lixeira->comLixeiraVisivel(fn (): array => $this->createQueryBuilder('d')
            ->andWhere('d.id IN (:ids)')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('ids', $ids)
            ->setParameter('tenant', $tenant)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * A lista da tela, Lixeira incluída, do mais novo para o mais antigo. Só os campos do `Resumo`: os blocos e o texto
     * lateral (que podem ser grandes) não são lidos. Os signatários vêm numa segunda consulta, pelos ids da primeira.
     *
     * @return list<ResumoDoDocumento>
     */
    public function resumosDoEscritorio(Tenant $tenant): array
    {
        /** @var list<array<string, mixed>> $linhas */
        $linhas = $this->lixeira->comLixeiraVisivel(fn (): array => $this->createQueryBuilder('d')
            ->select('d.id', 'd.nome', 'd.categoria', 'd.pastaRotulo', 'd.situacao', 'd.criadoEm', 'd.venceEm', 'd.cor', 'd.excluidoEm')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.criadoEm', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult());

        $signatarios = $this->signatariosDaLista($tenant, array_map(static fn (array $l): int => (int) $l['id'], $linhas));

        return array_map(static fn (array $l): ResumoDoDocumento => self::resumo($l, $signatarios[(int) $l['id']] ?? []), $linhas);
    }

    /**
     * Dos nomes de arquivo dados, os que algum documento do escritório ainda usa (PDF ou logo própria), Lixeira
     * incluída. Quem apaga arquivo depois do COMMIT confere aqui antes: arquivo em uso não sai.
     *
     * @param list<string> $nomes
     *
     * @return list<string>
     */
    public function arquivosEmUso(Tenant $tenant, array $nomes): array
    {
        if ($nomes === []) {
            return [];
        }

        /** @var list<array{pdf: ?string, logo: ?string}> $linhas */
        $linhas = $this->lixeira->comLixeiraVisivel(fn (): array => $this->createQueryBuilder('d')
            ->select('d.arquivoEnviadoChave AS pdf', 'd.logoChave AS logo')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.arquivoEnviadoChave IN (:nomes) OR d.logoChave IN (:nomes)')
            ->setParameter('tenant', $tenant)
            ->setParameter('nomes', $nomes)
            ->getQuery()
            ->getScalarResult());

        $usados = array_merge(array_column($linhas, 'pdf'), array_column($linhas, 'logo'));

        return array_values(array_filter($nomes, static fn (string $nome): bool => \in_array($nome, $usados, true)));
    }

    /**
     * Trava a linha do documento (`SELECT ... FOR UPDATE`) e relê o estado gravado por cima do que está em memória,
     * Lixeira incluída: quem decide pela situação ou pela Lixeira decide com o que está no banco, não com o que leu
     * antes da transação. Falso quando o documento não existe mais neste escritório. Exige transação aberta.
     */
    public function travarERecarregar(DocumentoParaAssinar $documento, Tenant $tenant): bool
    {
        $relido = $this->lixeira->comLixeiraVisivel(fn (): ?DocumentoParaAssinar => $this->createQueryBuilder('d')
            ->andWhere('d.id = :id')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('id', $documento->getId())
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult());

        return $relido === $documento;
    }

    /**
     * A porta da tela pública: o signatário do hash do token, com o documento, mesmo na Lixeira (a página mostra "Envio
     * cancelado", não "Link inválido."). Sem escritório na consulta: o token é a chave, e o escritório sai do próprio
     * documento daí em diante. O filtro de escritório já foi desligado nas rotas públicas pelo listener.
     */
    public function signatarioPeloTokenHash(string $tokenHash): ?Signatario
    {
        return $this->lixeira->comLixeiraVisivel(fn (): ?Signatario => $this->getEntityManager()->createQueryBuilder()
            ->select('s', 'd')
            ->from(Signatario::class, 's')
            ->innerJoin('s.documento', 'd')
            ->andWhere('s.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult());
    }

    /**
     * Relê do banco os signatários do documento, por cima do que está em memória. Vem depois de
     * {@see travarERecarregar()}: a decisão (vez, código, situação) usa o que está gravado. Exige transação aberta.
     */
    public function recarregarSignatarios(DocumentoParaAssinar $documento): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Signatario::class, 's')
            ->andWhere('s.documento = :documento')
            ->setParameter('documento', $documento)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /** Grava e confirma o documento (o id nasce aqui no documento novo). */
    public function salvar(DocumentoParaAssinar $documento): void
    {
        $em = $this->getEntityManager();
        $em->persist($documento);
        $em->flush();
    }

    /** Apaga de vez (o `flush` é da transação de quem chama): signatários e eventos saem junto, pelo CASCADE. */
    public function remover(DocumentoParaAssinar $documento): void
    {
        $this->getEntityManager()->remove($documento);
    }

    /**
     * Nome e situação dos signatários de cada documento da lista, na ordem do documento.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array{nome: string, situacao: SituacaoDoSignatario}>> por id do documento
     */
    private function signatariosDaLista(Tenant $tenant, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $linhas */
        $linhas = $this->lixeira->comLixeiraVisivel(fn (): array => $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(s.documento) AS documento', 's.nome', 's.situacao')
            ->from(Signatario::class, 's')
            ->innerJoin('s.documento', 'd')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.id IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $ids)
            ->orderBy('s.ordem', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult());

        $porDocumento = [];
        foreach ($linhas as $l) {
            $porDocumento[(int) $l['documento']][] = [
                'nome' => (string) $l['nome'],
                'situacao' => $l['situacao'] instanceof SituacaoDoSignatario ? $l['situacao'] : SituacaoDoSignatario::from((string) $l['situacao']),
            ];
        }

        return $porDocumento;
    }

    /**
     * @param array<string, mixed>                                      $linha
     * @param list<array{nome: string, situacao: SituacaoDoSignatario}> $signatarios
     */
    private static function resumo(array $linha, array $signatarios): ResumoDoDocumento
    {
        return new ResumoDoDocumento(
            id: (int) $linha['id'],
            nome: (string) $linha['nome'],
            categoria: (string) $linha['categoria'],
            pasta: isset($linha['pastaRotulo']) ? (string) $linha['pastaRotulo'] : null,
            situacao: $linha['situacao'] instanceof SituacaoDoDocumento ? $linha['situacao'] : SituacaoDoDocumento::from((string) $linha['situacao']),
            criadoEm: self::data($linha['criadoEm']) ?? throw new \LogicException('Documento sem data de criação.'),
            venceEm: self::data($linha['venceEm'] ?? null),
            cor: $linha['cor'] instanceof CorDaLinha ? $linha['cor'] : CorDaLinha::from((string) $linha['cor']),
            naLixeira: ($linha['excluidoEm'] ?? null) !== null,
            signatarios: $signatarios,
        );
    }

    private static function data(mixed $valor): ?\DateTimeImmutable
    {
        return $valor instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($valor) : null;
    }

    /**
     * Roda o trabalho numa transação (o acréscimo à trilha trava a linha do documento e exige uma).
     *
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(callable $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $trabalho());
    }
}
