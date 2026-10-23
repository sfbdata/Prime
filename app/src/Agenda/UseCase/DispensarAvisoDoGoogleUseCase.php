<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Janela que orienta a conectar o Google Agenda (pedido do dono, 23/10/2026, spec docs/specs/agenda-aviso-google.md).
 *
 * Quem: o usuário logado, para ele mesmo, no escritório da sessão (usuário e escritório vêm da sessão, nunca do
 * corpo da requisição). O quê: `executar()` grava a dispensa ("Não mostrar esta mensagem novamente") em
 * `preferencia_usuario`, chave {@see self::CHAVE} com `true`; `mostrar()` decide se a janela abre nesta entrada.
 * Por quê: a escolha segue a pessoa em qualquer computador e vale só no escritório em que foi feita.
 *
 * Gravar de novo é inofensivo (o repositório é idempotente).
 */
final class DispensarAvisoDoGoogleUseCase
{
    public const CHAVE = 'agenda.google_aviso_dispensado';

    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    public function executar(Tenant $tenant, User $eu): void
    {
        $this->preferencias->gravar($tenant, $eu, self::CHAVE, true);
    }

    /**
     * Abre a cada entrada na Agenda, menos para quem já está conectado, para quem dispensou e quando o servidor
     * está sem as credenciais do Google ({@see EstadoDoGoogleAgendaUseCase}).
     */
    public function mostrar(Tenant $tenant, User $eu, string $estadoDoGoogle): bool
    {
        if (EstadoDoGoogleAgendaUseCase::NAO_CONECTADO !== $estadoDoGoogle) {
            return false;
        }

        return true !== ($this->preferencias->valoresDoUsuario($tenant, $eu, [self::CHAVE])[self::CHAVE] ?? null);
    }
}
