<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Enum\TipoDeFerramenta;
use App\Conector\Service\ServidorDeAutorizacao;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;

/**
 * A lista fechada das ferramentas do conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 6 e 6.3, item 1): 7 de
 * leitura (6.4), 9 de gravação (6.6) e a `confirmar_acao` (6.7). Nenhuma outra existe; ferramenta nova entra aqui e na
 * spec antes do código, e o teste de arquitetura derruba a que não estiver nesta lista.
 *
 * Fonte única do que é fixo por ferramenta, para não haver dois textos que precisam ser iguais:
 *  - o tipo, que decide o escopo exigido ({@see politicaDeEscopo()}, 5.1);
 *  - o `title` em português e as quatro dicas com valor explícito ({@see anotacoes()}, 6.2, regra 8): o padrão da
 *    especificação para quem não declara é `destructiveHint: true` e `openWorldHint: true`;
 *  - o `_meta` com os `securitySchemes` do ChatGPT e, na confirmação, o pedido de interação do Claude ({@see meta()}).
 *
 * As anotações são dicas para o app de I.A.; nenhuma regra de segurança depende delas. Quem barra a gravação sem
 * `mcp:escrita` é a {@see ScopePolicy}, antes do SDK (403 `insufficient_scope`).
 */
final class CatalogoFechadoDoConector
{
    /** As ferramentas e o tipo de cada uma, na ordem da spec: 17 de escritório e as do Painel do Master que já entraram. */
    public const FERRAMENTAS = [
        // Leitura (6.4).
        'quem_sou_eu' => TipoDeFerramenta::Leitura,
        'buscar_pastas' => TipoDeFerramenta::Leitura,
        'ler_pasta' => TipoDeFerramenta::Leitura,
        'ler_documentos_e_modelos' => TipoDeFerramenta::Leitura,
        'ler_processos' => TipoDeFerramenta::Leitura,
        'ler_clientes' => TipoDeFerramenta::Leitura,
        'ler_agenda' => TipoDeFerramenta::Leitura,
        // Painel do Master (6.9): só o Master da plataforma a vê e a chama ({@see SO_DO_MASTER}).
        'ler_comercial' => TipoDeFerramenta::Leitura,
        // Gravação (6.6).
        'atualizar_pasta' => TipoDeFerramenta::Gravacao,
        'mudar_andamento' => TipoDeFerramenta::Gravacao,
        'registrar_na_pasta' => TipoDeFerramenta::Gravacao,
        'prazos_e_metas_da_pasta' => TipoDeFerramenta::Gravacao,
        'documentos_e_pecas' => TipoDeFerramenta::Gravacao,
        'financeiro_da_pasta' => TipoDeFerramenta::Gravacao,
        'atualizar_cliente' => TipoDeFerramenta::Gravacao,
        'processos_da_pasta' => TipoDeFerramenta::Gravacao,
        'gravar_na_agenda' => TipoDeFerramenta::Gravacao,
        // Confirmação (6.7).
        'confirmar_acao' => TipoDeFerramenta::Confirmacao,
    ];

    /** O `title` de cada ferramenta, em português (exigência do diretório da Anthropic, 6.2, regra 8). */
    public const TITULOS = [
        'quem_sou_eu' => 'Quem sou eu no BlueJus',
        'buscar_pastas' => 'Buscar pastas',
        'ler_pasta' => 'Ler a pasta',
        'ler_documentos_e_modelos' => 'Ler documentos e modelos',
        'ler_processos' => 'Ler processos',
        'ler_clientes' => 'Ler clientes',
        'ler_agenda' => 'Ler a agenda',
        'ler_comercial' => 'Ler o Comercial do Painel do Master',
        'atualizar_pasta' => 'Atualizar a pasta',
        'mudar_andamento' => 'Mudar o andamento da pasta',
        'registrar_na_pasta' => 'Registrar na pasta',
        'prazos_e_metas_da_pasta' => 'Prazos e metas da pasta',
        'documentos_e_pecas' => 'Documentos e peças',
        'financeiro_da_pasta' => 'Financeiro da pasta',
        'atualizar_cliente' => 'Atualizar cliente',
        'processos_da_pasta' => 'Processos da pasta',
        'gravar_na_agenda' => 'Gravar na agenda',
        'confirmar_acao' => 'Confirmar a ação proposta',
    ];

