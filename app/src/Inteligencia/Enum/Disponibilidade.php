<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Por que a BlueJus IA está (ou não) ao alcance de UM usuário em UM escritório, na ordem em que a
 * {@see \App\Inteligencia\Service\DisponibilidadeDeInteligencia} decide: plataforma → escritório →
 * permissão → cota. A UI mostra a `mensagem()` do motivo em vez de um botão que finge funcionar.
 */
enum Disponibilidade: string
{
    case Disponivel = 'disponivel';
    case NaoConfiguradaNaPlataforma = 'nao_configurada_na_plataforma';
    case DesligadaNoEscritorio = 'desligada_no_escritorio';
    case SemPermissao = 'sem_permissao';
    case LimiteAtingido = 'limite_atingido';

    public function estaDisponivel(): bool
    {
        return $this === self::Disponivel;
    }

    /** Texto que a tela mostra no lugar do botão (spec §3.6). */
    public function mensagem(): string
    {
        return match ($this) {
            self::Disponivel => 'BlueJus IA disponível',
            self::NaoConfiguradaNaPlataforma => 'IA não configurada nesta instalação',
            self::DesligadaNoEscritorio => 'BlueJus IA desligada neste escritório — peça ao administrador',
            self::SemPermissao => 'Sem permissão para usar a BlueJus IA',
            self::LimiteAtingido => 'Limite diário de análises atingido',
        };
    }
}
