<?php

declare(strict_types=1);

namespace App\Mcp\Command;

use App\Mcp\Tool\ConsultarSqlTool;
use App\Mcp\Tool\DescreverEsquemaTool;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Servidor MCP de LEITURA para investigação em produção.
 *
 * Falado por STDIO: o stdout carrega exclusivamente JSON-RPC. Nada pode ser escrito na saída
 * padrão a partir daqui — nem `$output->writeln()`, nem log, nem `dump()`. Qualquer byte fora
 * do protocolo corrompe a sessão e o cliente mostra apenas "servidor falhou", sem pista.
 *
 * Lançado remotamente por:
 *   ssh bluejus 'docker exec -i jusprime_php_prod php bin/console mcp:server'
 */
#[AsCommand(
    name: 'mcp:server',
    description: 'Servidor MCP de leitura (investigação em produção)',
)]
final class McpServerCommand extends Command
{
    public function __construct(
        private readonly ConsultarSqlTool $consultarSql,
        private readonly DescreverEsquemaTool $descreverEsquema,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $servidor = Server::builder()
            ->setServerInfo('JusPrime (leitura)', '1.0.0')
            ->setInstructions(
                'Acesso SOMENTE LEITURA ao banco do JusPrime. Nenhuma ferramenta grava dados. '
                . 'Chame descrever_esquema antes de escrever SQL contra uma tabela desconhecida — '
                . 'nome de coluna chutado devolve número errado, não erro.',
            )
            ->addTool(
                handler: [$this->consultarSql, 'consultar'],
                name: 'consultar_sql',
            )
            ->addTool(
                handler: [$this->descreverEsquema, 'descrever'],
                name: 'descrever_esquema',
            )
            ->build();

        return $servidor->run(new StdioTransport());
    }
}