    /**
     * As que falam com fora do BlueJus (`openWorldHint: true`, 6.2, regra 8): `ler_processos` consulta o CNJ e
     * `gravar_na_agenda` sincroniza com o Google. Todas as outras ficam com `false`.
     */
    public const MUNDO_ABERTO = ['ler_processos', 'gravar_na_agenda'];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function nomes(): array
    {
        return array_keys(self::FERRAMENTAS);
    }

    public static function existe(string $nome): bool
    {
        return \array_key_exists($nome, self::FERRAMENTAS);
    }

    /**
     * As ferramentas do Painel do Master (6.9, M5.10): só o Master da plataforma as vê no `tools/list`, na `quem_sou_eu`
     * e no guia, e só ele as chama ({@see \App\Conector\Service\VisibilidadeDasFerramentasDoMaster}). Cada uma entra em
     * {@see FERRAMENTAS} no PR que a implementa; a lista daqui já vale para o filtro.
     */
    public const SO_DO_MASTER = ['ler_comercial', 'automacoes_de_email', 'modelos_de_email', 'campanhas_de_email'];

    public static function soDoMaster(string $nome): bool
    {
        return \in_array($nome, self::SO_DO_MASTER, true);
    }

    /** @throws \InvalidArgumentException ferramenta fora da lista fechada */
    public static function tipo(string $nome): TipoDeFerramenta
    {
        return self::FERRAMENTAS[$nome] ?? throw new \InvalidArgumentException(sprintf('Ferramenta fora do catálogo do conector: %s.', $nome));
    }

    /**
     * Os escopos que a chamada da ferramenta exige, na ordem do `WWW-Authenticate`: `mcp:leitura` sempre (todo token
     * o tem, 5.1) e `mcp:escrita` na gravação e na confirmação. É o mesmo que a {@see politicaDeEscopo()} pede para o
     * `tools/call`, e o que vai nos `securitySchemes`.
     *
     * @return list<string>
     */
    public static function escoposExigidos(string $nome): array
    {
        $tipo = self::tipo($nome);

        return $tipo->eLeitura()
            ? [ServidorDeAutorizacao::ESCOPO_LEITURA]
            : [ServidorDeAutorizacao::ESCOPO_LEITURA, ServidorDeAutorizacao::ESCOPO_ESCRITA];
    }

    /**
     * A política de escopo do `AuthorizationMiddleware` (5.1, item 2): toda requisição exige `mcp:leitura`, e o
     * `tools/call` de cada ferramenta exige o escopo do tipo dela. Sem `mcp:escrita`, a gravação e a `confirmar_acao`
     * recebem 403 `insufficient_scope` com `scope="mcp:leitura mcp:escrita"`, antes do SDK, nunca um 200 com `isError`.
     */
    public static function politicaDeEscopo(): ScopePolicy
    {
        $porFerramenta = [];
        foreach (self::FERRAMENTAS as $nome => $tipo) {
            $porFerramenta[$nome] = [$tipo->escopo()];
        }

        return new ScopePolicy(default: [ServidorDeAutorizacao::ESCOPO_LEITURA], tools: $porFerramenta);
    }

    /**
     * As anotações MCP da ferramenta, todas com valor explícito (6.2, regra 8):
     *  - leitura: só lê, não destrói, idempotente, mundo fechado (salvo `ler_processos`);
     *  - gravação e confirmação: grava, destrói (todas mudam ou sobrescrevem algo que já existe), não idempotente,
     *    mundo fechado (salvo `gravar_na_agenda`).
     */
    public static function anotacoes(string $nome): ToolAnnotations
    {
        $leitura = self::tipo($nome)->eLeitura();

        return new ToolAnnotations(
            title: self::TITULOS[$nome],
            readOnlyHint: $leitura,
            destructiveHint: !$leitura,
            idempotentHint: $leitura,
            openWorldHint: \in_array($nome, self::MUNDO_ABERTO, true),
        );
    }

    /**
     * O `_meta` da ferramenta no `tools/list`: os `securitySchemes` (`oauth2`, com os escopos que ela exige), que o
     * ChatGPT lê para pedir o escopo que falta (5.1, item 3; no SDK 0.10 só cabem no `_meta`), e, na `confirmar_acao`,
     * `anthropic/requiresUserInteraction`, que faz o Claude pedir aprovação a cada chamada (6.7).
     *
     * @return array<string, mixed>
     */
    public static function meta(string $nome): array
    {
        $meta = ['securitySchemes' => [['type' => 'oauth2', 'scopes' => self::escoposExigidos($nome)]]];
        if (self::tipo($nome) === TipoDeFerramenta::Confirmacao) {
            $meta['anthropic/requiresUserInteraction'] = true;
        }

        return $meta;
    }
}
