<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao editar a sede, adicionar uma subseção, salvar a nota ou registrar uma divulgação numa seccional da OAB
 * (spec master-seccionais-oab): campo obrigatório, tamanho acima do limite, site fora do http:// ou https://, status ou
 * cor desconhecidos, data inválida ou no futuro. O controller devolve 422 com a recusa no idioma da requisição (chave e parâmetros em
 * {@see RecusaDaSeccional}).
 */
final class SeccionalRecusada extends RecusaDaSeccional
{
    public const RECUSA_STATUS_DE_DIVULGACAO = 'Escolha o status da divulgação.';
    public const RECUSA_COR_DA_MARCA = 'Escolha a cor da marca.';
    public const RECUSA_DATA_FORMATO = 'Informe a data no formato dia, mês e ano.';
    public const RECUSA_DATA_NO_FUTURO = 'A data da divulgação não pode ser no futuro.';
    public const RECUSA_NOTA_LONGA = 'A nota passa de %d caracteres.';
    public const RECUSA_CIDADE_OBRIGATORIA = 'Informe a cidade da subseção.';
    public const RECUSA_CIDADE_DA_NOTA_OBRIGATORIA = 'Informe a cidade da nota.';
    public const RECUSA_CIDADE_LONGA = 'A cidade passa de %d caracteres.';
    public const RECUSA_ENDERECO_OBRIGATORIO = 'Informe o endereço da subseção.';
    public const RECUSA_NOME_LONGO = 'O nome passa de %d caracteres.';
    public const RECUSA_ENDERECO_LONGO = 'O endereço passa de %d caracteres.';
    public const RECUSA_TELEFONE_LONGO = 'O telefone passa de %d caracteres.';
    public const RECUSA_SITE_LONGO = 'O site passa de %d caracteres.';
    public const RECUSA_SITE_SEM_HTTPS = 'Informe o site completo, começando com http:// ou https://.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_STATUS_DE_DIVULGACAO => 'master.seccionais.recusa.status_de_divulgacao',
        self::RECUSA_COR_DA_MARCA => 'master.seccionais.recusa.cor_da_marca',
        self::RECUSA_DATA_FORMATO => 'master.seccionais.recusa.data_formato',
        self::RECUSA_DATA_NO_FUTURO => 'master.seccionais.recusa.data_no_futuro',
        self::RECUSA_NOTA_LONGA => 'master.seccionais.recusa.nota_longa',
        self::RECUSA_CIDADE_OBRIGATORIA => 'master.seccionais.recusa.cidade_obrigatoria',
        self::RECUSA_CIDADE_DA_NOTA_OBRIGATORIA => 'master.seccionais.recusa.cidade_da_nota_obrigatoria',
        self::RECUSA_CIDADE_LONGA => 'master.seccionais.recusa.cidade_longa',
        self::RECUSA_ENDERECO_OBRIGATORIO => 'master.seccionais.recusa.endereco_obrigatorio',
        self::RECUSA_NOME_LONGO => 'master.seccionais.recusa.nome_longo',
        self::RECUSA_ENDERECO_LONGO => 'master.seccionais.recusa.endereco_longo',
        self::RECUSA_TELEFONE_LONGO => 'master.seccionais.recusa.telefone_longo',
        self::RECUSA_SITE_LONGO => 'master.seccionais.recusa.site_longo',
        self::RECUSA_SITE_SEM_HTTPS => 'master.seccionais.recusa.site_sem_https',
    ];
}
