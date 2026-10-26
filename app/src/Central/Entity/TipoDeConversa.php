<?php

declare(strict_types=1);

namespace App\Central\Entity;

/**
 * Individual (A1: um par), grupo (B: nome, administrador, participantes que entram e saem) ou a conversa da pessoa
 * com o "BlueJus IA" (F3, `docs/specs/ia-chat-central.md`): uma por (escritório, usuário), só ele participa.
 */
enum TipoDeConversa: string
{
    case Individual = 'individual';
    case Grupo = 'grupo';
    case Ia = 'ia';
}
