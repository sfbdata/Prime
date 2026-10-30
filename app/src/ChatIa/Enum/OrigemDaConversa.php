<?php

declare(strict_types=1);

namespace App\ChatIa\Enum;

/** De onde a conversa do Chat I.A nasceu. Os valores são contrato com o banco (`chat_ia_conversa.origem`). */
enum OrigemDaConversa: string
{
    case Pagina = 'pagina';
    case Central = 'central';
    /** O histórico antigo da conversa "BlueJus IA" da Central, trazido uma vez para o Chat I.A. */
    case Importada = 'importada';
}
