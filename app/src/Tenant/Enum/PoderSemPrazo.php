<?php

declare(strict_types=1);

namespace App\Tenant\Enum;

/**
 * Quem edita e exclui sem prazo e sem ser o autor (spec `edicao-sem-prazo-master-primario.md` §1): o Master, sempre;
 * o usuário primário, no próprio escritório, quando a opção do escritório está ligada. A resposta vem do
 * {@see \App\Tenant\Service\PoderDeEdicaoSemPrazo}; o valor é o que a tela usa no selo.
 */
enum PoderSemPrazo: string
{
    case Master = 'master';
    case Primario = 'primario';

    /** O texto do selo dourado (§5), em translations. */
    public function chaveDoSelo(): string
    {
        return 'edicao_sem_prazo.selo.' . $this->value;
    }

    /** O papel gravado na auditoria (§6). */
    public function papelNaAuditoria(): string
    {
        return $this === self::Master ? 'Master' : 'Usuário primário';
    }
}
