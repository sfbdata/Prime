<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Ponto\Entity\EventoDaTrilhaDoPonto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Leitura e gravação da trilha de auditoria do Ponto (`ponto_auditoria`, E9), por DBAL: a gravação não pode dar
 * `flush()` na unidade de trabalho de quem chama, e a leitura da cadeia inteira (conferência) vai em blocos, sem
 * hidratar entidades. Toda consulta filtra pelo escritório.
 *
 * Não é `final`: os testes dos UseCases que gravam eventos trocam este repositório por um dublê.
 *
 * @extends ServiceEntityRepository<EventoDaTrilhaDoPonto>
 *
 * @phpstan-type LinhaDaTrilha array{
 *     id: int, tenant_id: int, sequencia: int, em: string, autor_id: ?int, autor_nome: ?string, acao: string,
 *     detalhe: array<string, mixed>, selo_anterior: ?string, selo: ?string, versao_da_chave: ?int
 * }
 */
class TrilhaDoPontoRepository extends ServiceEntityRepository
{
    /** Classe de trava (primeiro argumento de pg_advisory_xact_lock): a trilha de um escritório. */
    public const CLASSE_TRAVA = 4203;

    private const COLUNAS = 'id, tenant_id, sequencia, em, autor_id, autor_nome, acao, detalhe, selo_anterior, selo, versao_da_chave';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventoDaTrilhaDoPonto::class);
    }

    /**
     * Roda o trabalho dentro de uma transação: a de quem chama, se houver, ou uma nova. A trava da trilha só vale
     * dentro de uma transação (cai no COMMIT), e o evento precisa comitar junto com o fato que ele registra.
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(\Closure $trabalho): mixed
    {
        $conexao = $this->conexao();
        if ($conexao->isTransactionActive()) {
            return $trabalho();
        }

        return $conexao->transactional(static fn (): mixed => $trabalho());
    }

    /** Trava a trilha do escritório até o fim da transação: dois eventos simultâneos gravam um depois do outro. */
    public function travar(int $tenantId): void
    {
        $this->conexao()->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, $tenantId]);
    }

    /**
     * O último elo da cadeia do escritório, lido depois da trava.
     *
     * @return array{sequencia: int, selo: ?string}|null nulo quando a trilha ainda está vazia
     */
    public function ultimoElo(int $tenantId): ?array
    {
        $linha = $this->conexao()->fetchAssociative(
            'SELECT sequencia, selo FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia DESC LIMIT 1',
            [$tenantId],
        );
        if ($linha === false) {
            return null;
        }

        return ['sequencia' => (int) $linha['sequencia'], 'selo' => self::textoOuNulo($linha['selo'])];
    }

    /**
     * @param array{tenant_id: int, sequencia: int, em: string, autor_id: ?int, autor_nome: ?string, acao: string,
     *     detalhe: array<string, mixed>, selo_anterior: ?string, selo: ?string, versao_da_chave: ?int} $linha
     */
    public function inserir(array $linha): void
    {
        $this->conexao()->executeStatement(
            'INSERT INTO ponto_auditoria (tenant_id, sequencia, em, autor_id, autor_nome, acao, detalhe, selo_anterior, selo, versao_da_chave)
             VALUES (:tenant_id, :sequencia, :em, :autor_id, :autor_nome, :acao, :detalhe, :selo_anterior, :selo, :versao_da_chave)',
            [
                'tenant_id'       => $linha['tenant_id'],
                'sequencia'       => $linha['sequencia'],
                'em'              => $linha['em'],
                'autor_id'        => $linha['autor_id'],
                'autor_nome'      => $linha['autor_nome'],
                'acao'            => $linha['acao'],
                'detalhe'         => json_encode($linha['detalhe'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                'selo_anterior'   => $linha['selo_anterior'],
                'selo'            => $linha['selo'],
                'versao_da_chave' => $linha['versao_da_chave'],
            ],
            [
                'autor_id'        => $linha['autor_id'] === null ? ParameterType::NULL : ParameterType::INTEGER,
                'versao_da_chave' => $linha['versao_da_chave'] === null ? ParameterType::NULL : ParameterType::INTEGER,
            ],
        );
    }

    /**
     * Um bloco da cadeia em ordem de sequência, a partir da sequência informada (exclusive): a conferência percorre a
     * trilha inteira sem carregá-la de uma vez.
     *
     * @return list<LinhaDaTrilha>
     */
    public function blocoDaCadeia(int $tenantId, int $depoisDaSequencia, int $tamanho): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT ' . self::COLUNAS . ' FROM ponto_auditoria WHERE tenant_id = ? AND sequencia > ? ORDER BY sequencia LIMIT ?',
            [$tenantId, $depoisDaSequencia, $tamanho],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return array_map(self::hidratar(...), $linhas);
    }

    /**
     * Uma página da aba Auditoria: o mais recente primeiro.
     *
     * @return list<LinhaDaTrilha>
     */
    public function pagina(int $tenantId, int $deslocamento, int $tamanho): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT ' . self::COLUNAS . ' FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia DESC LIMIT ? OFFSET ?',
            [$tenantId, $tamanho, $deslocamento],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return array_map(self::hidratar(...), $linhas);
    }

    public function contar(int $tenantId): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM ponto_auditoria WHERE tenant_id = ?', [$tenantId]);
    }

    /**
     * @param array<string, mixed> $linha
     *
     * @return LinhaDaTrilha
     */
    private static function hidratar(array $linha): array
    {
        $detalhe = json_decode((string) $linha['detalhe'], true, 512, \JSON_THROW_ON_ERROR);

        return [
            'id'              => (int) $linha['id'],
            'tenant_id'       => (int) $linha['tenant_id'],
            'sequencia'       => (int) $linha['sequencia'],
            'em'              => (string) $linha['em'],
            'autor_id'        => $linha['autor_id'] === null ? null : (int) $linha['autor_id'],
            'autor_nome'      => self::textoOuNulo($linha['autor_nome']),
            'acao'            => (string) $linha['acao'],
            'detalhe'         => is_array($detalhe) ? $detalhe : [],
            'selo_anterior'   => self::textoOuNulo($linha['selo_anterior']),
            'selo'            => self::textoOuNulo($linha['selo']),
            'versao_da_chave' => $linha['versao_da_chave'] === null ? null : (int) $linha['versao_da_chave'],
        ];
    }

    private static function textoOuNulo(mixed $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    private function conexao(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
