<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

/** Como cada referência do banco está, comparando disco local e espelho. */
enum ClasseDeReconciliacao: string
{
    case IGUAL                    = 'igual';
    case AUSENTE_NO_ESPELHO       = 'ausente_no_espelho';
    case AUSENTE_PENDENTE_OUTBOX  = 'ausente_pendente_na_outbox';
    case TAMANHO_DIVERGENTE       = 'tamanho_divergente';
    case SHA_DIVERGENTE           = 'sha_divergente';
    case SEM_SHA_NO_ESPELHO       = 'sem_sha_no_espelho';
    case LOCAL_AUSENTE            = 'local_ausente';
    case SUSPEITO_ERRO_DO_DRIVE   = 'suspeito_erro_do_drive';
    case CHAVE_INVALIDA           = 'chave_invalida';
    case ERRO_DE_CONSULTA         = 'erro_de_consulta';
}
