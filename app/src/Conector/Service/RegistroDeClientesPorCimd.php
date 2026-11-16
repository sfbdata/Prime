<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\DTO\DocumentoCimd;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Repository\ClienteDoConectorRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Registro do cliente por CIMD, o único caminho do piloto (`docs/specs/mcp-remoto-oauth.md`, 3.4, 9.5 e 9.6): o
 * `client_id` é a URL do documento de metadados do app.
 *
 * Ordem das travas, da mais barata para a mais cara, todas antes de qualquer busca:
 * 1. `client_id` em forma de URL CIMD: `https://`, com host e caminho, sem fragmento, query, porta, usuário ou senha,
 *    sem segmento `.` ou `..`, só ASCII visível, até 2000 caracteres (`client_id_invalido`);
 * 2. host na lista em configuração ({@see HostsDeClientePermitidos}, `host_nao_permitido`). Vale também para o cliente já
 *    gravado: tirar o host da configuração corta o app na hora;
 * 3. `redirect_uri` na lista exata do servidor ({@see ListaDeRedirects}, `redirect_nao_permitido`);
 * 4. cliente gravado e desativado recusa sem buscar (`cliente_inativo`);
 * 5. cache: cliente gravado com metadados no prazo não busca; vencido ou ausente busca ({@see BuscaDoDocumentoCimd}) e
 *    grava. O cliente novo entra por `INSERT ... ON CONFLICT DO NOTHING` e é relido: na corrida de dois pedidos do mesmo
 *    app, o segundo lê o que o primeiro gravou, sem exceção e sem fechar o EntityManager;
 * 6. `redirect_uri` no documento do cliente (byte a byte, menos a porta do loopback).
 *
 * Toda recusa vai para o canal de log `mcp` só com o motivo e o host do `client_id`: nunca a URL inteira, o redirect
 * nem o documento.
 */
#[AsAlias(RegistroDeClientes::class)]
final class RegistroDeClientesPorCimd implements RegistroDeClientes
{
    public function __construct(
        private readonly ClienteDoConectorRepository $clientes,
        private readonly ClockInterface $relogio,
        private readonly HostsDeClientePermitidos $hostsPermitidos,
        private readonly ListaDeRedirects $redirects,
        private readonly BuscaDoDocumentoCimd $busca,
        private readonly LoggerInterface $mcpLogger,
    ) {
    }

    public function resolver(string $clientId, string $redirectUri): ClienteDoConector
    {
        try {
            return $this->resolverOuRecusar($clientId, $redirectUri);
        } catch (ClienteRecusado $recusa) {
            $this->mcpLogger->warning('Cliente do conector recusado.', ['motivo' => $recusa->motivo, 'host' => $recusa->host]);

            throw $recusa;
        }
    }

    private function resolverOuRecusar(string $clientId, string $redirectUri): ClienteDoConector
    {
        $host = $this->exigirClientIdValido($clientId);
        if (!$this->redirects->permite($redirectUri)) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, $host);
        }

        $cliente = $this->clientes->buscarPorIdentificador($clientId);
        if ($cliente instanceof ClienteDoConector && !$cliente->isAtivo()) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_CLIENTE_INATIVO, $host);
        }
        if (!$cliente instanceof ClienteDoConector || !$this->dentroDoPrazo($cliente)) {
            $cliente = $this->buscarEGravar($clientId, $host, $cliente);
        }

        if (!$this->redirects->constaDoDocumento($cliente->getRedirectUris(), $redirectUri)) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, $host);
        }

        return $cliente;
    }

    /** Devolve o host (minúsculo) do `client_id` aceito. */
    private function exigirClientIdValido(string $clientId): string
    {
        $partes = $this->partesDaUrlCimd($clientId);
        if ($partes === null) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_CLIENT_ID_INVALIDO, ClienteRecusado::hostDe($clientId));
        }

        $host = strtolower($partes['host']);
        if (!$this->hostsPermitidos->permite($host)) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_HOST_NAO_PERMITIDO, $host);
        }

        return $host;
    }

    /**
     * As partes da URL quando ela tem a forma de um `client_id` CIMD; nulo quando não tem. A query, que o rascunho do CIMD
     * só desaconselha, é recusada: nenhum app do piloto usa, e cada query diferente seria um cliente novo a buscar.
     *
     * @return array{host: string, path: string}|null
     */
    private function partesDaUrlCimd(string $url): ?array
    {
        if (\strlen($url) > ClienteDoConector::IDENTIFICADOR_MAXIMO
            || preg_match('/^[\x21-\x7E]+\z/', $url) !== 1
            || !str_starts_with($url, 'https://')
            || strpbrk($url, '#?\\') !== false) {
            return null;
        }

        $partes = parse_url($url);
        if (!\is_array($partes) || ($partes['scheme'] ?? null) !== 'https' || ($partes['host'] ?? '') === ''
            || isset($partes['user']) || isset($partes['pass']) || isset($partes['port'])) {
            return null;
        }

        $caminho = $partes['path'] ?? '';
        foreach (explode('/', $caminho) as $segmento) {
            if (\in_array(rawurldecode($segmento), ['.', '..'], true)) {
                return null;
            }
        }

        return $caminho === '' || $caminho === '/' ? null : ['host' => $partes['host'], 'path' => $caminho];
    }

    private function dentroDoPrazo(ClienteDoConector $cliente): bool
    {
        $validoAte = $cliente->getMetadadosValidosAte();

        return $validoAte !== null && $validoAte > $this->relogio->now();
    }

    /** Busca o documento e grava: atualiza o cliente vencido, ou insere o novo e relê (corrida de unique). */
    private function buscarEGravar(string $clientId, string $host, ?ClienteDoConector $cliente): ClienteDoConector
    {
        $documento = $this->busca->buscar($clientId, $host);
        $agora = $this->relogio->now();

        if ($cliente instanceof ClienteDoConector) {
            $cliente->renovarMetadados($documento->clientName, $documento->redirectUris, $agora, $documento->validoAte);
            $this->clientes->salvar($cliente, true);

            return $cliente;
        }

        $this->clientes->inserirSeAusente($this->clienteNovo($documento, $agora));
        $gravado = $this->clientes->buscarPorIdentificador($clientId);
        if (!$gravado instanceof ClienteDoConector) {
            throw new \LogicException('O cliente do conector não foi gravado nem achado depois da gravação.');
        }
        if (!$gravado->isAtivo()) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_CLIENTE_INATIVO, $host);
        }

        return $gravado;
    }

    private function clienteNovo(DocumentoCimd $documento, \DateTimeImmutable $agora): ClienteDoConector
    {
        $cliente = new ClienteDoConector($documento->clientId, TipoDeClienteDoConector::Cimd, $documento->clientName, $documento->redirectUris, $agora);
        $cliente->renovarMetadados($documento->clientName, $documento->redirectUris, $agora, $documento->validoAte);

        return $cliente;
    }
}
