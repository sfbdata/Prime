<?php

declare(strict_types=1);

namespace App\Conector\Enum;

/**
 * Versão do texto do aceite do conector de I.A. que o administrador do escritório dá ao ligá-lo
 * (`docs/specs/mcp-remoto-oauth.md`, decisão 14.a, item 3), gravada em `conector_ia_escritorio.aceite_versao`
 * junto com a data e quem aceitou. Não é o aceite da BlueJus I.A. ({@see \App\Inteligencia\Enum\VersaoDoAceiteDaIa}):
 * o texto do conector diz que o dado vai para a conta de I.A. de cada pessoa, sob o contrato dela.
 *
 * - {@see Inicial} (1): o primeiro texto. Já cobre os documentos da pasta, porque a decisão 14.f os inclui.
 *
 * Mudar o texto do aceite para cobrir algo novo = caso novo aqui (e a regra que o usa), nunca editar o texto sem mudar
 * a versão.
 */
enum VersaoDoAceiteDoConector: int
{
    case Inicial = 1;

    /** A versão do texto que a tela mostra hoje: é a que um aceite novo grava. */
    public static function atual(): self
    {
        return self::Inicial;
    }
}
