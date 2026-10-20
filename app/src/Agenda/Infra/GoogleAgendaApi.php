<?php

declare(strict_types=1);

namespace App\Agenda\Infra;

use App\Agenda\Exception\AgendaDoEscritorioSumiuException;
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
 *
 * Modelo do dono (20/10/2026): o BlueJus só mexe na agenda secundária que ele mesmo criou na conta, com o nome
 * do escritório. Escopo `calendar.app.created` ("Make secondary Google calendars, and see, create, change, and
 * delete events on them"): a agenda principal da pessoa fica fora do alcance. `openid email` só para saber o
 * e-mail da conta (no `id_token`). Não depende do login do BlueJus (o "Entrar com o Google" é outra coisa).
 */
final class GoogleAgendaApi
{
    public const ESCOPO = 'https://www.googleapis.com/auth/calendar.app.created';
    public const FUSO = 'America/Sao_Paulo';
    private const ESCOPOS = 'openid email ' . self::ESCOPO;
    /** O que a conexão aceita receber: o e-mail chega também com o nome longo. Mais que isso, recusa e revoga. */
    private const ESCOPOS_ACEITOS = ['openid', 'email', 'https://www.googleapis.com/auth/userinfo.email', self::ESCOPO];
    private const EMISSORES = ['https://accounts.google.com', 'accounts.google.com'];
    private const AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const REVOGAR = 'https://oauth2.googleapis.com/revoke';
    private const AGENDAS = 'https://www.googleapis.com/calendar/v3/calendars';
    private const TEMPO_SEGUNDOS = 15;
    /** O que o BlueJus manda no evento; o resto (convidados, Meet, lembretes, cor) é de quem usa o Google. */
    private const CAMPOS_DO_BLUEJUS = ['summary', 'description', 'location', 'status', 'visibility', 'start', 'end', 'recurrence'];
    /** Só leitura na API: não voltam na atualização. */
    private const CAMPOS_DO_GOOGLE = ['etag', 'kind', 'htmlLink', 'created', 'updated'];
    /** 403 por limite de uso é passageiro (tenta na próxima volta); os outros 403 são recusa do evento. */
    private const LIMITES = ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'];

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(string:default::GOOGLE_CALENDAR_OAUTH_CLIENT_ID)%')]
        private readonly string $clienteId = '',
        #[Autowire('%env(string:default::GOOGLE_CALENDAR_OAUTH_CLIENT_SECRET)%')]
        private readonly string $segredo = '',
        // Sem a chave que cifra o token (`SYNC_CRYPTO_KEY`), não há como guardar a autorização: fica desligado.
        #[Autowire('%sync_crypto_key%')]
        private readonly string $chaveDosSegredos = '',
    ) {
    }

    public function ligado(): bool
    {
        return '' !== trim($this->clienteId) && '' !== trim($this->segredo) && '' !== trim($this->chaveDosSegredos);
    }

    /**
     * PKCE S256 (`desafio`) e state contra CSRF; offline + consent para vir o refresh token. `sugestao`: o
     * e-mail do cadastro, só sugerido (a pessoa pode escolher qualquer conta).
     */
    public function urlDeAutorizacao(string $state, string $desafio, string $retorno, string $sugestao = ''): string
    {
        return self::AUTORIZAR . '?' . http_build_query(array_filter([
            'client_id'             => $this->clienteId,
            'redirect_uri'          => $retorno,
            'response_type'         => 'code',
            'scope'                 => self::ESCOPOS,
            'access_type'           => 'offline',
            'prompt'                => 'select_account consent',
            // Só os escopos pedidos aqui: nada herdado de outra autorização do mesmo cliente (ex.: um login futuro).
            'include_granted_scopes' => 'false',
            'login_hint'            => $sugestao,
            'state'                 => $state,
            'code_challenge'        => $desafio,
            'code_challenge_method' => 'S256',
        ], static fn (string $v): bool => '' !== $v));
    }

    /** @return array{refresh: string, conta: string} o refresh token e o e-mail da conta */
    public function trocarCodigo(string $codigo, string $verificador, string $retorno): array
    {
        $token = $this->token(['grant_type' => 'authorization_code', 'code' => $codigo, 'code_verifier' => $verificador, 'redirect_uri' => $retorno]);
        if (!is_string($token['refresh_token'] ?? null) || '' === $token['refresh_token']) {
            throw new GoogleAgendaException('O Google não devolveu a autorização permanente (refresh token).');
        }
        $escopos = preg_split('/\s+/', trim((string) ($token['scope'] ?? self::ESCOPO))) ?: [];
        if (!in_array(self::ESCOPO, $escopos, true) || [] !== array_diff($escopos, self::ESCOPOS_ACEITOS)) {
            $this->revogar($token['refresh_token']);
            throw new GoogleAgendaException('O Google concedeu permissões diferentes das pedidas para a agenda.');
        }

        return ['refresh' => $token['refresh_token'], 'conta' => $this->emailDoIdToken((string) ($token['id_token'] ?? ''))];
    }

    public function renovar(string $refreshToken): string
    {
        $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        if (!is_string($token['access_token'] ?? null)) {
            throw new GoogleAgendaException('O Google não devolveu o token de acesso.');
        }

        return $token['access_token'];
    }

    /** Cria a agenda do escritório na conta. @return string o id dela */
    public function criarAgenda(string $acesso, string $nome): string
    {
        $j = $this->json($this->pedir('POST', self::AGENDAS, $acesso, ['json' => ['summary' => mb_substr($nome, 0, 250), 'timeZone' => self::FUSO]]));
        if (!is_string($j['id'] ?? null) || '' === $j['id']) {
            throw new GoogleAgendaException('O Google não devolveu a agenda criada.');
        }

        return $j['id'];
    }

    /** Apaga a agenda do escritório (Desconectar, se a pessoa pedir). Já apagada (404/410) conta como feito. */
    public function apagarAgenda(string $acesso, string $calendario): void
    {
        $this->pedir('DELETE', $this->agenda($calendario), $acesso, [], [404, 410]);
    }

    /**
     * O que mudou na agenda do escritório desde o syncToken (ou, sem ele, desde `desde`), em todas as páginas.
     *
     * @return array{itens: list<array<string, mixed>>, syncToken: ?string}
     */
    public function mudancas(string $acesso, string $calendario, ?string $syncToken, \DateTimeImmutable $desde): array
    {
        $base = null !== $syncToken
            ? ['syncToken' => $syncToken]
            : ['timeMin' => $desde->format(\DateTimeInterface::RFC3339)];
        $itens = [];
        $pagina = null;
        do {
            $r = $this->pedir('GET', $this->agenda($calendario) . '/events', $acesso, ['query' => $base + ['showDeleted' => 'true', 'singleEvents' => 'false', 'maxResults' => 250] + (null !== $pagina ? ['pageToken' => $pagina] : [])], [404]);
            $j = $this->json($this->semAgenda($r));
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
    public function gravar(string $acesso, string $calendario, string $id, array $corpo, bool $jaExiste): void
    {
        $url = $this->agenda($calendario) . '/events';
        $resposta = $jaExiste
            ? $this->atualizar($acesso, $url . '/' . rawurlencode($id), $corpo)
            : $this->semAgenda($this->pedir('POST', $url, $acesso, ['json' => ['id' => $id] + $corpo], [409, 404]));
        $status = $resposta->getStatusCode();
        if (in_array($status, [404, 410], true)) {
            $this->semAgenda($this->pedir('POST', $url, $acesso, ['json' => ['id' => $id] + $corpo], [409, 404]));
        } elseif (409 === $status) {
            $this->atualizar($acesso, $url . '/' . rawurlencode($id), $corpo);
        }
    }

    /**
     * O `events.update` do Google troca o evento inteiro: lê o que está lá e troca só os campos do BlueJus,
     * para não apagar convidados, Meet e lembretes postos no Google. Sumido (404 ou 410) volta para quem chamou.
     *
     * @param array<string, mixed> $corpo
     */
    private function atualizar(string $acesso, string $urlDoEvento, array $corpo): ResponseInterface
    {
        $atual = $this->pedir('GET', $urlDoEvento, $acesso, [], [404, 410]);
        if (in_array($atual->getStatusCode(), [404, 410], true)) {
            return $atual;
        }
        $deles = array_diff_key($this->json($atual), array_flip([...self::CAMPOS_DO_BLUEJUS, ...self::CAMPOS_DO_GOOGLE]));

        return $this->pedir('PUT', $urlDoEvento, $acesso, ['json' => $corpo + $deles], [404]);
    }

    /** Apaga o evento na agenda do escritório; já apagado (404/410) conta como feito. */
    public function apagar(string $acesso, string $calendario, string $id): void
    {
        $this->pedir('DELETE', $this->agenda($calendario) . '/events/' . rawurlencode($id), $acesso, [], [404, 410]);
    }

    /** Revoga a autorização na conta Google (LGPD, art. 8º, § 5º). Falha não impede desconectar aqui. */
    public function revogar(string $refreshToken): void
    {
        try {
            $this->http->request('POST', self::REVOGAR, ['body' => ['token' => $refreshToken], 'timeout' => self::TEMPO_SEGUNDOS])->getStatusCode();
        } catch (ExceptionInterface) {
        }
    }

    private function agenda(string $calendario): string
    {
        return self::AGENDAS . '/' . rawurlencode($calendario);
    }

    /** 404 na listagem ou na criação de evento: a agenda do escritório não existe mais na conta. */
    private function semAgenda(ResponseInterface $r): ResponseInterface
    {
        if (404 === $r->getStatusCode()) {
            throw new AgendaDoEscritorioSumiuException('A agenda do escritório não existe mais no Google.');
        }

        return $r;
    }

    /**
     * O e-mail confirmado da conta, do `id_token` que o próprio Google devolveu na troca do código (canal TLS
     * direto com o Google, OpenID Connect Core 3.1.3.7: sem conferir assinatura, mas conferindo `aud`, `iss` e
     * `exp`, 3.1.3.7 itens 2, 3 e 9).
     */
    private function emailDoIdToken(string $idToken): string
    {
        $carga = $this->cargaDoIdToken($idToken);
        $email = is_string($carga['email'] ?? null) ? trim($carga['email']) : '';
        // Só e-mail confirmado pelo Google: ele casa convidados com a equipe (ParticipantesDoGoogle).
        if ('' === $email || true !== ($carga['email_verified'] ?? null)) {
            throw new GoogleAgendaException('O Google não informou um e-mail confirmado da conta.');
        }

        return $email;
    }

    /** @return array<string, mixed> a carga do `id_token`, se for para este cliente, do Google e ainda válida */
    private function cargaDoIdToken(string $idToken): array
    {
        $partes = explode('.', $idToken);
        $carga = 3 === count($partes) ? json_decode((string) base64_decode(strtr($partes[1], '-_', '+/'), false), true) : null;
        if (!is_array($carga)) {
            throw new GoogleAgendaException('O Google não devolveu a identificação da conta.');
        }
        $audiencia = $carga['aud'] ?? null;
        $paraNos = is_array($audiencia) ? in_array($this->clienteId, $audiencia, true) : $audiencia === $this->clienteId;
        if (!$paraNos || !in_array($carga['iss'] ?? null, self::EMISSORES, true) || !is_int($carga['exp'] ?? null) || $carga['exp'] <= time()) {
            throw new GoogleAgendaException('A identificação da conta não confere (cliente, emissor ou validade).');
        }

        return $carga;
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
