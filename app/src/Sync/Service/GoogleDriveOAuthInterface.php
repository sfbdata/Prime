<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Sync\DTO\ResultadoAutenticacaoDrive;

/**
 * Fluxo OAuth "authorization code" do Google, para o "Conectar meu Drive" por escritório.
 * Usa as credenciais GLOBAIS do app `bluejus-sync` (client_id/secret) — não depende de tenant.
 * Fronteira de integração (rede): a implementação real é validada manualmente; os testes usam fake.
 */
interface GoogleDriveOAuthInterface
{
    /**
     * URL de consentimento do Google. `$state` (anti-CSRF) volta no callback; `$redirectUri` deve
     * bater exatamente com um registrado no app. Pede escopo Drive + offline + consent (garante refresh_token).
     */
    public function criarUrlDeAutorizacao(string $redirectUri, string $state): string;

    /**
     * Troca o `code` recebido no callback pelo refresh_token (+ e-mail e escopo da conta).
     * Lança se o Google não devolver refresh_token (exige re-consentimento) ou em erro da API.
     */
    public function trocarCodePorRefreshToken(string $code, string $redirectUri): ResultadoAutenticacaoDrive;
}
