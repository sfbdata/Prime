<?php

declare(strict_types=1);

namespace App\Pagamento\Enum;

/** De onde vem a cobrança. O consumidor liga ao pagamento por origem + origemId, sem chave estrangeira entre domínios. */
enum OrigemDoPagamento: string
{
    case TrabalhoExterno = 'trabalho_externo';
    case ServiceDesk = 'service_desk';
    case ContribuaApoio = 'contribua_apoio';
    case ContribuaPlano = 'contribua_plano';
}
