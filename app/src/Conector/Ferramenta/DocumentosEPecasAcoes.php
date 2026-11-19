<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

/**
 * O catálogo das ações da `documentos_e_pecas` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 12). O campo `acao` da entrada
 * é a chave da {@see TabelaDeAbaEChave} (é a linha da aba e da chave); o campo `operacao` diz o que fazer dentro dela.
 * A ação inteira é `chave.operacao` ({@see junta()}).
 *
 * Cada operação diz se vai por proposta (exclusão e gravar por cima, 6.6) e quais campos aceita:
 *  - `obrigatorios` e `opcionais`: o resto é recusado (6.2, regra 10);
 *  - `um_de`: pelo menos um destes precisa vir; com `exclusivo`, exatamente um;
 *  - `proposta_com`: a operação só vira proposta quando este campo vem (`salvar_modelo`: com `modelo_id` grava por cima
 *    de um modelo que já existe; sem ele, cria um novo, direto).
 */
final class DocumentosEPecasAcoes
{
    public const GRUPO_DOCUMENTOS = 'documentos';
    public const GRUPO_PECAS = 'pecas';
    public const GRUPO_CHECKLIST = 'checklist';
    public const GRUPO_LIXEIRA = 'lixeira';

    /** O teto de itens de uma chamada em lote (6.2, regra 12). */
    public const TETO_DO_LOTE = 50;

    private const DOCUMENTO = ['documento_id'];
    private const ITENS_DA_SELECAO = ['documentos', 'subpastas'];

    /**
     * @var array<string, array<string, array{proposta: bool, obrigatorios: list<string>, opcionais: list<string>, um_de: list<string>, exclusivo?: bool, proposta_com?: string}>>
     */
    private const OPERACOES = [
        'editar_documento' => [
            'editar' => ['proposta' => true, 'obrigatorios' => self::DOCUMENTO, 'opcionais' => ['categoria', 'descricao', 'numero', 'nome_base'], 'um_de' => ['categoria', 'descricao', 'numero', 'nome_base']],
            'excluir' => ['proposta' => true, 'obrigatorios' => ['documentos'], 'opcionais' => [], 'um_de' => []],
        ],
        'mover' => [
            'mover' => ['proposta' => false, 'obrigatorios' => [], 'opcionais' => ['documentos', 'subpastas', 'destino_id'], 'um_de' => self::ITENS_DA_SELECAO],
        ],
        'copiar' => [
            'copiar' => ['proposta' => false, 'obrigatorios' => ['documentos'], 'opcionais' => ['destino_id'], 'um_de' => []],
        ],
        'favoritar' => [
            'favoritar' => ['proposta' => false, 'obrigatorios' => ['item', 'alvo_id', 'marcado'], 'opcionais' => [], 'um_de' => []],
        ],
        'subpasta' => [
            'criar' => ['proposta' => false, 'obrigatorios' => ['nome'], 'opcionais' => ['pai_id'], 'um_de' => []],
            'renomear' => ['proposta' => true, 'obrigatorios' => ['subpasta_id', 'nome'], 'opcionais' => [], 'um_de' => []],
            'excluir' => ['proposta' => true, 'obrigatorios' => ['subpasta_id'], 'opcionais' => [], 'um_de' => []],
        ],
        'reordenar' => [
            'de_documentos' => ['proposta' => false, 'obrigatorios' => ['ids'], 'opcionais' => [], 'um_de' => []],
            'de_subpastas' => ['proposta' => false, 'obrigatorios' => ['ids'], 'opcionais' => [], 'um_de' => []],
        ],
        'restaurar' => [
            'restaurar' => ['proposta' => false, 'obrigatorios' => [], 'opcionais' => self::ITENS_DA_SELECAO, 'um_de' => self::ITENS_DA_SELECAO],
        ],
        'excluir_de_vez' => [
            'excluir' => ['proposta' => true, 'obrigatorios' => [], 'opcionais' => self::ITENS_DA_SELECAO, 'um_de' => self::ITENS_DA_SELECAO],
        ],
        'escrever_peca' => [
            'escrever' => ['proposta' => false, 'obrigatorios' => ['titulo', 'conteudo'], 'opcionais' => ['categoria', 'subpasta_id'], 'um_de' => []],
        ],
        'editar_peca' => [
            'editar' => ['proposta' => true, 'obrigatorios' => ['documento_id', 'conteudo'], 'opcionais' => ['titulo'], 'um_de' => []],
        ],
        'destino_da_peca' => [
            'definir' => ['proposta' => false, 'obrigatorios' => self::DOCUMENTO, 'opcionais' => ['vinculo_id'], 'um_de' => []],
        ],
        'usar_modelo' => [
            'usar' => ['proposta' => false, 'obrigatorios' => ['modelo_id'], 'opcionais' => [], 'um_de' => []],
        ],
        'salvar_modelo' => [
            'salvar' => ['proposta' => false, 'obrigatorios' => ['titulo', 'materia', 'tipo', 'corpo'], 'opcionais' => ['modelo_id'], 'um_de' => [], 'proposta_com' => 'modelo_id'],
            'excluir' => ['proposta' => true, 'obrigatorios' => ['modelo_id'], 'opcionais' => [], 'um_de' => []],
        ],
        'favoritar_modelo' => [
            'favoritar' => ['proposta' => false, 'obrigatorios' => [], 'opcionais' => ['modelo_id', 'materia'], 'um_de' => ['modelo_id', 'materia'], 'exclusivo' => true],
        ],
        'checklist_item' => [
            'adicionar' => ['proposta' => false, 'obrigatorios' => ['titulo'], 'opcionais' => [], 'um_de' => []],
            'marcar' => ['proposta' => false, 'obrigatorios' => ['item_id', 'concluido'], 'opcionais' => [], 'um_de' => []],
            'reordenar' => ['proposta' => false, 'obrigatorios' => ['ids'], 'opcionais' => [], 'um_de' => []],
            'editar' => ['proposta' => true, 'obrigatorios' => ['item_id', 'titulo'], 'opcionais' => [], 'um_de' => []],
            'excluir' => ['proposta' => true, 'obrigatorios' => ['item_id'], 'opcionais' => [], 'um_de' => []],
        ],
        'checklist_estado' => [
            'alterar' => ['proposta' => false, 'obrigatorios' => ['ativo'], 'opcionais' => ['motivo'], 'um_de' => []],
        ],
        'checklist_modelo' => [
            'salvar' => ['proposta' => false, 'obrigatorios' => ['nome'], 'opcionais' => [], 'um_de' => []],
            'aplicar' => ['proposta' => false, 'obrigatorios' => ['modelo_checklist_id'], 'opcionais' => [], 'um_de' => []],
            'renomear' => ['proposta' => true, 'obrigatorios' => ['modelo_checklist_id', 'nome'], 'opcionais' => [], 'um_de' => []],
            'excluir' => ['proposta' => true, 'obrigatorios' => ['modelo_checklist_id'], 'opcionais' => [], 'um_de' => []],
        ],
    ];

