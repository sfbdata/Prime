<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * A UF do escritório para os convênios (spec docs/specs/convenios.md, §5.1): o estado do escritório (`Tenant.estado`)
 * e, quando ele está vazio ou não é uma UF, a UF da inscrição OAB válida de quem criou o escritório. O escritório nasce
 * sem estado (o cadastro não pergunta), e sem esta volta quase nenhum ganharia o convênio OAB da tarefa diária.
 *
 * A inscrição válida é a mesma da `oabValida` da sincronização: uma linha de `inscricao_oab` que não está divergente
 * (a principal primeiro, depois a mais antiga) ou, para quem ainda não tem linha lá (fluxos antigos gravam só no
 * usuário), o espelho `user.oab_*`. Vale em todo lugar onde a UF decide convênio: a sincronização dos vínculos, o
 * código de convênio de um estado, o rótulo e a contagem das contas no painel do Master e o aviso aos escritórios da UF.
 * O mapa "Onde estamos atuando" tem regra própria ({@see LocalizacaoDaPessoa::localizar}).
 */
final class UfDoEscritorio
{
    /**
     * A UF da OAB válida do criador, como coluna de uma consulta que tem `tenant t` e `LEFT JOIN "user" u ON u.id =
     * t.criado_por_id`. Texto fixo, sem dado de fora.
     */
    public const SQL_UF_DA_OAB_DO_CRIADOR = <<<'SQL'
        COALESCE(
            (SELECT i.uf FROM inscricao_oab i
              WHERE i.user_id = t.criado_por_id AND i.status <> 'divergente'
              ORDER BY (i.tipo = 'principal') DESC, i.id
              LIMIT 1),
            CASE WHEN NOT EXISTS (SELECT 1 FROM inscricao_oab i WHERE i.user_id = t.criado_por_id)
                      AND COALESCE(TRIM(u.oab_numero), '') <> ''
                      AND COALESCE(u.oab_status, 'nao_verificada') <> 'divergente'
                 THEN u.oab_uf
            END)
        SQL;

    /** O estado do escritório, se for uma UF; senão a UF da OAB do criador; senão nulo. */
    public static function de(?string $estado, ?string $ufDaOab): ?string
    {
        return LocalizacaoDaPessoa::uf($estado) ?? LocalizacaoDaPessoa::uf($ufDaOab);
    }

    /**
     * A mesma regra sobre uma linha de {@see \App\Master\Repository\PainelMasterRepository::escritorios()}.
     *
     * @param array{estado?: ?string, uf_oab?: ?string} $escritorio
     */
    public static function daLinha(array $escritorio): ?string
    {
        return self::de($escritorio['estado'] ?? null, $escritorio['uf_oab'] ?? null);
    }
}
