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
        'pagina-publica-vagas' => 'na página de vagas do BlueJus',
        // F3h: o "Cadastrar e-mail" do Master na seção Indicações (CadastrarContatoDoMasterUseCase::ORIGEM).
        'cadastro-manual-master' => 'pela equipe do BlueJus, em um cadastro manual',
    ];

    private const LISTAS = [
        'vagas_alerta' => 'os alertas de vagas do BlueJus',
        // A Base de contatos das campanhas (ComposicaoDaCampanha::LISTA): convites, ofertas e novidades.
        'comercial_campanhas' => 'os convites, as ofertas e as novidades do BlueJus',
    ];

    /**
     * A segunda frase do rodapé ("Este endereço foi informado <origem>. <frase>"). No cadastro feito pela equipe o
     * "Se não foi você" não casa (quem informou não foi a pessoa): fica esta até o dono decidir (coordenação, 21/10).
     */
    private const FRASES_DO_MOTIVO = [
        'cadastro-manual-master' => 'Se não quiser receber, é só ignorar este e-mail.',
    ];
    private const FRASE_DO_MOTIVO = 'Se não foi você, ignore este e-mail.';

    private const ORIGEM_DESCONHECIDA = 'em um formulário do BlueJus';
    private const LISTA_DESCONHECIDA = 'as comunicações do BlueJus';

    /** Onde o endereço foi informado, já com a preposição: "Este endereço foi informado <rótulo>." */
    public static function daOrigem(string $origem): string
    {
        return self::ORIGENS[$origem] ?? self::ORIGEM_DESCONHECIDA;
    }

    /** O rodapé de motivo do e-mail de confirmação, com a origem e a segunda frase da origem. */
    public static function motivoDoEnvio(string $origem): string
    {
        return sprintf('Este endereço foi informado %s. %s', self::daOrigem($origem), self::FRASES_DO_MOTIVO[$origem] ?? self::FRASE_DO_MOTIVO);
    }

    /** O que a pessoa vai receber: "Confirme que quer receber <nome>." */
    public static function daLista(string $lista): string
    {
        return self::LISTAS[$lista] ?? self::LISTA_DESCONHECIDA;
    }
}
