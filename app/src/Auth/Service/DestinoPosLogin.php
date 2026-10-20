<?php

declare(strict_types=1);

namespace App\Auth\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Valida para onde o usuário pode ir depois do login (spec login-desenho-10, comportamento 2).
 *
 * O destino nasce de fonte que o usuário controla (o `target_path` da sessão vem da URL que ele
 * abriu; o `?para=` do `/login/entrando` vem da barra de endereço), então tudo aqui é defesa
 * contra open redirect: só sai daqui um caminho relativo deste mesmo site, ou `null`.
 *
 * Aceita:
 * - caminho relativo que começa com `/`, sem `//` nem `/\` na frente, sem `\` em lugar nenhum,
 *   sem espaço nem caractere de controle (nem cru, nem codificado com `%`);
 * - URL absoluta `http(s)` do próprio host (e porta) da requisição, reduzida a caminho e query.
 *
 * Recusa também as telas de entrada (`/login`, `/logout`, `/senha`, `/cadastro`) para não criar laço, e o que
 * não é página: endpoint de dados (`/api/`, `.../count`, o polling do sino), arquivo estático e as rotas
 * internas do Symfony (`/_`). Em produção, o `fetch('/notificacoes/count')` do sino com a sessão vencida
 * virou destino e o dono entrou e caiu no JSON `{"count":390}`. Na dúvida, `null` (a página inicial).
 */
final class DestinoPosLogin
{
    private const TAMANHO_MAXIMO = 2048;

    private const PREFIXOS_PROIBIDOS = ['/login', '/logout', '/senha', '/cadastro', '/api'];

    /** Caminhos que devolvem dado, não página: o último trecho `count` e arquivos com extensão de recurso. */
    private const NAO_E_PAGINA = '#^/_|(/count$|\.(json|js|css|map|xml|txt|png|jpe?g|gif|svg|ico|webp|avif|pdf|woff2?)$)#i';

    public function validar(?string $destino, Request $request): ?string
    {
        if ($destino === null || $destino === '' || strlen($destino) > self::TAMANHO_MAXIMO) {
            return null;
        }

        $caminho = str_starts_with($destino, '/') && !str_starts_with($destino, '//')
            ? $destino
            : $this->reduzirUrlDoProprioHost($destino, $request);

        if ($caminho === null) {
            return null;
        }

        return $this->caminhoSeguro($caminho) ? $caminho : null;
    }

    /**
     * URL absoluta só vale se for deste host e porta; aí sobra o caminho e a query (o fragmento cai).
     */
    private function reduzirUrlDoProprioHost(string $url, Request $request): ?string
    {
        if (preg_match('#^https?://#i', $url) !== 1 || $this->temCaractereProibido($url)) {
            return null;
        }

        $partes = parse_url($url);
        if ($partes === false || !isset($partes['host']) || isset($partes['user']) || isset($partes['pass'])) {
            return null;
        }

        if ($this->autoridade($partes) !== strtolower($request->getHttpHost())) {
            return null;
        }

        $caminho = $partes['path'] ?? '/';

        return isset($partes['query']) ? $caminho . '?' . $partes['query'] : $caminho;
    }

    /**
     * Host e porta no mesmo formato de `Request::getHttpHost()`: a porta só aparece quando
     * não é a padrão do esquema.
     *
     * @param array<string, int|string> $partes
     */
    private function autoridade(array $partes): string
    {
        $esquema = strtolower((string) ($partes['scheme'] ?? 'http'));
        $host = strtolower((string) ($partes['host'] ?? ''));
        $porta = isset($partes['port']) ? (int) $partes['port'] : null;
        $padrao = $esquema === 'https' ? 443 : 80;

        return $porta === null || $porta === $padrao ? $host : $host . ':' . $porta;
    }

    private function caminhoSeguro(string $caminho): bool
    {
        if ($this->temCaractereProibido($caminho)) {
            return false;
        }

        // Confere também o decodificado: `/%2F%2Fevil.com`, `/%5Cevil.com` ou `/%09/evil.com`
        // não podem virar `//evil.com` em quem decodificar no meio do caminho.
        $decodificado = rawurldecode($caminho);
        foreach ([$caminho, $decodificado] as $forma) {
            if (!str_starts_with($forma, '/') || str_starts_with($forma, '//') || str_starts_with($forma, '/\\')) {
                return false;
            }
        }

        return preg_match('/[\x00-\x1F\x7F]/', $decodificado) !== 1
            && !$this->apontaParaEntrada($decodificado)
            && !$this->naoEPagina($decodificado);
    }

    /** Barra invertida, espaço e caractere de controle crus nunca entram. */
    private function temCaractereProibido(string $texto): bool
    {
        return str_contains($texto, '\\') || preg_match('/[\x00-\x20\x7F]/', $texto) === 1;
    }

    private function naoEPagina(string $caminho): bool
    {
        // Sem a barra final: `/notificacoes/count/` também é o contador.
        return preg_match(self::NAO_E_PAGINA, rtrim(strtolower((string) strtok($caminho, '?#')), '/')) === 1;
    }

    private function apontaParaEntrada(string $caminho): bool
    {
        $soCaminho = strtolower((string) strtok($caminho, '?#'));

        foreach (self::PREFIXOS_PROIBIDOS as $prefixo) {
            if ($soCaminho === $prefixo || str_starts_with($soCaminho, $prefixo . '/')) {
                return true;
            }
        }

        return false;
    }
}