    private const GRUPOS = [
        'editar_documento' => self::GRUPO_DOCUMENTOS,
        'mover' => self::GRUPO_DOCUMENTOS,
        'copiar' => self::GRUPO_DOCUMENTOS,
        'favoritar' => self::GRUPO_DOCUMENTOS,
        'subpasta' => self::GRUPO_DOCUMENTOS,
        'reordenar' => self::GRUPO_DOCUMENTOS,
        'restaurar' => self::GRUPO_DOCUMENTOS,
        'excluir_de_vez' => self::GRUPO_LIXEIRA,
        'escrever_peca' => self::GRUPO_PECAS,
        'editar_peca' => self::GRUPO_PECAS,
        'destino_da_peca' => self::GRUPO_PECAS,
        'usar_modelo' => self::GRUPO_PECAS,
        'salvar_modelo' => self::GRUPO_PECAS,
        'favoritar_modelo' => self::GRUPO_PECAS,
        'checklist_item' => self::GRUPO_CHECKLIST,
        'checklist_estado' => self::GRUPO_CHECKLIST,
        'checklist_modelo' => self::GRUPO_CHECKLIST,
    ];

    private function __construct()
    {
    }

    public static function junta(string $chave, string $operacao): string
    {
        return $chave . '.' . $operacao;
    }

    public static function temChave(string $chave): bool
    {
        return isset(self::OPERACOES[$chave]);
    }

    public static function existe(string $chave, string $operacao): bool
    {
        return isset(self::OPERACOES[$chave][$operacao]);
    }

    /** O grupo que cuida da chave; vazio para chave fora do catálogo. */
    public static function grupoDa(string $chave): string
    {
        return self::GRUPOS[$chave] ?? '';
    }

    /** A operação da chave que tem uma só (então `operacao` pode faltar na entrada); null se houver mais de uma. */
    public static function operacaoUnica(string $chave): ?string
    {
        $operacoes = array_keys(self::OPERACOES[$chave] ?? []);

        return \count($operacoes) === 1 ? $operacoes[0] : null;
    }

    /**
     * Todas as operações do esquema, sem repetir, na ordem do catálogo.
     *
     * @return list<string>
     */
    public static function todasAsOperacoes(): array
    {
        $todas = [];
        foreach (self::OPERACOES as $operacoes) {
            foreach (array_keys($operacoes) as $operacao) {
                $todas[$operacao] = true;
            }
        }

        return array_map(strval(...), array_keys($todas));
    }

    /**
     * O catálogo inteiro, por chave e operação (a descrição da ferramenta nasce dele).
     *
     * @return array<string, array<string, array{proposta: bool, obrigatorios: list<string>, opcionais: list<string>, um_de: list<string>, exclusivo?: bool, proposta_com?: string}>>
     */
    public static function todas(): array
    {
        return self::OPERACOES;
    }

    /**
     * @return array{proposta: bool, obrigatorios: list<string>, opcionais: list<string>, um_de: list<string>, exclusivo?: bool, proposta_com?: string}
     */
    public static function regras(string $chave, string $operacao): array
    {
        return self::OPERACOES[$chave][$operacao];
    }

    /** @param array<string, mixed> $campos */
    public static function porProposta(string $chave, string $operacao, array $campos): bool
    {
        $regras = self::regras($chave, $operacao);

        return $regras['proposta'] || (isset($regras['proposta_com']) && isset($campos[$regras['proposta_com']]));
    }
}
