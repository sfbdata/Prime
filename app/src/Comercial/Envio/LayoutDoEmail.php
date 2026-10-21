<?php

declare(strict_types=1);

namespace App\Comercial\Envio;

/**
 * Como o {@see \App\Comercial\Service\MontadorDeEmail} encaixa o corpo no layout (spec `comercial-e-emails.md` §8.3):
 *  - `padrao`: o miolo num `<td>` com a fonte do corpo (o layout da 1a; automações, 0d e a7);
 *  - `linhas`: o corpo já são as linhas `<tr>` da tabela de 600 px (os blocos e o modo simples das campanhas,
 *    desenho 05 L.496-500);
 *  - `cru`: o HTML importado, sanitizado de novo na montagem, sem o cabeçalho azul e com só o rodapé legal (B3).
 */
enum LayoutDoEmail: string
{
    case Padrao = 'padrao';
    case Linhas = 'linhas';
    case Cru = 'cru';
}
