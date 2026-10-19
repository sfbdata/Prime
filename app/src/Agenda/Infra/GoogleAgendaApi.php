<?php

declare(strict_types=1);

namespace App\Agenda\Infra;

use App\Agenda\Exception\AutorizacaoDoGoogleRecusadaException;
use App\Agenda\Exception\EventoRecusadoPeloGoogleException;
use App\Agenda\Exception\GoogleAgendaException;
use App\Agenda\Exception\SyncTokenVencidoException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OAuth 2.0 e Google Calendar API v3 pelo HttpClient do Symfony (entrega 6, sem biblioteca do Google).
 * Ligada só com GOOGLE_CALENDAR_OAUTH_CLIENT_ID e GOOGLE_CALENDAR_OAUTH_CLIENT_SECRET no servidor.
 */
final class GoogleAgendaApi
{
    public const ESCOPO = 'https://www.googleapis.com/auth/calendar.events';
    private const AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const REVOGAR = 'https://oauth2.googleapis.com/revoke';
    private const AGENDA = 'https://www.googleapis.com/calendar/v3/calendars/primary';
    private const TEMPO_SEGUNDOS = 15;
    /** 403 por limite de uso é passageiro (tenta na próxima volta); os outros 403 são recusa do evento. */
    private const LIMITES = ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'];

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(string:default::GOOGLE_CALENDAR_OAUTH_CLIENT_ID)%')]
        private readonly string $clienteId = '',
        #[Autowire('%env(string:default::GOOGLE_CALENDAR_OAUTH_CLIENT_SECRET)%')]
        private readonly string $segredo = '',
    ) {
    }

    public function ligado(): bool
    {
        return '' !== trim($this->clienteId) && '' !== trim($this->segredo);
    }

    /** PKCE S256 (`desafio`) e state contra CSRF; offline + consent para vir o refresh token. */
    public function urlDeAutorizacao(string $state, string $desafio, string $retorno): string
    {
        return self::AUTORIZAR . '?' . http_build_query([
            'client_id'             => $this->clienteId,
            'redirect_uri'          => $retorno,
            'response_type'         => 'code',
            'scope'                 => self::ESCOPO,
            'access_type'           => 'offline',
            'prompt'                => 'select_account consent',
            'include_granted_scopes' => 'true',
            'state'                 => $state,
            'code_challenge'        => $desafio,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** @return string o refresh token */
    public function trocarCodigo(string $codigo, string $verificador, string $retorno): string
    {
        $token = $this->token(['grant_type' => 'authorization_code', 'code' => $codigo, 'code_verifier' => $verificador, 'redirect_uri' => $retorno]);
        if (!is_string($token['refresh_token'] ?? null) || '' === $token['refresh_token']) {
            throw new GoogleAgendaException('O Google não devolveu a autorização permanente (refresh token).');
        }
        if (!str_contains(' ' . (string) ($token['scope'] ?? self::ESCOPO) . ' ', ' ' . self::ESCOPO . ' ')) {
            throw new GoogleAgendaException('A permissão de agenda não foi concedida.');
        }

        return $token['refresh_token'];
    }

    public function renovar(string $refreshToken): string
    {
        $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        if (!is_string($token['access_token'] ?? null)) {
            throw new GoogleAgendaException('O Google não devolveu o token de acesso.');
        }

        return $token['access_token'];
    }

    /**
     * E-mail (nome da agenda principal) e fuso, pela listagem de eventos: `calendars.get` pede um escopo
     * maior que `calendar.events`, e a listagem traz `summary` e `timeZone` no topo.
     *
     * @return array{conta: string, fuso: string}
     */
    public function agendaPrincipal(string $acesso): array
    {
        $j = $this->json($this->pedir('GET', self::AGENDA . '/events', $acesso, ['query' => ['maxResults' => 1]]));

        return ['conta' => (string) ($j['summary'] ?? ''), 'fuso' => (string) ($j['timeZone'] ?? 'America/Sao_Paulo')];
    }

    /**
     * O que mudou desde o syncToken (ou, sem ele, desde `desde`), em todas as páginas.
     *
     * @return array{itens: list<array<string, mixed>>, syncToken: ?string}
     */
    public function mudancas(string $acesso, ?string $syncToken, \DateTimeImmutable $desde): array
    {
        $base = null !== $syncToken
            ? ['syncToken' => $syncToken]
            : ['timeMin' => $desde->format(\DateTimeInterface::RFC3339)];
        $itens = [];
        $pagina = null;
        do {
            $j = $this->json($this->pedir('GET', self::AGENDA . '/events', $acesso, ['query' => $base + ['showDeleted' => 'true', 'singleEvents' => 'false', 'maxResults' => 250] + (null !== $pagina ? ['pageToken' => $pagina] : [])]));
            array_push($itens, ...array_values(array_filter((array) ($j['items'] ?? []), 'is_array')));
            $pagina = is_string($j['nextPageToken'] ?? null) ? $j['nextPageToken'] : null;
        } while (null !== $pagina);

        return ['itens' => $itens, 'syncToken' => is_string($j['nextSyncToken'] ?? null) ? $j['nextSyncToken'] : null];
    }

    /**
     * Cria ou atualiza o evento com o id dado (id nosso, determinístico: inserir duas vezes não duplica).
     *
     * @param array<string, mixed> $corpo
     */
    public function gravar(string $acesso, string $id, array $corpo, bool $jaExiste): void
    {
        $url = self::AGENDA . '/events';
        $resposta = $jaExiste
            ? $this->pedir('PUT', $url . '/' . rawurlencode($id), $acesso, ['json' => $corpo], [404])
            : $this->pedir('POST', $url, $acesso, ['json' => ['id' => $id] + $corpo], [409]);
        $status = $resposta->getStatusCode();
        if (404 === $status) {
            $this->pedir('POST', $url, $acesso, ['json' => ['id' => $id] + $corpo], [409]);
        } elseif (409 === $status) {
            $this->pedir('PUT', $url . '/' . rawurlencode($id), $acesso, ['json' => $corpo]);
        }
    }

    /** Apaga no Google; já apagado (404/410) conta como feito. */
    public function apagar(string $acesso, string $id): void
    {
        $this->pedir('DELETE', self::AGENDA . '/events/' . rawurlencode($id), $acesso, [], [404, 410]);
    }

    /** Revoga a autorização na conta Google (LGPD, art. 8º, § 5º). Falha não impede desconectar aqui. */
    public function revogar(string $refreshToken): void
    {
        try {
            $this->http->request('POST', self::REVOGAR, ['body' => ['token' => $refreshToken], 'timeout' => self::TEMPO_SEGUNDOS])->getStatusCode();
        } catch (ExceptionInterface) {
        }
    }

    /**
     * @param array<string, string> $campos
     *
     * @return array<string, mixed>
     */
    private function token(array $campos): array
    {
        try {
            $r = $this->http->request('POST', self::TOKEN, ['body' => $campos + ['client_id' => $this->clienteId, 'client_secret' => $this->segredo], 'timeout' => self::TEMPO_SEGUNDOS]);
            $status = $r->getStatusCode();
            $j = $r->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new GoogleAgendaException('Sem resposta do Google: ' . $e->getMessage(), 0, $e);
        }
        if ('invalid_grant' === ($j['error'] ?? null)) {
            throw new AutorizacaoDoGoogleRecusadaException('O Google recusou a autorização.');
        }
        if (200 !== $status) {
            throw new GoogleAgendaException('O Google recusou o pedido de token (' . $status . ').');
        }

        return $j;
    }

    /**
     * @param array<string, mixed> $opcoes
     * @param list<int>            $aceitos códigos de erro que o chamador trata
     */
    private function pedir(string $metodo, string $url, string $acesso, array $opcoes = [], array $aceitos = []): ResponseInterface
    {
        try {
            $r = $this->http->request($metodo, $url, $opcoes + ['auth_bearer' => $acesso, 'timeout' => self::TEMPO_SEGUNDOS]);
            $status = $r->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new GoogleAgendaException('Sem resposta do Google: ' . $e->getMessage(), 0, $e);
        }
        if (410 === $status && !in_array(410, $aceitos, true)) {
            throw new SyncTokenVencidoException('O Google pediu para recomeçar a sincronia.');
        }
        if (401 === $status) {
            throw new AutorizacaoDoGoogleRecusadaException('O Google recusou o acesso à agenda.');
        }
        if (in_array($status, $aceitos, true) || $status < 400) {
            return $r;
        }
        if (400 === $status || (403 === $status && !$this->ehLimiteDeUso($r))) {
            throw new EventoRecusadoPeloGoogleException('O Google recusou o evento (' . $status . ' em ' . $metodo . ').');
        }

        throw new GoogleAgendaException('O Google respondeu ' . $status . ' em ' . $metodo . '.');
    }

    private function ehLimiteDeUso(ResponseInterface $r): bool
    {
        try {
            $erros = $r->toArray(false)['error']['errors'] ?? [];
        } catch (ExceptionInterface) {
            return false;
        }

        return is_array($erros) && in_array($erros[0]['reason'] ?? '', self::LIMITES, true);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $r): array
    {
        try {
            return $r->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new GoogleAgendaException('Resposta do Google ilegível.', 0, $e);
        }
    }
}
