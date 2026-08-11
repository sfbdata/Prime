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
 * são cinto e suspensório: ajudam contra engano, mas não são a garantia — qualquer chamador
 * desliga o `default_transaction_read_only` com um `SET` comum, sem privilégio nenhum, de
 * dentro da própria `consultar_sql`.
 *
 * Por isso `conexao()` CONFERE a tranca em vez de confiar nela: se o usuário do DSN puder
 * escrever, a conexão é recusada. Antes disso, a promessa inteira dependia de um passo manual do
 * runbook (apontar `DATABASE_URL_LEITURA` para a role certa) que nada verificava — e "a leitura
 * funciona" não distingue a role certa da errada.
 */
final class ConexaoLeitura
{
    /**
     * `bool_or` sobre as tabelas comuns (`relkind = 'r'`) do schema `public`: verdadeiro se o
     * usuário conectado puder gravar em QUALQUER uma delas.
     */
    private const SQL_PODE_ESCREVER = "SELECT bool_or(has_table_privilege(current_user, c.oid, 'INSERT'))
             FROM pg_class c
             WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'r'";

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

        $this->recusarSePuderEscrever($conexao);

        return $this->conexao = $conexao;
    }

    /**
     * Recusa a conexão se o usuário do DSN tiver permissão de escrita.
     *
     * Vem DEPOIS dos dois `SET` de propósito: o `SET ... read_only = on` não atrapalha a
     * consulta (é um SELECT) e assim a conexão nasce já travada mesmo no caminho de erro.
     *
     * Tipo do retorno: com `pdo_pgsql` no PHP 8.2 o `fetchOne` devolve booleano NATIVO
     * (`bool(true)`/`bool(false)`) — medido, e provado em `ConexaoLeituraTest`. A lista de
     * variantes textuais abaixo é para não depender dessa medição valer em toda versão de
     * driver: `'t'`/`'true'`/`'1'` são as formas que um driver que devolvesse string usaria.
     */
    private function recusarSePuderEscrever(Connection $conexao): void
    {
        $podeEscrever = $conexao->fetchOne(self::SQL_PODE_ESCREVER);

        // `bool_or` sobre conjunto vazio devolve NULL: não existe UMA tabela sequer no schema
        // `public`. Isso não é "seguro", é banco anômalo (sem migração aplicada, schema errado
        // no DSN) — e nesse estado a verificação não conseguiu concluir nada. A invariante falha
        // FECHADA: recusa, em vez de deixar passar um DSN que nunca chegou a ser conferido.
        if ($podeEscrever === null) {
            throw new \RuntimeException(
                'Não há nenhuma tabela no schema "public" do banco apontado por '
                . 'DATABASE_URL_LEITURA, então não foi possível conferir se o usuário é mesmo '
                . 'somente-leitura. O servidor MCP se recusa a subir sem essa conferência — '
                . 'confira o banco e o schema no DSN.',
            );
        }

        if (in_array($podeEscrever, [true, 't', 'true', '1', 1], true)) {
            throw new \RuntimeException(sprintf(
                'DATABASE_URL_LEITURA aponta para um usuário com permissão de ESCRITA no schema '
                . '"public" (usuário conectado: "%s"). O servidor MCP se recusa a subir assim: a '
                . 'única garantia de que este servidor não grava é a role restrita do PostgreSQL, '
                . 'não o texto do SQL. Refaça o Passo 2 do runbook '
                . '(docs/runbooks/mcp-investigacao-prod.md) apontando o DSN para a role de '
                . 'leitura criada no Passo 1.',
                (string) $conexao->fetchOne('SELECT current_user'),
            ));
        }
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
