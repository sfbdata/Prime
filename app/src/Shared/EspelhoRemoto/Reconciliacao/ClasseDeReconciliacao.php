<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

/** Como cada referência do banco está, comparando disco local e espelho. */
enum ClasseDeReconciliacao: string
{
    case IGUAL                    = 'igual';
    /** Presente com o mesmo tamanho, mas SEM conferir o SHA (`--sem-sha`): nunca conta como igual (DP-7). */
    case PRESENTE_SEM_SHA_CONFERIDO = 'presente_sem_sha_conferido';
    case AUSENTE_NO_ESPELHO       = 'ausente_no_espelho';
    case AUSENTE_PENDENTE_OUTBOX  = 'ausente_pendente_na_outbox';
    case TAMANHO_DIVERGENTE       = 'tamanho_divergente';
    case SHA_DIVERGENTE           = 'sha_divergente';
    case SEM_SHA_NO_ESPELHO       = 'sem_sha_no_espelho';
    case LOCAL_AUSENTE            = 'local_ausente';
    case SUSPEITO_ERRO_DO_DRIVE   = 'suspeito_erro_do_drive';
    case CHAVE_INVALIDA           = 'chave_invalida';
    case ERRO_DE_CONSULTA         = 'erro_de_consulta';
    /**
     * Ausente no disco, com linha aberta no registro de liberação e o R2 confirmando presença e tamanho
     * (spec `leitura-pelo-r2-e-liberacao-de-disco.md` D5, §4): saiu do disco de propósito. Não alerta.
     */
    case LIBERADO                 = 'liberado';
    /** Presente no disco E com linha aberta de liberação: alguém regravou o arquivo. Alerta leve: fechar a linha. */
    case RESTAURADO_SEM_REGISTRO  = 'restaurado_sem_registro';
}
