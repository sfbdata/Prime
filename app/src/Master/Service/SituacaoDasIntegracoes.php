<?php

declare(strict_types=1);

namespace App\Master\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Situação das conexões externas do cartão "Integrações" da seção Desempenho (desenho 05 Perfis 1.2 VS 25.10,
 * `dsInt`; spec docs/specs/master-desempenho.md D4). "Conectada" = a credencial da plataforma está configurada no
 * ambiente. Só diz se existe credencial, não testa a chamada: o teste de verdade é o ping/erro que o monitor mede.
 *
 * Banco e PIX ficam como não configurados até existir o gateway de pagamentos da plataforma
 * (docs/specs/gateway-pagamentos.md): hoje a conexão com o banco é por escritório, não da plataforma.
 */
final class SituacaoDasIntegracoes
{
    public function __construct(
        #[Autowire(env: 'MAILER_DSN')]
        private readonly string $mailerDsn,
        #[Autowire(env: 'string:default::GOOGLE_LOGIN_OAUTH_CLIENT_ID')]
        private readonly string $googleLogin,
        #[Autowire(env: 'string:default::GOOGLE_CALENDAR_OAUTH_CLIENT_ID')]
        private readonly string $googleAgenda,
        #[Autowire(env: 'string:default::FACEBOOK_APP_ID')]
        private readonly string $facebookAppId,
        #[Autowire(env: 'DJEN_BASE_URL')]
        private readonly string $djenBaseUrl,
        #[Autowire(env: 'string:default::ANTHROPIC_API_KEY')]
        private readonly string $anthropicChave,
    ) {
    }

    /**
     * @return list<array{chave: string, conectada: bool}> na ordem do desenho
     */
    public function situacao(): array
    {
        return [
            ['chave' => 'email', 'conectada' => $this->mailerDsn !== '' && !str_starts_with($this->mailerDsn, 'null://')],
            ['chave' => 'banco_pix', 'conectada' => false],
            ['chave' => 'google', 'conectada' => $this->googleLogin !== '' || $this->googleAgenda !== ''],
            ['chave' => 'facebook', 'conectada' => $this->facebookAppId !== ''],
            ['chave' => 'tribunais', 'conectada' => $this->djenBaseUrl !== ''],
            ['chave' => 'ia', 'conectada' => $this->anthropicChave !== ''],
        ];
    }
}
