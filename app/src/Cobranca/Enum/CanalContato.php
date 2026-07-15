<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Canal pelo qual o gestor tentou/fez o contato de cobrança (ajuste 2026-07: "tentativa" vira registro
 * de contato). Backed string — o value é gravado no payload `dados` do EventoHistorico; a apresentação
 * usa `label()`. Sem persistência em coluna tipada → não há migração.
 */
enum CanalContato: string
{
    case Telefone = 'telefone';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::Telefone => 'Telefone',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'E-mail',
            self::Sms => 'SMS',
        };
    }
}
