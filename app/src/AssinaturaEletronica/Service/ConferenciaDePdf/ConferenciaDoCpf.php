<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/** O CPF do certificado da assinatura mais recente contra o CPF esperado do signatário (contrato, M16). */
enum ConferenciaDoCpf: string
{
    case NaoPedida = 'nao_pedida';
    case Confere = 'confere';
    case NaoConfere = 'nao_confere';
    case SemCpfNoCertificado = 'sem_cpf_no_certificado';
    case SemAssinatura = 'sem_assinatura';

    public function motivo(): string
    {
        return match ($this) {
            self::NaoPedida => 'O CPF esperado não foi informado',
            self::Confere => 'O CPF do certificado é o do signatário',
            self::NaoConfere => 'O CPF do certificado não é o do signatário',
            self::SemCpfNoCertificado => 'O certificado não traz CPF',
            self::SemAssinatura => 'Não há assinatura nova para conferir o CPF',
        };
    }
}
