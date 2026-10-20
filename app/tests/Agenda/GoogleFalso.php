<?php

declare(strict_types=1);

namespace App\Tests\Agenda;

use App\Agenda\Infra\GoogleAgendaApi;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Google falso para o MockHttpClient (entrega 6, modelo do dono de 20/10/2026): guarda as agendas que o BlueJus
 * cria e os eventos de cada uma na memória e responde como a API (token com `id_token`, criar e apagar agenda,
 * mudanças com syncToken, inserir com 409, atualizar com 404, apagar, revogar). Como o Google real, a listagem
 * seguinte devolve também o que foi gravado (o eco do nosso envio); sem syncToken, devolve tudo. Agenda que não
 * existe responde 404. `pedidos` registra "MÉTODO caminho" de cada chamada.
 */
final class GoogleFalso
{
    /** @var array<string, array<string, array<string, mixed>>> agenda => id => evento */
    public array $eventos = [];
    /** @var array<string, list<array<string, mixed>>> agenda => o que a próxima listagem devolve como mudança */
    public array $paraTrazer = [];
    /** @var array<string, string> agenda => nome */
    public array $agendas = [];
    /** @var list<string> */
    public array $pedidos = [];
    public string $conta = 'pessoa@gmail.com';
    public bool $recusarAutorizacao = false;
    public bool $emailConfirmado = true;
    public bool $syncTokenVencido = false;
    /** @var list<string> ids que o Google recusa gravar (403) */
    public array $recusar = [];
    /** @var array<string, array<string, array<string, mixed>>> agenda => gravado desde a última listagem */
    private array $eco = [];
    private int $rodada = 0;

    public function api(): GoogleAgendaApi
    {
        return new GoogleAgendaApi(new MockHttpClient($this->responder(...)), 'cliente-teste', 'segredo-teste');
    }

    /** A pessoa apagou a agenda lá. */
    public function apagarAgendaNoGoogle(string $agenda): void
    {
        unset($this->agendas[$agenda], $this->eventos[$agenda], $this->eco[$agenda]);
    }

    /** @param array<string, mixed> $opcoes */
    public function responder(string $metodo, string $url, array $opcoes): MockResponse
    {
        $caminho = (string) parse_url($url, \PHP_URL_PATH);
        $this->pedidos[] = $metodo . ' ' . $caminho;
        if (str_ends_with($caminho, '/token')) {
            return $this->token((string) ($opcoes['body'] ?? ''));
        }
        if (str_ends_with($caminho, '/revoke')) {
            return new MockResponse('');
        }
        if ('/calendar/v3/calendars' === $caminho) {
            return $this->criarAgenda($opcoes);
        }
        $partes = explode('/', substr($caminho, strlen('/calendar/v3/calendars/')));
        $agenda = rawurldecode($partes[0]);

        return match (true) {
            1 === count($partes) && 'DELETE' === $metodo => $this->apagarAgenda($agenda),
            !isset($this->agendas[$agenda]) => $this->json(['error' => ['code' => 404]], 404),
            2 === count($partes) && 'GET' === $metodo => $this->listar($agenda, $url),
            2 === count($partes) && 'POST' === $metodo => $this->inserir($agenda, $opcoes),
            default => $this->umEvento($agenda, $metodo, rawurldecode((string) end($partes)), $opcoes),
        };
    }

    private function token(string $corpo): MockResponse
    {
        parse_str($corpo, $campos);
        if ($this->recusarAutorizacao) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        if ('authorization_code' === ($campos['grant_type'] ?? null)) {
            $carga = rtrim(strtr(base64_encode((string) json_encode(['email' => $this->conta, 'email_verified' => $this->emailConfirmado])), '+/', '-_'), '=');

            return $this->json(['access_token' => 'acesso', 'refresh_token' => 'REFRESH-SECRETO', 'scope' => 'openid email ' . GoogleAgendaApi::ESCOPO, 'id_token' => 'cab.' . $carga . '.ass']);
        }

        return $this->json(['access_token' => 'acesso', 'expires_in' => 3600]);
    }

