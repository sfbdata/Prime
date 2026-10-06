<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * Por que o checklist de documentação de uma pasta foi desativado (DOC-73).
 *
 * São os quatro motivos do desenho, e só eles (02 - EXPEDIENTES 1.2.3, `ckcVals`, `MOT`, dc
 * L4051) — o rótulo é o texto do botão do desenho, sem mudança. O valor gravado é curto e estável:
 * trocar o rótulo um dia não muda o que já está no banco.
 */
enum MotivoDesativacaoChecklist: string
{
    case NaoSeAplica                = 'nao_se_aplica';
    case OutroSistema               = 'outro_sistema';
    case AdministrativaOuConsultiva = 'administrativa_consultiva';
    case Encerrada                  = 'encerrada';

    public function rotulo(): string
    {
        return match ($this) {
            self::NaoSeAplica                => 'Não se aplica a esta pasta',
            self::OutroSistema               => 'Documentos controlados em outro sistema',
            self::AdministrativaOuConsultiva => 'Pasta administrativa ou consultiva',
            self::Encerrada                  => 'Pasta encerrada',
        };
    }
}
