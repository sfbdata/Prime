<?php

declare(strict_types=1);

namespace App\Pasta\Enum;

/** Os 14 tipos de peça do banco de modelos (desenho 02 de 27/10, `bj-modelos.js` `TIPOS`), na ordem do desenho. */
enum TipoDePeca: string
{
    case PeticaoInicial = 'peticao_inicial';
    case Contestacao = 'contestacao';
    case Replica = 'replica';
    case Manifestacao = 'manifestacao';
    case Recurso = 'recurso';
    case Contrarrazoes = 'contrarrazoes';
    case Embargos = 'embargos';
    case CumprimentoDeSentenca = 'cumprimento_de_sentenca';
    case Acordo = 'acordo';
    case NotificacaoExtrajudicial = 'notificacao_extrajudicial';
    case Procuracao = 'procuracao';
    case ContratoDeHonorarios = 'contrato_de_honorarios';
    case RequerimentoAdministrativo = 'requerimento_administrativo';
    case Parecer = 'parecer';

    public function rotulo(): string
    {
        return match ($this) {
            self::PeticaoInicial => 'Petição inicial',
            self::Contestacao => 'Contestação',
            self::Replica => 'Réplica',
            self::Manifestacao => 'Manifestação',
            self::Recurso => 'Recurso',
            self::Contrarrazoes => 'Contrarrazões',
            self::Embargos => 'Embargos',
            self::CumprimentoDeSentenca => 'Cumprimento de sentença',
            self::Acordo => 'Acordo',
            self::NotificacaoExtrajudicial => 'Notificação extrajudicial',
            self::Procuracao => 'Procuração',
            self::ContratoDeHonorarios => 'Contrato de honorários',
            self::RequerimentoAdministrativo => 'Requerimento administrativo',
            self::Parecer => 'Parecer',
        };
    }
}
