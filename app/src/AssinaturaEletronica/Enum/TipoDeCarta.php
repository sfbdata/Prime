<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/**
 * Os e-mails da Assinatura (contrato da etapa 2, seção 6). Os quatro primeiros vão ao signatário, em nome do escritório;
 * os dois últimos, ao remetente (quem criou o documento). Template em `templates/email/assinatura/<valor>.html.twig`,
 * textos em `assinatura_eletronica.email.<valor>`.
 */
enum TipoDeCarta: string
{
    case Convite = 'convite';
    case Vez = 'vez';
    case Lembrete = 'lembrete';
    case Codigo = 'codigo';
    case Recusa = 'recusa';
    case Conclusao = 'conclusao';

    public function aoSignatario(): bool
    {
        return \in_array($this, [self::Convite, self::Vez, self::Lembrete, self::Codigo], true);
    }

    public function template(): string
    {
        return 'email/assinatura/' . $this->value . '.html.twig';
    }

    public function chaveDoAssunto(): string
    {
        return 'assinatura_eletronica.email.' . $this->value . '.assunto';
    }
}
