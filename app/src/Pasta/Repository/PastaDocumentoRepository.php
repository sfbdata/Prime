<?php

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Enum\OrigemDaAssinatura;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaDocumento>
 */
class PastaDocumentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaDocumento::class);
    }

    /** @return PastaDocumento[] */
    /**
     * Chaves (`caminho_arquivo`) das peças de texto — os documentos HTML — de um escritório.
     *
     * Usado por `ArquivosReferenciadosEmPecas` para saber quais arquivos precisam ser lidos em
     * busca de referências embutidas. O filtro de tenant é EXPLÍCITO (não depende só do
     * TenantFilter): esta consulta alimenta decisões sobre apagar arquivo, e aqui um vazamento
     * de escopo não daria erro — daria exclusão errada.
     *
     * @return string[]
     */
    public function chavesDePecasHtmlDoTenant(Tenant $tenant): array
    {
        /** @var string[] $chaves */
        $chaves = $this->createQueryBuilder('d')
            ->select('d.caminhoArquivo')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.mimeType = :mime')
            ->setParameter('tenant', $tenant)
            ->setParameter('mime', 'text/html')
            ->getQuery()
            ->getSingleColumnResult();

        return $chaves;
    }

    /**
     * As chaves das peças HTML de algumas pastas do escritório (a imagem embutida na peça e o cadeado,
     * spec pasta-cadeado D5).
     *
     * @param list<int> $pastaIds
     *
     * @return string[]
     */
    public function chavesDePecasHtmlDasPastas(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        /** @var string[] $chaves */
        $chaves = $this->createQueryBuilder('d')
            ->select('d.caminhoArquivo')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.mimeType = :mime')
            ->andWhere('IDENTITY(d.pasta) IN (:pastas)')
            ->setParameter('tenant', $tenant)
            ->setParameter('mime', 'text/html')
            ->setParameter('pastas', $pastaIds)
            ->getQuery()
            ->getSingleColumnResult();

        return $chaves;
    }

    /**
     * Documentos do escritório com o MESMO conteúdo (`sha256` igual) — a consulta por trás do aviso
     * "este arquivo já existe em…" do upload.
     *
     * Filtro de tenant EXPLÍCITO (não depende do TenantFilter): o resultado aponta para pastas de
     * outras telas, e um vazamento aqui mostraria a um escritório o título e o número de pasta de
     * outro. Pastas excluídas (lápide) ficam de fora — o arquivo delas não está "em uso". Linhas com
     * `sha256` NULL nunca casam (NULL = ainda não calculado). A permissão por pasta NÃO é daqui: é
     * do use case, que filtra com o `PermissionChecker`.
     *
     * @return list<PastaDocumento> com a pasta já carregada, do mais antigo ao mais novo
     */
    public function comOMesmoConteudo(Tenant $tenant, string $sha256, ?int $excetoDocumentoId = null, int $limite = 50): array
    {
        $qb = $this->createQueryBuilder('d')
            ->addSelect('p')
            ->innerJoin('d.pasta', 'p')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.sha256 = :sha256')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('sha256', $sha256)
            ->orderBy('d.carregadoEm', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->setMaxResults($limite);

        if ($excetoDocumentoId !== null) {
            $qb->andWhere('d.id <> :excetoId')->setParameter('excetoId', $excetoDocumentoId);
        }

        /** @var list<PastaDocumento> $documentos */
        $documentos = $qb->getQuery()->getResult();

        return $documentos;
    }

    /**
     * Ids dos documentos ainda sem hash, do mais antigo ao mais novo — a fila de
     * `app:documentos:calcular-hash`. Só ids (escalar) para o comando percorrer em lotes sem
     * hidratar o acervo inteiro.
     *
     * @return list<int>
     */
    public function idsSemSha256(?int $tenantId = null, ?int $limite = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.sha256 IS NULL')
            ->orderBy('d.id', 'ASC');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        if ($limite !== null) {
            $qb->setMaxResults($limite);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    public function contarSemSha256(?int $tenantId = null): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.sha256 IS NULL');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Ids dos PDFs ainda sem contagem de páginas, do mais antigo ao mais novo — a fila de
     * `app:documentos:calcular-hash --paginas`. Só PDF: os outros tipos não têm página e ficam
     * NULL de propósito.
     *
     * @return list<int>
     */
    public function idsSemPaginas(?int $tenantId = null, ?int $limite = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.paginas IS NULL')
            ->andWhere('d.mimeType = :pdf')
            ->setParameter('pdf', 'application/pdf')
            ->orderBy('d.id', 'ASC');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        if ($limite !== null) {
            $qb->setMaxResults($limite);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    public function contarSemPaginas(?int $tenantId = null): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.paginas IS NULL')
            ->andWhere('d.mimeType = :pdf')
            ->setParameter('pdf', 'application/pdf');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Um documento pelo id, DENTRO do escritório — o guard IDOR das rotas que recebem só o id do
     * documento na URL. Filtro explícito: não depende de o TenantFilter estar ligado.
     */
    /** Documentos vivos (fora da lixeira) da pasta: a sugestão "Documentação" do andamento conta por eles. */
    public function contarVivosDaPasta(Pasta $pasta, Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.excluidoEm IS NULL')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByIdAndTenant(int $id, Tenant $tenant): ?PastaDocumento
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.id = :id')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os documentos de $ids que pertencem a ESTA pasta e a ESTE escritório — a prova de posse em
     * lote (D4). Quem chama compara a contagem com a de ids pedidos: qualquer id que não voltou
     * é de outra pasta (irmã do mesmo escritório ou de outro) ou não existe, e a resposta é 404
     * sem efeito parcial. Uma query só, com a seção carregada.
     *
     * @param list<int> $ids
     *
     * @return list<PastaDocumento>
     */
    public function findTodosDaPasta(array $ids, Pasta $pasta, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<PastaDocumento> $documentos */
        $documentos = $this->createQueryBuilder('d')
            ->addSelect('s')
            ->leftJoin('d.secao', 's')
            ->andWhere('d.id IN (:ids)')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('ids', $ids)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $documentos;
    }

    /**
     * Todos os documentos de uma pasta, com a seção e quem enviou JÁ carregados, na ordem manual —
     * a consulta única por trás do explorador da aba Documentos (`ExploradorDeDocumentosOutput`).
     *
     * Uma query só: a pasta de produção com 1.128 documentos não pode acordar 1.128 proxies de
     * seção (nem de usuário) na renderização. Filtro de tenant EXPLÍCITO além do TenantFilter: o
     * JSON que sai daqui vira linha na tela, e uma linha de outro escritório seria vazamento, não
     * erro.
     *
     * @return list<PastaDocumento>
     */
    public function findByPastaComSecao(Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<PastaDocumento> $documentos */
        $documentos = $this->createQueryBuilder('d')
            ->addSelect('s', 'u')
            ->leftJoin('d.secao', 's')
            ->leftJoin('d.enviadoPor', 'u')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('d.ordem', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $documentos;
    }

    public function findByPastaECategoria(Pasta $pasta, string $categoria): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.categoria = :categoria')
            ->setParameter('pasta', $pasta)
            ->setParameter('categoria', $categoria)
            ->orderBy('d.carregadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // ── Lixeira (D7) — só fazem sentido com o LixeiraFilter desligado (`AcessoALixeira`) ─────────

    /**
     * Os documentos de $ids que estão NA LIXEIRA desta pasta e deste escritório — a prova de posse
     * do restaurar, com a mesma regra do `findTodosDaPasta`: contagem diferente da pedida → 404
     * sem efeito. A condição `excluidoEm IS NOT NULL` é explícita: com o filtro ligado a consulta
     * não acharia nada, e com ele desligado não pode devolver item vivo como se restaurável.
     *
     * @param list<int> $ids
     *
     * @return list<PastaDocumento> com a seção (viva ou na lixeira) já carregada
     */
    public function findNaLixeiraDaPasta(array $ids, Pasta $pasta, Tenant $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<PastaDocumento> $documentos */
        $documentos = $this->createQueryBuilder('d')
            ->addSelect('s')
            ->leftJoin('d.secao', 's')
            ->andWhere('d.id IN (:ids)')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.excluidoEm IS NOT NULL')
            ->setParameter('ids', $ids)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $documentos;
    }

    /**
     * Tudo que está na lixeira de uma pasta, do excluído mais recente ao mais antigo, com a seção
     * e quem excluiu já carregados (uma query — é a listagem da UI da lixeira).
     *
     * @return list<PastaDocumento>
     */
    public function listarLixeiraDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<PastaDocumento> $documentos */
        $documentos = $this->createQueryBuilder('d')
            ->addSelect('s', 'u')
            ->leftJoin('d.secao', 's')
            ->leftJoin('d.excluidoPor', 'u')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.excluidoEm IS NOT NULL')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('d.excluidoEm', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $documentos;
    }

    /**
     * A fila da purga: ids dos documentos excluídos ANTES de $corte, do mais antigo ao mais novo.
     * Só ids (escalar) para o comando percorrer em lotes. `$tenantId` recorta a um escritório
     * (`--tenant`); sem ele a fila é da instalação inteira.
     *
     * @return list<int>
     */
    public function idsNaLixeiraVencida(\DateTimeImmutable $corte, ?int $limite = null, ?int $tenantId = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.excluidoEm IS NOT NULL')
            ->andWhere('d.excluidoEm < :corte')
            ->setParameter('corte', $corte)
            ->orderBy('d.excluidoEm', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        if ($limite !== null) {
            $qb->setMaxResults($limite);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    public function contarNaLixeiraVencida(\DateTimeImmutable $corte, ?int $tenantId = null): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.excluidoEm IS NOT NULL')
            ->andWhere('d.excluidoEm < :corte')
            ->setParameter('corte', $corte);

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Inteligência cadastral do cliente (F-A): os documentos de identificação e comprovante de residência destas pastas,
     * fora da lixeira, deste escritório (tenant e lixeira explícitos), o mais novo primeiro. Quem chama já conferiu que
     * as pastas são do cliente e que o usuário pode vê-las.
     *
     * @param list<Pasta> $pastas
     *
     * @return list<PastaDocumento>
     */
    public function identificacaoEComprovanteDasPastas(array $pastas, Tenant $tenant): array
    {
        if ($pastas === []) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->andWhere('d.pasta IN (:pastas)')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.excluidoEm IS NULL')
            ->andWhere('d.categoria IN (:categorias)')
            ->setParameter('pastas', $pastas)
            ->setParameter('tenant', $tenant)
            ->setParameter('categorias', [PastaDocumento::CATEGORIA_IDENTIFICACAO, PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA])
            ->orderBy('d.carregadoEm', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Título, nome do arquivo e categoria dos documentos (fora da lixeira) das pastas pedidas, para o
     * relatório de pastas sem processo. Uma consulta para o lote; tenant pela pasta.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, list<string>> pastaId => "título nome categoria"
     */
    public function textosParaAnaliseSemProcesso(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.pasta) AS pasta_id', 'd.titulo', 'd.nomeOriginal', 'd.categoria')
            ->join('d.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.id IN (:ids)')
            // A lixeira já sai pelo LixeiraFilter na tela; o comando roda sem filtro nenhum.
            ->andWhere('d.excluidoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $pastaIds)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $porPasta = [];
        foreach ($linhas as $l) {
            $porPasta[(int) $l['pasta_id']][] = trim($l['titulo'] . ' ' . $l['nomeOriginal'] . ' ' . $l['categoria']);
        }

        return $porPasta;
    }

    /**
     * Título, nome do arquivo e categoria dos documentos (fora da lixeira) das pastas pedidas, para o
     * relatório de pastas paradas saber se a procuração e o contrato de honorários estão juntados.
     * Uma consulta para o lote; tenant pela pasta.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, list<array{titulo: string, nomeOriginal: string, categoria: string}>> pastaId => documentos
     */
    public function documentosParaPastasParadas(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.pasta) AS pasta_id', 'd.titulo', 'd.nomeOriginal', 'd.categoria')
            ->join('d.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.id IN (:ids)')
            // A lixeira já sai pelo LixeiraFilter na tela; o comando roda sem filtro nenhum.
            ->andWhere('d.excluidoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $pastaIds)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $porPasta = [];
        foreach ($linhas as $l) {
            $porPasta[(int) $l['pasta_id']][] = [
                'titulo'       => (string) $l['titulo'],
                'nomeOriginal' => (string) $l['nomeOriginal'],
                'categoria'    => (string) $l['categoria'],
            ];
        }

        return $porPasta;
    }

    /**
     * Fila de `app:pasta:detectar-assinatura-antigos`: documentos ainda sem o selo "Assinado" que
     * podem trazer assinatura digital (PDF, `.p7s`, `.p7m`), do mais antigo ao mais novo. Só ids,
     * para o comando percorrer em lotes. Marcado (digital ou manual) não entra: rodar de novo não
     * relê nada e a marcação manual nunca é tocada.
     *
     * @return list<int>
     */
    public function idsParaDetectarAssinatura(?int $tenantId = null, ?int $limite = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.assinadoEm IS NULL')
            ->andWhere("(d.mimeType = 'application/pdf' OR LOWER(d.nomeOriginal) LIKE '%.p7s' OR LOWER(d.nomeOriginal) LIKE '%.p7m')")
            ->orderBy('d.id', 'ASC');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        if ($limite !== null) {
            $qb->setMaxResults($limite);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    /**
     * Grava o selo `digital` direto no banco, só se o documento AINDA está sem selo: o UPDATE confere o
     * id, o escritório e a pasta do documento e `assinado_em IS NULL`. Devolve false quando nada mudou
     * (alguém marcou entre a leitura e a gravação), e a marca de quem chegou antes fica. A data é a do
     * upload e o autor é quem enviou, como no `marcarAssinaturaDoUpload()`.
     */
    public function marcarAssinaturaDigitalSeSemSelo(PastaDocumento $doc): bool
    {
        $afetadas = $this->getEntityManager()->createQueryBuilder()
            ->update(PastaDocumento::class, 'd')
            ->set('d.assinadoEm', ':em')
            ->set('d.assinaturaOrigem', ':origem')
            ->set('d.assinadoPor', ':por')
            ->where('d.id = :id')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.assinadoEm IS NULL')
            ->setParameter('em', $doc->getCarregadoEm(), Types::DATETIME_IMMUTABLE)
            ->setParameter('origem', OrigemDaAssinatura::Digital->value)
            ->setParameter('por', $doc->getEnviadoPor()?->getId())
            ->setParameter('id', $doc->getId())
            ->setParameter('tenant', $doc->getTenant()?->getId())
            ->setParameter('pasta', $doc->getPasta()?->getId())
            ->getQuery()
            ->execute();

        return $afetadas > 0;
    }

    /** Documentos que já têm o selo (digital ou manual): os "pulados" da varredura. */
    public function contarJaAssinados(?int $tenantId = null): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.assinadoEm IS NOT NULL');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** Grava o que mudou nos documentos já gerenciados (a costura que o teste de UseCase mocka). */
    public function salvar(): void
    {
        $this->getEntityManager()->flush();
    }
}
