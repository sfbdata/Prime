<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Repository;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As versões guardadas do PDF de um documento (contrato da etapa 5, seção 2, item 2). Quem lê já tem o documento, lido
 * pelo escritório (tela interna) ou pelo token do signatário (tela pública): a versão não leva `tenant_id`, como a
 * trilha, e toda consulta parte do documento dado. Sem junção com o documento, a Lixeira não esconde as versões.
 *
 * @extends ServiceEntityRepository<VersaoDoPdf>
 */
class VersaoDoPdfRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VersaoDoPdf::class);
    }

    /** A versão mais recente (maior ordem): a que a próxima assinatura gov.br ou ICP tem de preservar. */
    public function ultima(DocumentoParaAssinar $documento): ?VersaoDoPdf
    {
        return $this->doDocumentoQb($documento)
            ->orderBy('v.ordem', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** O PDF que já chegou com assinaturas digitais, guardado ao salvar o rascunho; nulo sem ele. */
    public function original(DocumentoParaAssinar $documento): ?VersaoDoPdf
    {
        return $this->primeiraDaOrigem($documento, OrigemDaVersao::Original);
    }

    /** O PDF para assinar congelado na primeira geração (E5-1); nulo antes dela. */
    public function paraAssinar(DocumentoParaAssinar $documento): ?VersaoDoPdf
    {
        return $this->primeiraDaOrigem($documento, OrigemDaVersao::ParaAssinar);
    }

    /**
     * Todas as versões do documento, da primeira para a última (quem apaga o documento junta as chaves dos arquivos).
     *
     * @return list<VersaoDoPdf>
     */
    public function doDocumento(DocumentoParaAssinar $documento): array
    {
        return $this->doDocumentoQb($documento)
            ->orderBy('v.ordem', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** A ordem da versão seguinte. Quem cria a versão chama com a linha do documento travada. */
    public function proximaOrdem(DocumentoParaAssinar $documento): int
    {
        return ($this->ultima($documento)?->getOrdem() ?? 0) + 1;
    }

    /** Grava na hora: o índice único (documento, ordem) recusa duas versões com a mesma ordem. */
    public function salvar(VersaoDoPdf $versao): void
    {
        $em = $this->getEntityManager();
        $em->persist($versao);
        $em->flush();
    }

    /**
     * Apaga na hora (com `flush`) o `original` de um rascunho, para o original novo entrar em seguida sem bater nos
     * índices únicos (o Doctrine grava os INSERT antes dos DELETE num mesmo `flush`). O arquivo sai depois do COMMIT,
     * por quem chama ({@see \App\Shared\Armazenamento\RemocaoAposTransacao}).
     *
     * @throws \LogicException versão que não é o original de documento em rascunho
     */
    public function remover(VersaoDoPdf $versao): void
    {
        $versao->exigirRemovivel();
        $em = $this->getEntityManager();
        $em->remove($versao);
        $em->flush();
    }

    private function primeiraDaOrigem(DocumentoParaAssinar $documento, OrigemDaVersao $origem): ?VersaoDoPdf
    {
        return $this->doDocumentoQb($documento)
            ->andWhere('v.origem = :origem')
            ->setParameter('origem', $origem)
            ->orderBy('v.ordem', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function doDocumentoQb(DocumentoParaAssinar $documento): QueryBuilder
    {
        if ($documento->getId() === null) {
            throw new \LogicException('Documento sem id não tem versões gravadas.');
        }

        return $this->createQueryBuilder('v')
            ->andWhere('v.documento = :documento')
            ->setParameter('documento', $documento);
    }
}
