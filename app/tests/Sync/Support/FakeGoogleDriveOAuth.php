<?php

declare(strict_types=1);

namespace App\Tests\Sync\Support;

use App\Sync\DTO\ResultadoAutenticacaoDrive;
use App\Sync\Service\GoogleDriveOAuthInterface;

/**
 * Fake do fluxo OAuth para os testes do "Conectar meu Drive" — nenhum teste toca o Google.
 * Devolve valores canned e registra o último `state`/`code` recebidos para asserções.
 */
final class FakeGoogleDriveOAuth implements GoogleDriveOAuthInterface
{
    public ?string $ultimoState = null;
    public ?string $ultimoCode = null;
    public ?string $ultimoRedirectUri = null;

    /** Se setado, trocarCodePorRefreshToken lança este erro (simula code inválido / sem refresh_token). */
    public ?\Throwable $erroNaTroca = null;

    public function __construct(
        private readonly string $refreshTokenCanned = 'refresh-token-fake',
        private readonly ?string $emailCanned = 'dono@escritorio.com',
        private readonly ?string $escopoCanned = 'https://www.googleapis.com/auth/drive',
    ) {
    }

    public function criarUrlDeAutorizacao(string $redirectUri, string $state): string
    {
        $this->ultimoState = $state;
        $this->ultimoRedirectUri = $redirectUri;

        return 'https://accounts.google.com/o/oauth2/v2/auth?state=' . urlencode($state)
            . '&redirect_uri=' . urlencode($redirectUri);
    }

    public function trocarCodePorRefreshToken(string $code, string $redirectUri): ResultadoAutenticacaoDrive
    {
        $this->ultimoCode = $code;
        $this->ultimoRedirectUri = $redirectUri;

        if ($this->erroNaTroca !== null) {
            throw $this->erroNaTroca;
        }

        return new ResultadoAutenticacaoDrive($this->refreshTokenCanned, $this->emailCanned, $this->escopoCanned);
    }
}
