<?php

declare(strict_types=1);

namespace App\Mcp\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Conexão SOMENTE LEITURA com o banco, usada apenas pelas ferramentas MCP.
 *
 * Construída à mão com DriverManager, e não como uma segunda conexão do bundle do Doctrine,
 * de propósito: declarar `doctrine.dbal.connections` obrigaria a reestruturar a conexão
 * padrão do sistema inteiro. Aqui o raio de alcance é zero — nada fora do MCP enxerga isto.
 *
 * A tranca real é o usuário do PostgreSQL, que só tem SELECT. As duas linhas de `SET` abaixo
 * são cinto e suspensório: ajudam contra engano, mas não são a garantia.
 */
final class ConexaoLeitura
{
    private ?Connection $conexao = null;

    public function __construct(
        private readonly string $dsn,
        private readonly int $timeoutSegundos = 15,
    ) {}

    public function conexao(): Connection
    {
        if ($this->conexao !== null) {
            return $this->conexao;
        }

        if (trim($this->dsn) === '') {
            throw new \RuntimeException(
                'DATABASE_URL_LEITURA não está configurada. O MCP não sobe sem a conexão de '
                . 'leitura — configure a variável no .env.prod da VPS.',
            );
        }

        $parser = new DsnParser(['pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);
        $conexao = DriverManager::getConnection($parser->parse($this->dsn));

        $conexao->executeStatement(sprintf('SET statement_timeout = %d', $this->timeoutSegundos * 1000));
        $conexao->executeStatement('SET default_transaction_read_only = on');

        return $this->conexao = $conexao;
    }

    /**
     * Lê linha a linha, parando na primeira além do teto. Carregar tudo para cortar depois
     * derrubaria o processo por memória numa consulta grande — e aí o teto não teria servido
     * para nada.
     *
     * @param array<string, mixed> $parametros Parâmetros ligados (`:nome` no SQL)
     *
     * @return array{colunas: list<string>, linhas: list<array<string, mixed>>, truncado: bool}
     */
    public function consultar(string $sql, int $limite, array $parametros = []): array
    {
        $resultado = $this->conexao()->executeQuery($sql, $parametros);

        $linhas = [];
        $truncado = false;

        while (($linha = $resultado->fetchAssociative()) !== false) {
            if (count($linhas) >= $limite) {
                $truncado = true;
                break;
            }
            $linhas[] = $linha;
        }

        $resultado->free();

        return [
            'colunas'  => $linhas === [] ? [] : array_keys($linhas[0]),
            'linhas'   => $linhas,
            'truncado' => $truncado,
        ];
    }
}
