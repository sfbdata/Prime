<?php

declare(strict_types=1);

namespace App\Comercial\Consentimento;

use App\Entity\Tenant\Tenant;

/**
 * Double opt-in reaproveitável por qualquer lista com consentimento (alertas de vagas da a7, base de contatos das
 * campanhas). Spec `comercial-e-emails.md` §3.3. `escopo` null = plataforma.
 *
 * - `pedir` grava o fato "pedido" (com a versão e o texto que a pessoa viu) e manda o e-mail com o link de 7 dias.
 *   Sobre endereço já confirmado não faz nada e não revela isso. Limites: 1 pedido por endereço e lista a cada 24 h
 *   (30 dias se o endereço saiu da lista); 200 por hora no total. O limite por IP é do controller do consumidor.
 * - `confirmar` é da rota pública desta frente (POST, nunca o GET).
 * - `estaConfirmado`: confirmação vigente e o endereço não descadastrado da lista.
 */
interface ConfirmacaoDeEnderecoInterface
{
    public function pedir(string $lista, string $email, string $origem, string $versaoDoConsentimento, string $textoDoConsentimento, ?Tenant $escopo = null): void;

    public function confirmar(string $token, string $ip, ?string $agente): ResultadoDaConfirmacao;

    public function estaConfirmado(string $lista, string $email, ?Tenant $escopo = null): bool;
}
