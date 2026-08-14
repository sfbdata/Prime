<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Sync\DTO\ResultadoAutenticacaoDrive;
use Google\Client;
use Google\Service\Drive;

/**
 * Implementação real do fluxo OAuth authorization-code sobre `google/apiclient`, usando as
 * credenciais globais do app `bluejus-sync`. Fronteira de rede: validada manualmente, fora do CI
 * (os testes do "Conectar meu Drive" injetam um fake de {@see GoogleDriveOAuthInterface}).
 */
final class GoogleDriveOAuth implements GoogleDriveOAuthInterface
{
    public function __construct(
        private readonly string $googleDriveOauthClientId,
        private readonly string $googleDriveOauthClientSecret,
    ) {
    }

    public function criarUrlDeAutorizacao(string $redirectUri, string $state): string
    {
        $client = $this->clientBase($redirectUri);
        // O state (anti-CSRF) TEM de ir na URL para o Google ecoá-lo no callback; sem isso o
        // callback rejeitaria sempre. Validado por unit test que inspeciona a URL gerada.
        $client->setState($state);

        return $client->createAuthUrl();
    }

    public function trocarCodePorRefreshToken(string $code, string $redirectUri): ResultadoAutenticacaoDrive
    {
        $client = $this->clientBase($redirectUri);

        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            throw new \RuntimeException('Falha na troca do code OAuth: ' . (string) ($token['error_description'] ?? $token['error']));
        }

        $refreshToken = $token['refresh_token'] ?? '';
        if ($refreshToken === '') {
            throw new \RuntimeException('O Google não retornou refresh_token — refaça a conexão concedendo o acesso (consentimento).');
        }

        $client->setAccessToken($token);

        return new ResultadoAutenticacaoDrive(
            $refreshToken,
            $this->emailDaConta($client),
            $token['scope'] ?? null,
        );
    }

    private function clientBase(string $redirectUri): Client
    {
        if (trim($this->googleDriveOauthClientId) === '' || trim($this->googleDriveOauthClientSecret) === '') {
            throw new \RuntimeException('OAuth do Drive incompleto: faltam GOOGLE_DRIVE_OAUTH_CLIENT_ID/SECRET.');
        }

        $client = new Client();
        $client->setClientId($this->googleDriveOauthClientId);
        $client->setClientSecret($this->googleDriveOauthClientSecret);
        $client->setRedirectUri($redirectUri);
        $client->addScope(Drive::DRIVE);
        $client->setAccessType('offline');
        // `consent` força o Google a devolver um refresh_token novo sempre.
        // `select_account` conserta o "trocar de conta": só com `consent`, se o navegador já
        // está logado na conta antiga, o Google pula direto para o consentimento DELA e o
        // usuário reconecta a MESMA conta achando que trocou — falha silenciosa, sem erro
        // nenhum na tela. Com `select_account` o seletor de contas aparece sempre.
        $client->setPrompt('select_account consent');

        return $client;
    }

    /** E-mail da conta autenticada (só para exibir). Falha silenciosa — não é essencial. */
    private function emailDaConta(Client $client): ?string
    {
        try {
            $about = (new Drive($client))->about->get(['fields' => 'user']);

            return $about->getUser()?->getEmailAddress();
        } catch (\Throwable) {
            return null;
        }
    }
}
