<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use Doctrine\DBAL\Connection;

/**
 * Os arquivos que o BANCO diz que existem — as dez fontes (oito colunas da P1, mais os documentos de
 * acordo e de carteira da cobrança, hoje vazios em produção) (spec P2 §3.4).
 *
 * Por DBAL, sem filtro de escritório nem de lixeira: a reconciliação olha o acervo inteiro, de todos
 * os escritórios, inclusive documento na lixeira (o arquivo existe no disco e tem de existir no
 * espelho). O escopo de cada chave sai da coluna de escritório da PRÓPRIA linha (a do chamado, no
 * anexo de chamado) — o mesmo que as fábricas `ChavesDe*` tiram da entidade.
 *
 * ⚠️ Imagens do editor (`pastas/{t}/`) não têm linha no banco: vivem dentro do HTML da peça. Não
 * entram aqui; ficam para a reconciliação contra o inventário do disco.
 */
final class FontesDeReferencia implements FonteDeReferencias
{
    /** @var array<string, array{sql: string, categoria: CategoriaDeArquivo}> */
    private const FONTES = [
        'pasta_documento' => ['sql' => 'SELECT id, tenant_id AS tenant, caminho_arquivo AS valor FROM pasta_documento WHERE caminho_arquivo IS NOT NULL ORDER BY id', 'categoria' => CategoriaDeArquivo::PASTA_DOCUMENTO],
        'cliente_documento' => ['sql' => 'SELECT id, tenant_id AS tenant, caminho_arquivo AS valor FROM cliente_documento WHERE caminho_arquivo IS NOT NULL ORDER BY id', 'categoria' => CategoriaDeArquivo::CLIENTE_DOCUMENTO],
        'justificativa_ponto' => ['sql' => 'SELECT min(id) AS id, tenant_id AS tenant, anexo_path AS valor FROM justificativa_ponto WHERE anexo_path IS NOT NULL GROUP BY tenant_id, anexo_path ORDER BY 1', 'categoria' => CategoriaDeArquivo::JUSTIFICATIVA_ANEXO],
        'user_profiles' => ['sql' => 'SELECT id, NULL AS tenant, foto_url AS valor FROM user_profiles WHERE foto_url IS NOT NULL ORDER BY id', 'categoria' => CategoriaDeArquivo::FOTO_PERFIL],
        'cobranca_documento' => ['sql' => 'SELECT id, tenant_id AS tenant, caminho_arquivo AS valor FROM cobranca_documento WHERE caminho_arquivo IS NOT NULL ORDER BY id', 'categoria' => CategoriaDeArquivo::COBRANCA_DOCUMENTO],
        'cobranca_acordo_documento' => ['sql' => 'SELECT id, tenant_id AS tenant, caminho_arquivo AS valor FROM cobranca_acordo_documento WHERE caminho_arquivo IS NOT NULL ORDER BY id', 'categoria' => CategoriaDeArquivo::COBRANCA_DOCUMENTO],
        'cobranca_carteira_documento' => ['sql' => 'SELECT id, tenant_id AS tenant, caminho_arquivo AS valor FROM cobranca_carteira_documento WHERE caminho_arquivo IS NOT NULL ORDER BY id', 'categoria' => CategoriaDeArquivo::COBRANCA_DOCUMENTO],
        'chamado_anexo' => ['sql' => 'SELECT a.id, c.tenant_id AS tenant, a.nome_arquivo AS valor FROM chamado_anexo a JOIN chamado c ON c.id = a.chamado_id WHERE a.nome_arquivo IS NOT NULL ORDER BY a.id', 'categoria' => CategoriaDeArquivo::CHAMADO_ANEXO],
        'kanban_anexo' => ['sql' => 'SELECT id, tenant_id AS tenant, caminho AS valor FROM kanban_anexo WHERE caminho IS NOT NULL ORDER BY id', 'categoria' => CategoriaDeArquivo::KANBAN_ANEXO],
        'tarefa_mensagem' => ['sql' => 'SELECT id, tenant_id AS tenant, arquivo_anexo AS valor FROM tarefa_mensagem WHERE arquivo_anexo IS NOT NULL ORDER BY id', 'categoria' => CategoriaDeArquivo::TAREFA_ANEXO],
    ];

    public function __construct(
        private readonly Connection $conexao,
        private readonly TradutorDeAnexoDeTarefa $tarefa,
    ) {
    }

    /** @return iterable<ReferenciaDeArquivo> */
    public function referencias(): iterable
    {
        foreach (self::FONTES as $fonte => ['sql' => $sql, 'categoria' => $categoria]) {
            foreach ($this->conexao->iterateAssociative($sql) as $linha) {
                yield $this->referencia($fonte, $categoria, $linha);
            }
        }
    }

    /** @param array<string, mixed> $linha */
    private function referencia(string $fonte, CategoriaDeArquivo $categoria, array $linha): ReferenciaDeArquivo
    {
        $id    = (int) $linha['id'];
        $valor = (string) $linha['valor'];

        try {
            $nome = $categoria === CategoriaDeArquivo::TAREFA_ANEXO ? $this->tarefa->nomeDoValorDaColuna($valor) : $valor;
            if ($nome === null) {
                throw new ChaveDeArquivoInvalida('valor de anexo de tarefa fora do formato');
            }

            $escopo = $categoria === CategoriaDeArquivo::FOTO_PERFIL
                ? EscopoDeArquivo::global()
                : EscopoDeArquivo::deTenant((int) ($linha['tenant'] ?? throw new ChaveDeArquivoInvalida('linha sem escritório')));

            return new ReferenciaDeArquivo($fonte, $id, new ChaveDeArquivo($escopo, $categoria, $nome));
        } catch (ChaveDeArquivoInvalida $e) {
            return new ReferenciaDeArquivo($fonte, $id, null);
        }
    }
}
