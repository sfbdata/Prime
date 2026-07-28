<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Como se fala com este número: WhatsApp ou telefone comum (2026-07-28, pedido do dono).
 *
 * É CAPACIDADE de contato, não formato do número — um fixo comercial pode ter WhatsApp e um celular
 * pode não ter. Por isso o tipo NÃO decide a máscara: quem decide como o número é exibido é a
 * quantidade de dígitos (10 → `(99) 9999-9999`, 11 → `(99) 99999-9999`), no filtro `telefone_br`.
 * O tipo decide o ÍCONE e para onde o número leva ao ser clicado (`wa.me` × `tel:`).
 *
 * A coluna é NULLABLE de propósito: os telefones cadastrados antes desta frente não têm tipo, e
 * ninguém disse qual é (decisão do dono: não inferir por contagem de dígitos, porque o palpite ficaria
 * gravado como se fosse informação). Nulo lê como o `Fixo` na tela — mesmo ícone, mesmo `tel:` — mas
 * não afirma nada no banco. Quem quiser marcar, edita o número.
 */
enum TipoTelefone: string
{
    case WhatsApp = 'whatsapp';
    case Fixo = 'fixo';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Fixo => 'Telefone',
        };
    }

    /** Classe do Bootstrap Icons, sem a cor — quem pinta é o template (verde no item atual). */
    public function icone(): string
    {
        return match ($this) {
            self::WhatsApp => 'bi-whatsapp',
            self::Fixo => 'bi-telephone',
        };
    }
}
