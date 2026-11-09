<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * O CPF do certificado da assinatura mais recente contra o CPF esperado do signatário (contrato, M16). Só sai
 * `Confere` com a assinatura íntegra e o certificado vinculado a ela (M26); mesmo assim o CPF é o que o certificado
 * declara, sem cadeia conferida (M25).
 */
enum ConferenciaDoCpf: string
{
    case NaoPedida = 'nao_pedida';
    case Confere = 'confere';
    case NaoConfere = 'nao_confere';
    case SemCpfNoCertificado = 'sem_cpf_no_certificado';
    case SemAssinatura = 'sem_assinatura';
    case NaoConferido = 'nao_conferido';

    public function motivo(): string
    {
        return match ($this) {
            self::NaoPedida => 'O CPF esperado não foi informado',
            self::Confere => 'O CPF do certificado é o do signatário',
            self::NaoConfere => 'O CPF do certificado não é o do signatário',
            self::SemCpfNoCertificado => 'O certificado não traz CPF',
            self::SemAssinatura => 'Não há assinatura nova para conferir o CPF',
            self::NaoConferido => 'O CPF não foi conferido: a assinatura não está íntegra ou o certificado não está vinculado a ela',
        };
    }
}
