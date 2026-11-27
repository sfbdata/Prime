<?php

declare(strict_types=1);

namespace App\Comercial\Enum;

/**
 * De qual Central de envios do /master a campanha saiu (pedido do Farlei M1, item D5, 25/11/2026; campo aprovado pelo
 * Samuel em 27/11/2026): a da seção "Comercial e e-mails" ou a da seção "Indicações do BlueJus" (o modo venda). Gravada
 * em `comercial_campanha.central`; o Relatório de cada seção lista só as campanhas e os testes dela.
 *
 * Nula só nas campanhas de antes deste campo que a migration não conseguiu separar (as que cabiam no modo venda): essas
 * aparecem nas duas seções, como antes.
 */
enum CentralDaCampanha: string
{
    case Comercial = 'comercial';
    case Indicacoes = 'indicacoes';

    /** A seção do Relatório (`com` ou `ind`, o `data-mc-relatorio` da tela); outro valor vale como a Comercial. */
    public static function daSecao(mixed $secao): self
    {
        return $secao === 'ind' ? self::Indicacoes : self::Comercial;
    }
}
