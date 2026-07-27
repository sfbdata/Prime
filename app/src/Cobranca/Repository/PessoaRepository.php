<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Service\NormalizadorDocumento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pessoa>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class PessoaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pessoa::class);
    }

    public function salvar(Pessoa $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Pessoa $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Pessoa
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Opções (rótulo legível => id) das Pessoas do tenant, ordenadas por nome, para popular o ChoiceType
     * de seleção (vincular pessoa / trocar pessoa cobrada — Onda 8B). Escalares via DQL (leve, sem expor
     * Doctrine ao Twig); tenant SEMPRE explícito (defesa em profundidade).
     *
     * O VALOR é sempre o id — é ele que o formulário submete e o UseCase resolve (por id + tenant). A
     * chave é só ROTULAGEM. Até 2026-07-26 a chave era o nome puro, e homônimos se sobrescreviam no
     * mapa: medido no dev, 125 pessoas produziam 110 opções — 15 sumiam do select, e escolher um nome
     * repetido podia selecionar o registro errado (isso decide QUEM é cobrado). Agora o nome repetido
     * ganha um desempate visível — documento, e-mail ou o próprio id — e o laço de unicidade garante
     * que nenhuma opção apague outra, custe o que custar o cadastro.
     *
     * O desempate só aparece em nome repetido: quem tem nome único continua exibido como antes, sem
     * expor documento a mais na tela.
     *
     * @return array<string, int>
     */
    public function opcoesDoTenant(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('p')
            ->select('p.id AS id', 'p.nome AS nome', 'p.cpf AS cpf', 'p.cnpj AS cnpj', 'p.email AS email')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.nome', 'ASC')
            // Desempate estável: sem ele a ordem entre homônimos é indefinida no Postgres.
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $ocorrencias = [];
        foreach ($linhas as $linha) {
            $nome = (string) $linha['nome'];
            $ocorrencias[$nome] = ($ocorrencias[$nome] ?? 0) + 1;
        }

        $opcoes = [];
        foreach ($linhas as $linha) {
            $id = (int) $linha['id'];
            $nome = (string) $linha['nome'];
            $rotulo = $ocorrencias[$nome] > 1
                ? sprintf('%s (%s)', $nome, self::desempate($linha, $id))
                : $nome;

            // Invariante do mapa: rótulo repetido APAGARIA a opção anterior. Cobre até o patológico —
            // dois homônimos com o mesmo documento, ou um nome cadastrado já contendo o sufixo de
            // outro. Termina sempre: cada volta alonga a string e as chaves existentes são finitas.
            while (isset($opcoes[$rotulo])) {
                $rotulo .= ' #' . $id;
            }

            $opcoes[$rotulo] = $id;
        }

        return $opcoes;
    }

    /**
     * Dado já disponível que diferencia dois homônimos do mesmo escritório, na ordem em que um humano
     * reconhece a pessoa. Sem nenhum deles, o id — feio, mas único e estável.
     *
     * @param array{cpf?: ?string, cnpj?: ?string, email?: ?string} $linha
     */
    private static function desempate(array $linha, int $id): string
    {
        $cpf = trim((string) ($linha['cpf'] ?? ''));
        if ($cpf !== '') {
            return 'CPF ' . $cpf;
        }

        $cnpj = trim((string) ($linha['cnpj'] ?? ''));
        if ($cnpj !== '') {
            return 'CNPJ ' . $cnpj;
        }

        $email = trim((string) ($linha['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }

        return '#' . $id;
    }

    /**
     * Pessoas do MESMO tenant cujo CPF ou CNPJ coincide (por DÍGITOS) com os informados — suporte
     * à sugestão advisory de duplicidades (SPEC §7/§24; Etapa 7). Escopo intra-tenant SEMPRE; nunca
     * atravessa escritórios (invariável 24). Sem documentos informados, retorna vazio.
     *
     * A comparação ignora a formatação armazenada: `regexp_replace(..., '\D', '', 'g')` reduz o
     * valor gravado a dígitos, então "123.456.789-01" casa com "12345678901". O parâmetro também é
     * reduzido a dígitos AQUI (fronteira auto-defensiva): qualquer chamador — inclusive o futuro
     * importador da Etapa 7 — pode passar o documento formatado sem perder o match. Índices
     * funcionais equivalentes dão performance (migration Version20260710130000). Usa consulta nativa
     * porque o DQL não expõe `regexp_replace`; hidrata entidades `Pessoa` via ResultSetMappingBuilder.
     *
     * @return Pessoa[]
     */
    public function buscarPossiveisDuplicadas(Tenant $tenant, ?string $cpf, ?string $cnpj): array
    {
        $cpf = NormalizadorDocumento::apenasDigitos($cpf);
        $cnpj = NormalizadorDocumento::apenasDigitos($cnpj);

        if ($cpf === null && $cnpj === null) {
            return [];
        }

        $em = $this->getEntityManager();
        $rsm = new ResultSetMappingBuilder($em);
        $rsm->addRootEntityFromClassMetadata(Pessoa::class, 'p');
        $select = $rsm->generateSelectClause(['p' => 'p']);

        $condicoes = [];
        $parametros = ['tenant' => $tenant->getId()];
        if ($cpf !== null) {
            $condicoes[] = "regexp_replace(coalesce(p.cpf, ''), '\\D', '', 'g') = :cpf";
            $parametros['cpf'] = $cpf;
        }
        if ($cnpj !== null) {
            $condicoes[] = "regexp_replace(coalesce(p.cnpj, ''), '\\D', '', 'g') = :cnpj";
            $parametros['cnpj'] = $cnpj;
        }

        $sql = "SELECT {$select} FROM cobranca_pessoa p"
            . ' WHERE p.tenant_id = :tenant AND (' . implode(' OR ', $condicoes) . ')';

        return $em->createNativeQuery($sql, $rsm)
            ->setParameters($parametros)
            ->getResult();
    }
}
