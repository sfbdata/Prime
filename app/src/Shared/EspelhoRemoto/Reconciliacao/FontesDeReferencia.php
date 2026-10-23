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
 * acordo e de carteira da cobrança, hoje vazios em produção) (spec P2 §3.4), e as três que vieram
 * depois: documento de demanda e arquivos do currículo do Trabalho Externo e imagem da campanha do
 * Comercial (decisão do dono de 23/10).
 *
 * Currículo: os arquivos moram no JSON `arquivos` (tipo → chave e nome original); cada arquivo é uma
 * referência, com o id da linha do currículo.
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
        'trabalho_externo_documento' => ['sql' => 'SELECT d.id, e.tenant_id AS tenant, d.nome_armazenado AS valor FROM trabalho_externo_documento d JOIN trabalho_externo_demanda e ON e.id = d.demanda_id ORDER BY d.id', 'categoria' => CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO],
        'trabalho_externo_curriculo' => ['sql' => "SELECT c.id, NULL AS tenant, a.value->>'chave' AS valor FROM trabalho_externo_curriculo c CROSS JOIN LATERAL json_each(c.arquivos) a WHERE a.value->>'chave' IS NOT NULL ORDER BY c.id, a.key", 'categoria' => CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO],
        'comercial_imagem' => ['sql' => 'SELECT id, NULL AS tenant, nome_arquivo AS valor FROM comercial_imagem ORDER BY id', 'categoria' => CategoriaDeArquivo::COMERCIAL_IMAGEM],
    ];

    /** As categorias sem escritório: o escopo é global, e a coluna de escritório não existe (vem NULL). */
    private const GLOBAIS = [
        CategoriaDeArquivo::FOTO_PERFIL,
        CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO,
        CategoriaDeArquivo::COMERCIAL_IMAGEM,
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

            $escopo = \in_array($categoria, self::GLOBAIS, true)
                ? EscopoDeArquivo::global()
                : EscopoDeArquivo::deTenant((int) ($linha['tenant'] ?? throw new ChaveDeArquivoInvalida('linha sem escritório')));

            return new ReferenciaDeArquivo($fonte, $id, new ChaveDeArquivo($escopo, $categoria, $nome));
        } catch (ChaveDeArquivoInvalida $e) {
            return new ReferenciaDeArquivo($fonte, $id, null);
        }
    }
}