    /** @param array<string, mixed> $opcoes */
    private function criarAgenda(array $opcoes): MockResponse
    {
        $corpo = json_decode((string) ($opcoes['body'] ?? '{}'), true);
        $id = 'agenda-' . (count($this->agendas) + 1) . '-' . bin2hex(random_bytes(3)) . '@group.calendar.google.com';
        $this->agendas[$id] = (string) ($corpo['summary'] ?? '');
        $this->eventos[$id] = [];
        $this->eco[$id] = [];

        return $this->json(['id' => $id, 'summary' => $this->agendas[$id], 'timeZone' => $corpo['timeZone'] ?? '']);
    }

    private function apagarAgenda(string $agenda): MockResponse
    {
        $existia = isset($this->agendas[$agenda]);
        $this->apagarAgendaNoGoogle($agenda);

        return new MockResponse('', ['http_code' => $existia ? 204 : 404]);
    }

    private function listar(string $agenda, string $url): MockResponse
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
        if ($this->syncTokenVencido && isset($q['syncToken'])) {
            $this->syncTokenVencido = false;

            return $this->json(['error' => ['code' => 410]], 410);
        }
        $gravados = isset($q['syncToken']) ? array_values($this->eco[$agenda] ?? []) : array_values($this->eventos[$agenda] ?? []);
        $itens = [...$gravados, ...($this->paraTrazer[$agenda] ?? [])];
        unset($this->paraTrazer[$agenda]);
        $this->eco[$agenda] = [];

        return $this->json(['summary' => $this->agendas[$agenda], 'timeZone' => 'America/Sao_Paulo', 'items' => $itens, 'nextSyncToken' => 'st-' . ++$this->rodada]);
    }

    /** @param array<string, mixed> $opcoes */
    private function inserir(string $agenda, array $opcoes): MockResponse
    {
        $corpo = json_decode((string) ($opcoes['body'] ?? '{}'), true);
        if (in_array($corpo['id'], $this->recusar, true)) {
            return $this->json(['error' => ['code' => 403, 'errors' => [['reason' => 'forbiddenForNonOrganizer']]]], 403);
        }
        if (isset($this->eventos[$agenda][$corpo['id']])) {
            return $this->json(['error' => ['code' => 409]], 409);
        }
        $this->guardar($agenda, $corpo['id'], $corpo);

        return $this->json($corpo);
    }

    /** @param array<string, mixed> $opcoes */
    private function umEvento(string $agenda, string $metodo, string $id, array $opcoes): MockResponse
    {
        if ('DELETE' === $metodo) {
            $existia = isset($this->eventos[$agenda][$id]);
            unset($this->eventos[$agenda][$id]);
            $this->eco[$agenda][$id] = ['id' => $id, 'status' => 'cancelled'];

            return new MockResponse('', ['http_code' => $existia ? 204 : 410]);
        }
        if (in_array($id, $this->recusar, true)) {
            return $this->json(['error' => ['code' => 403, 'errors' => [['reason' => 'forbiddenForNonOrganizer']]]], 403);
        }
        if (!isset($this->eventos[$agenda][$id])) {
            return $this->json(['error' => ['code' => 404]], 404);
        }
        if ('GET' === $metodo) {
            return $this->json($this->eventos[$agenda][$id] + ['etag' => '"1"', 'kind' => 'calendar#event']);
        }
        // Como o events.update do Google: o corpo troca o evento inteiro.
        $corpo = json_decode((string) ($opcoes['body'] ?? '{}'), true);
        $this->guardar($agenda, $id, ['id' => $id] + $corpo);

        return $this->json($this->eventos[$agenda][$id]);
    }

    /**
     * Grava como o Google devolve: horas com deslocamento de Brasília; o organizador é a própria agenda.
     *
     * @param array<string, mixed> $evento
     */
    private function guardar(string $agenda, string $id, array $evento): void
    {
        foreach (['start', 'end'] as $ponta) {
            if (isset($evento[$ponta]['dateTime']) && 19 === strlen($evento[$ponta]['dateTime'])) {
                $evento[$ponta]['dateTime'] .= '-03:00';
            }
        }
        $evento['organizer'] = ['email' => $agenda, 'self' => true];
        $this->eventos[$agenda][$id] = $evento;
        $this->eco[$agenda][$id] = $evento;
    }

    /** @param array<string, mixed> $dados */
    private function json(array $dados, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($dados), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
