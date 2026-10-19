<?php

declare(strict_types=1);

namespace App\Tests\Agenda;

use App\Agenda\Infra\GoogleAgendaApi;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Google falso para o MockHttpClient (entrega 6): guarda os eventos na memória e responde como a API
 * (token, agenda principal, mudanças com syncToken, inserir com 409, atualizar com 404, apagar, revogar).
 * Como o Google real, a listagem seguinte devolve também o que foi gravado (o eco do nosso envio); sem
 * syncToken, devolve tudo. `pedidos` registra "MÉTODO caminho" de cada chamada.
 */
final class GoogleFalso
{
    /** @var array<string, array<string, mixed>> */
    public array $eventos = [];
    /** @var list<array<string, mixed>> o que a próxima listagem devolve como mudança */
    public array $paraTrazer = [];
    /** @var list<string> */
    public array $pedidos = [];
    /** @var list<array<string, mixed>> corpos JSON enviados (POST/PUT de evento) */
    public array $corpos = [];
    public bool $recusarAutorizacao = false;
    public bool $syncTokenVencido = false;
    /** @var list<string> ids que o Google recusa gravar (403, como convite de outro organizador) */
    public array $recusar = [];
    /** @var array<string, array<string, mixed>> gravado desde a última listagem */
    private array $eco = [];
    private int $rodada = 0;

    public function api(): GoogleAgendaApi
    {
        return new GoogleAgendaApi(new MockHttpClient($this->responder(...)), 'cliente-teste', 'segredo-teste');
    }

    /** @param array<string, mixed> $opcoes */
    public function responder(string $metodo, string $url, array $opcoes): MockResponse
    {
        $caminho = (string) parse_url($url, \PHP_URL_PATH);
        $this->pedidos[] = $metodo . ' ' . $caminho;

        return match (true) {
            str_ends_with($caminho, '/token') => $this->token((string) ($opcoes['body'] ?? '')),
            str_ends_with($caminho, '/revoke') => new MockResponse(''),
            str_ends_with($caminho, '/calendars/primary') => $this->json(['error' => ['code' => 403]], 403),
            str_ends_with($caminho, '/events') && 'GET' === $metodo => $this->listar($url),
            str_ends_with($caminho, '/events') && 'POST' === $metodo => $this->inserir($opcoes),
            default => $this->umEvento($metodo, rawurldecode(basename($caminho)), $opcoes),
        };
    }

    private function token(string $corpo): MockResponse
    {
        parse_str($corpo, $campos);
        if ($this->recusarAutorizacao) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        if ('authorization_code' === ($campos['grant_type'] ?? null)) {
            return $this->json(['access_token' => 'acesso', 'refresh_token' => 'REFRESH-SECRETO', 'scope' => GoogleAgendaApi::ESCOPO]);
        }

        return $this->json(['access_token' => 'acesso', 'expires_in' => 3600]);
    }

    private function listar(string $url): MockResponse
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
        // calendars.get exige escopo maior que calendar.events: o falso recusa, como o Google faria.
        if ($this->syncTokenVencido && isset($q['syncToken'])) {
            $this->syncTokenVencido = false;

            return $this->json(['error' => ['code' => 410]], 410);
        }
        $gravados = isset($q['syncToken']) ? array_values($this->eco) : array_values($this->eventos);
        if ('1' === (string) ($q['maxResults'] ?? '')) {
            return $this->json(['summary' => 'pessoa@gmail.com', 'timeZone' => 'America/Sao_Paulo', 'items' => []]);
        }
        [$itens, $this->paraTrazer, $this->eco] = [[...$gravados, ...$this->paraTrazer], [], []];

        return $this->json(['summary' => 'pessoa@gmail.com', 'timeZone' => 'America/Sao_Paulo', 'items' => $itens, 'nextSyncToken' => 'st-' . ++$this->rodada]);
    }

    /** @param array<string, mixed> $opcoes */
    private function inserir(array $opcoes): MockResponse
    {
        $corpo = json_decode((string) ($opcoes['body'] ?? '{}'), true);
        $this->corpos[] = $corpo;
        if (in_array($corpo['id'], $this->recusar, true)) {
            return $this->json(['error' => ['code' => 403, 'errors' => [['reason' => 'forbiddenForNonOrganizer']]]], 403);
        }
        if (isset($this->eventos[$corpo['id']])) {
            return $this->json(['error' => ['code' => 409]], 409);
        }
        $this->guardar($corpo['id'], $corpo);

        return $this->json($corpo);
    }

    /** @param array<string, mixed> $opcoes */
    private function umEvento(string $metodo, string $id, array $opcoes): MockResponse
    {
        if ('DELETE' === $metodo) {
            $existia = isset($this->eventos[$id]);
            unset($this->eventos[$id]);
            $this->eco[$id] = ['id' => $id, 'status' => 'cancelled'];

            return new MockResponse('', ['http_code' => $existia ? 204 : 410]);
        }
        if (in_array($id, $this->recusar, true)) {
            return $this->json(['error' => ['code' => 403, 'errors' => [['reason' => 'forbiddenForNonOrganizer']]]], 403);
        }
        if (!isset($this->eventos[$id])) {
            return $this->json(['error' => ['code' => 404]], 404);
        }
        $corpo = json_decode((string) ($opcoes['body'] ?? '{}'), true);
        $this->corpos[] = $corpo;
        $this->guardar($id, ['id' => $id] + $corpo);

        return $this->json($this->eventos[$id]);
    }

    /**
     * Grava como o Google devolve: horas com deslocamento de Brasília.
     *
     * @param array<string, mixed> $evento
     */
    private function guardar(string $id, array $evento): void
    {
        foreach (['start', 'end'] as $ponta) {
            if (isset($evento[$ponta]['dateTime']) && 19 === strlen($evento[$ponta]['dateTime'])) {
                $evento[$ponta]['dateTime'] .= '-03:00';
            }
        }
        $this->eventos[$id] = $evento;
        $this->eco[$id] = $evento;
    }

    /** @param array<string, mixed> $dados */
    private function json(array $dados, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($dados), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
