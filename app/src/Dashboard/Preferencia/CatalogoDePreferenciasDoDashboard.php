<?php

declare(strict_types=1);

namespace App\Dashboard\Preferencia;

use App\Dashboard\Exception\PreferenciaInvalidaException;

/**
 * A lista FECHADA do que o menu ⋮ da tabela Desempenho pode gravar (desenho
 * "01 - Dashboard 1.2.2", "Opções da tabela" e "Personalização por usuário"). Nada fora daqui
 * chega ao banco: chave desconhecida, valor fora da lista ou tipo errado é recusado com
 * {@see PreferenciaInvalidaException} — o servidor nunca grava JSON que o cliente inventou.
 *
 * "Sons" liga/desliga o som do calendário das datas (README do desenho, "Calendário das datas").
 * O som do campeão não existe: depende do troféu, que espera decisão do dono (D-DASH).
 *
 * "Colunas extras" ("Adicionar coluna") guarda a LISTA das métricas acrescentadas, na ordem em
 * que o usuário as acrescentou (cada "+" vai para o fim da tabela). Só valem as chaves do
 * {@see ColunasExtrasDoDashboard} — as métricas que o servidor calcula de verdade.
 *
 * O que NÃO está aqui, de propósito:
 *   - zerar relatório e acesso restrito: decisão do dono pendente.
 *
 * Os identificadores de coluna são os mesmos do `ordenar` da tabela (`th[data-ordenar]`) e do
 * `data-coluna` das células — é por eles que o CSS esconde a coluna.
 */
final class CatalogoDePreferenciasDoDashboard
{
    public const DENSIDADE       = 'dashboard.densidade';
    public const ANIMACOES       = 'dashboard.animacoes';
    public const SETAS           = 'dashboard.setas';
    public const COLUNAS_OCULTAS = 'dashboard.colunas_ocultas';
    public const SONS            = 'dashboard.sons';
    public const COLUNAS_EXTRAS  = 'dashboard.colunas_extras';

    public const DENSIDADE_CONFORTAVEL = 'confortavel';
    public const DENSIDADE_COMPACTA    = 'compacta';
    public const ANIMACOES_LIGADAS     = 'ligadas';
    public const ANIMACOES_REDUZIDAS   = 'reduzidas';
    public const SETAS_LIGADAS         = 'ligadas';
    public const SETAS_DESLIGADAS      = 'desligadas';
    public const SONS_LIGADOS          = 'ligados';
    public const SONS_DESLIGADOS       = 'desligados';

    /** As sete numéricas, na ordem da tabela. Pelo menos uma tem de ficar visível. */
    public const COLUNAS_NUMERICAS = [
        'metas',
        'metas_ativas',
        'metas_vencidas',
        'prazos',
        'demandas',
        'demandas_ativas',
        'pastas_criadas',
    ];

    /** "Colaborador" é fixa (cadeado no desenho): não entra na lista. */
    public const COLUNAS_OCULTAVEIS = [
        'cargo',
        'metas',
        'metas_ativas',
        'metas_vencidas',
        'prazos',
        'demandas',
        'demandas_ativas',
        'pastas_criadas',
    ];

    /** Valores aceitos para as chaves de escolha única. */
    private const OPCOES = [
        self::DENSIDADE => [self::DENSIDADE_CONFORTAVEL, self::DENSIDADE_COMPACTA],
        self::ANIMACOES => [self::ANIMACOES_LIGADAS, self::ANIMACOES_REDUZIDAS],
        self::SETAS     => [self::SETAS_LIGADAS, self::SETAS_DESLIGADAS],
        self::SONS      => [self::SONS_LIGADOS, self::SONS_DESLIGADOS],
    ];

    /**
     * Padrão de cada chave — o que a tela já mostrava antes do menu existir: a tabela já era a
     * compacta (7px), com animações e setas, sem coluna oculta. Sons nascem ligados (desenho:
     * "padrão ligado").
     */
    private const PADROES = [
        self::DENSIDADE       => self::DENSIDADE_COMPACTA,
        self::ANIMACOES       => self::ANIMACOES_LIGADAS,
        self::SETAS           => self::SETAS_LIGADAS,
        self::COLUNAS_OCULTAS => [],
        self::SONS            => self::SONS_LIGADOS,
        self::COLUNAS_EXTRAS  => [],
    ];

    /** @return list<string> */
    public static function chaves(): array
    {
        return array_keys(self::PADROES);
    }

    public static function existe(string $chave): bool
    {
        return array_key_exists($chave, self::PADROES);
    }

    public static function padrao(string $chave): mixed
    {
        if (!self::existe($chave)) {
            throw new PreferenciaInvalidaException('Preferência desconhecida.');
        }

        return self::PADROES[$chave];
    }

    /**
     * Confere a chave e o valor e devolve o valor NORMALIZADO (é ele que vai para o banco).
     * Colunas ocultas saem sem repetição e na ordem da tabela, para o mesmo conjunto gravar
     * sempre o mesmo JSON.
     *
     * @throws PreferenciaInvalidaException
     */
    public static function validar(string $chave, mixed $valor): mixed
    {
        if (!self::existe($chave)) {
            throw new PreferenciaInvalidaException('Preferência desconhecida.');
        }

        if ($chave === self::COLUNAS_OCULTAS) {
            return self::validarColunasOcultas($valor);
        }

        if ($chave === self::COLUNAS_EXTRAS) {
            return self::validarColunasExtras($valor);
        }

        if (!is_string($valor) || !in_array($valor, self::OPCOES[$chave], true)) {
            throw new PreferenciaInvalidaException('Valor não permitido para esta preferência.');
        }

        return $valor;
    }

    /**
     * @return list<string>
     *
     * @throws PreferenciaInvalidaException
     */
    private static function validarColunasOcultas(mixed $valor): array
    {
        if (!is_array($valor) || !array_is_list($valor)) {
            throw new PreferenciaInvalidaException('As colunas ocultas vêm numa lista.');
        }

        foreach ($valor as $coluna) {
            if (!is_string($coluna) || !in_array($coluna, self::COLUNAS_OCULTAVEIS, true)) {
                throw new PreferenciaInvalidaException('Coluna desconhecida.');
            }
        }

        // Ordem da tabela e sem repetição: o mesmo conjunto vira sempre o mesmo JSON.
        $ocultas = array_values(array_filter(
            self::COLUNAS_OCULTAVEIS,
            static fn (string $coluna): bool => in_array($coluna, $valor, true),
        ));

        if (array_diff(self::COLUNAS_NUMERICAS, $ocultas) === []) {
            throw new PreferenciaInvalidaException('Pelo menos uma coluna numérica tem de ficar visível.');
        }

        return $ocultas;
    }

    /**
     * Colunas extras: lista de chaves do catálogo de extras, sem repetição, NA ORDEM RECEBIDA —
     * a ordem é a da tabela (cada "+" acrescenta no fim). Lista vazia = nenhuma extra.
     *
     * @return list<string>
     *
     * @throws PreferenciaInvalidaException
     */
    private static function validarColunasExtras(mixed $valor): array
    {
        if (!is_array($valor) || !array_is_list($valor)) {
            throw new PreferenciaInvalidaException('As colunas extras vêm numa lista.');
        }

        foreach ($valor as $coluna) {
            if (!is_string($coluna) || !ColunasExtrasDoDashboard::existe($coluna)) {
                throw new PreferenciaInvalidaException('Coluna extra desconhecida.');
            }
        }

        return ColunasExtrasDoDashboard::filtrar($valor);
    }
}
