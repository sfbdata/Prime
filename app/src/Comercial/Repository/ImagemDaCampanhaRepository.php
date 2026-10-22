<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\ImagemDaCampanha;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As imagens das campanhas do modo simples (spec `comercial-e-emails.md` §12, F3i). Plataforma, do Master: a tabela
 * não tem `tenant_id` e nenhuma consulta filtra por escritório (o envio é `ROLE_SUPER_ADMIN`; a leitura pública só
 * acha a imagem pelo token, que é aleatório).
 *
 * A validade muda por SQL na conexão, para entrar na transação de quem chama (a confirmação da campanha e a execução
 * do expansor rodam na transação da conexão DBAL): `expira_em` só cresce (`GREATEST`).
 *
 * @extends ServiceEntityRepository<ImagemDaCampanha>
 */
class ImagemDaCampanhaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImagemDaCampanha::class);
    }

    /** Só o `persist`: quem confirma é a `TransacaoComArquivoNovo` do caso de uso, com o arquivo recém-gravado. */
    public function adicionar(ImagemDaCampanha $imagem): void
    {
        $this->getEntityManager()->persist($imagem);
    }

    /** A imagem do token que ainda não venceu (`expira_em >= agora`); fora do formato, nem consulta. */
    public function vigente(string $token, \DateTimeImmutable $agora): ?ImagemDaCampanha
    {
        if (preg_match(ImagemDaCampanha::PADRAO_DO_TOKEN, $token) !== 1) {
            return null;
        }
        $imagem = $this->findOneBy(['token' => $token]);

        return $imagem !== null && $imagem->vigente($agora) ? $imagem : null;
    }

    /**
     * Empurra a validade para pelo menos `$ate` (nunca encurta) e, quando a imagem foi usada num envio, marca o uso.
     */
    public function prolongar(string $token, \DateTimeImmutable $ate, ?\DateTimeImmutable $usadaEm = null): void
    {
        $sql = 'UPDATE comercial_imagem SET expira_em = GREATEST(expira_em, :ate)'
            . ($usadaEm !== null ? ', ultimo_uso_em = :uso' : '')
            . ' WHERE token = :token';
        $parametros = ['ate' => $ate, 'token' => $token] + ($usadaEm !== null ? ['uso' => $usadaEm] : []);
        $tipos = ['ate' => Types::DATETIME_IMMUTABLE] + ($usadaEm !== null ? ['uso' => Types::DATETIME_IMMUTABLE] : []);

        $this->getEntityManager()->getConnection()->executeStatement($sql, $parametros, $tipos);
    }

    public function contarVencidas(\DateTimeImmutable $agora): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM comercial_imagem WHERE expira_em < :agora',
            ['agora' => $agora],
            ['agora' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Apaga as vencidas (`expira_em < agora`) num único DELETE, que se confirma sozinho, e devolve as chaves no storage:
     * quem chama remove os arquivos DEPOIS (INV-6).
     *
     * @return list<string>
     */
    public function apagarVencidas(\DateTimeImmutable $agora): array
    {
        $nomes = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'DELETE FROM comercial_imagem WHERE expira_em < :agora RETURNING nome_arquivo',
            ['agora' => $agora],
            ['agora' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(static fn (mixed $nome): string => (string) $nome, $nomes);
    }
}
