<?php

declare(strict_types=1);

namespace App\Central\Enum;

/**
 * O que se encaminha da pasta para a Central ("Encaminhar via Chat I.A", `docs/specs/encaminhar-via-chat-ia.md`). O
 * servidor relê o item pelo tipo e pelo id, sempre preso à pasta e ao escritório (`ItensEncaminhaveisDaPasta`).
 */
enum TipoDeItemEncaminhado: string
{
    case Pasta = 'pasta';
    case RegistroDados = 'registro_dados';
    case RegistroDetalhes = 'registro_detalhes';
    case RegistroFinanceiro = 'registro_financeiro';
    case Documento = 'documento';
    case Secao = 'secao';
    case Processo = 'processo';
    case Meta = 'meta';
    case Prazo = 'prazo';
    case Push = 'push';
    case NotaTecnica = 'nota_tecnica';
}
