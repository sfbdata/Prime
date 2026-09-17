<?php

declare(strict_types=1);

namespace App\Sync\Exception;

/**
 * O Google não devolveu access token utilizável ao renovar a credencial do Drive (DT-8) — refresh token
 * revogado, app com client_id/secret errado, erro do servidor de token, resposta sem token, token que já
 * nasce vencido (sem `expires_in`). Lançada ANTES de qualquer chamada à API: sem ela o cliente do Google
 * seguia sem credencial e a resposta de erro virava o conteúdo baixado.
 */
final class TokenDoDriveRecusadoException extends \RuntimeException
{
    /**
     * `$erro` é o código devolvido pelo Google (já validado como identificador); `$motivo` é o texto
     * limpo, sem credencial. A dica só aparece quando o código diz o que fazer.
     */
    public static function naRenovacao(?string $erro, ?string $motivo): self
    {
        $dica = match ($erro) {
            'invalid_grant'  => ' Reconecte o Drive do escritório.',
            'invalid_client' => ' Confira GOOGLE_DRIVE_OAUTH_CLIENT_ID e GOOGLE_DRIVE_OAUTH_CLIENT_SECRET.',
            default          => '',
        };

        return new self(sprintf(
            'O Google não devolveu access token utilizável ao renovar a credencial do Drive (%s); nenhuma chamada foi feita.%s',
            $motivo ?? 'a resposta não trouxe access_token',
            $dica,
        ));
    }
}
