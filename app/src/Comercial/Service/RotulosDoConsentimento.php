<?php

declare(strict_types=1);

namespace App\Comercial\Service;

/**
 * Os nomes legíveis que o e-mail e as páginas da confirmação de endereço mostram (spec `comercial-e-emails.md` §3.3,
 * M-g): nunca o identificador cru da origem ou da lista. Origem ou lista nova entra aqui; a desconhecida cai no
 * genérico, que continua verdadeiro.
 */
final class RotulosDoConsentimento
{
    private const ORIGENS = [
        'pagina-publica-vagas' => 'a página de vagas do BlueJus',
    ];

    private const LISTAS = [
        'vagas_alerta' => 'os alertas de vagas do BlueJus',
    ];

    private const ORIGEM_DESCONHECIDA = 'um formulário do BlueJus';
    private const LISTA_DESCONHECIDA = 'as comunicações do BlueJus';

    /** Onde o endereço foi informado: "Este endereço foi informado em <rótulo>." */
    public static function daOrigem(string $origem): string
    {
        return self::ORIGENS[$origem] ?? self::ORIGEM_DESCONHECIDA;
    }

    /** O que a pessoa vai receber: "Confirme que quer receber <nome>." */
    public static function daLista(string $lista): string
    {
        return self::LISTAS[$lista] ?? self::LISTA_DESCONHECIDA;
    }
}
