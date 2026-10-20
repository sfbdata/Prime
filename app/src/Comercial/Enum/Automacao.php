<?php

declare(strict_types=1);

namespace App\Comercial\Enum;

/**
 * As 8 automações do desenho 05 (dc L.562-564; spec `comercial-e-emails.md` §2 e §3.4), com o título, a descrição, o
 * gatilho que aparece em "Últimos disparos", as opções de atraso e o padrão do dc. O estado de cada uma mora em
 * `comercial_automacao` (uma linha por chave, criada pela migration com o padrão daqui).
 */
enum Automacao: string
{
    case Cad = 'cad';
    case Lemb = 'lemb';
    case Val = 'val';
    case Conv = 'conv';
    case Dica = 'dica';
    case E90 = 'e90';
    case E100 = 'e100';
    case Ped = 'ped';

    public function titulo(): string
    {
        return match ($this) {
            self::Cad => 'Ao abrir uma conta',
            self::Lemb => 'E-mail não confirmado',
            self::Val => 'Ao confirmar o e-mail',
            self::Conv => 'Convite aceito (secundário)',
            self::Dica => '7 dias depois do cadastro',
            self::E90 => 'Conta chegou a 90% do espaço',
            self::E100 => 'Espaço esgotado (100%)',
            self::Ped => 'Pedido de mais espaço',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Cad => 'Envia o link e o código para confirmar o e-mail',
            self::Lemb => 'Reenvia a confirmação se não validar',
            self::Val => 'Boas-vindas com os primeiros passos',
            self::Conv => 'Boas-vindas para quem entrou na equipe',
            self::Dica => 'Dicas para aproveitar o sistema',
            self::E90 => 'Recomenda aumentar ou solicitar espaço (ao primário)',
            self::E100 => 'Avisa que novos envios estão pausados',
            self::Ped => 'Confirma ao cliente e avisa o Master',
        };
    }

    /** O rótulo do gatilho em "Últimos disparos" (dc L.565-566). */
    public function gatilho(): string
    {
        return match ($this) {
            self::Cad => 'Conta aberta',
            self::Lemb => 'Lembrete de confirmação',
            self::Val => 'E-mail confirmado',
            self::Conv => 'Convite aceito',
            self::Dica => '7 dias depois',
            self::E90 => 'Espaço em 90%',
            self::E100 => 'Espaço esgotado',
            self::Ped => 'Pedido de espaço',
        };
    }

    /** O nome do modelo no botão da linha (dc L.564). */
    public function modelo(): string
    {
        return match ($this) {
            self::Cad, self::Lemb => 'Confirmar e-mail',
            self::Val, self::Conv => 'Boas-vindas',
            self::Dica => 'Primeiros passos (7 dias)',
            self::E90 => 'Espaço em 90%',
            self::E100 => 'Espaço esgotado',
            self::Ped => 'Pedido de espaço recebido',
        };
    }

    /**
     * As opções de atraso do seletor, na ordem do dc; vazio = sem seletor (imediato).
     *
     * @return list<string>
     */
    public function opcoesDeAtraso(): array
    {
        return match ($this) {
            self::Lemb => ['24h', '48h', '72h'],
            self::Dica => ['3d', '7d', '15d'],
            self::E90 => ['80', '85', '90'],
            default => [],
        };
    }

    public function atrasoPadrao(): ?string
    {
        return match ($this) {
            self::Lemb => '24h',
            self::Dica => '7d',
            self::E90 => '90',
            default => null,
        };
    }

    public function ligadaPorPadrao(): bool
    {
        return $this !== self::Dica;
    }

    /** O `cad` é obrigatório: sem ele ninguém cria conta (P2). O interruptor aparece ligado e desabilitado. */
    public function obrigatoria(): bool
    {
        return $this === self::Cad;
    }

    public function origem(): string
    {
        return 'automacao:' . $this->value;
    }
}
