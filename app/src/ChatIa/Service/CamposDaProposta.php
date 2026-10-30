<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\Cliente\Service\CamposDoCadastro;

/**
 * Os campos que uma proposta de cliente aceita: as chaves de {@see CamposDoCadastro::ROTULOS}, e só elas, separadas por
 * tipo de pessoa (campo de PJ numa proposta de PF não tem onde ser gravado). Fonte única para o esquema da ferramenta, a
 * leitura da chamada do modelo e o que a pessoa edita no cartão.
 */
final class CamposDaProposta
{
    public const PF = 'pf';
    public const PJ = 'pj';

    private const SO_PF = ['nome', 'cpf', 'rg', 'rg_orgao', 'nascimento', 'estado_civil', 'profissao', 'nacionalidade', 'genero'];
    private const SO_PJ = [
        'razao_social', 'cnpj', 'nome_fantasia', 'inscricao_estadual', 'inscricao_municipal',
        'representante_legal', 'representante_cpf', 'representante_rg', 'representante_cargo',
    ];

    /** Dicas de formato para o modelo (o rótulo sozinho não diz como escrever a data nem o estado civil). */
    private const DICAS = [
        'nascimento' => 'data no formato AAAA-MM-DD',
        'estado_civil' => 'um entre SOLTEIRO, CASADO, DIVORCIADO, SEPARADO, VIUVO, UNIAO_ESTAVEL',
        'uf' => 'sigla do estado com 2 letras',
        'cpf' => 'somente se estiver legível no documento',
        'cnpj' => 'somente se estiver legível no documento',
    ];

    public static function tipoValido(mixed $tipo): bool
    {
        return $tipo === self::PF || $tipo === self::PJ;
    }

    /** @return list<string> as chaves aceitas para o tipo de pessoa, na ordem do cadastro */
    public static function chaves(string $tipoPessoa): array
    {
        $fora = $tipoPessoa === self::PJ ? self::SO_PF : self::SO_PJ;

        return array_values(array_diff(array_keys(CamposDoCadastro::ROTULOS), $fora));
    }

    /** @return array<string, string> chave => rótulo, só das aceitas para o tipo de pessoa */
    public static function rotulos(string $tipoPessoa): array
    {
        return array_intersect_key(CamposDoCadastro::ROTULOS, array_flip(self::chaves($tipoPessoa)));
    }

    /**
     * O objeto `campos` do esquema JSON da ferramenta: todas as chaves de PF e PJ (o modelo escolhe pelo `tipo`), cada
     * uma texto, e nenhuma outra.
     *
     * @return array<string, mixed>
     */
    public static function esquema(): array
    {
        $propriedades = [];
        foreach (CamposDoCadastro::ROTULOS as $chave => $rotulo) {
            $tipo = \in_array($chave, self::SO_PF, true) ? ' (só pessoa física)' : (\in_array($chave, self::SO_PJ, true) ? ' (só pessoa jurídica)' : '');
            $dica = isset(self::DICAS[$chave]) ? ': ' . self::DICAS[$chave] : '';
            $propriedades[$chave] = ['type' => 'string', 'description' => ucfirst($rotulo) . $tipo . $dica];
        }

        return ['type' => 'object', 'properties' => $propriedades, 'additionalProperties' => false];
    }
}
