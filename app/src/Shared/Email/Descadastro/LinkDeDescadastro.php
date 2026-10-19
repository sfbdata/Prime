<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O link "Não quero mais receber estes avisos": gera e lê a URL assinada da rota pública
 * `email_descadastro` (UriSigner do Symfony, HMAC com o `kernel.secret`).
 *
 * O pedido (escritório ou null = plataforma, endereço, assunto) vai num único parâmetro `d`, em JSON base64url: não deixa
 * o endereço legível na URL (logs de acesso, histórico) e mantém a assinatura sobre um campo só.
 * Sem expiração: o descadastro tem de funcionar no e-mail antigo. Qualquer byte trocado no caminho
 * ou na consulta invalida a assinatura e `ler()` devolve null (a rota responde 404).
 *
 * A assinatura cobre o CAMINHO com a consulta, não o esquema e o host: o e-mail sai do CLI com o
 * `DEFAULT_URI` (https), e atrás do proxy sem `SYMFONY_TRUSTED_PROXIES` confirmado a requisição pode
 * chegar como http; assinar a URL inteira faria todo link válido responder 404.
 */
final class LinkDeDescadastro
{
    public const ROTA = 'email_descadastro';

    public function __construct(
        private readonly UriSigner $assinador,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function gerar(PedidoDeDescadastro $pedido): string
    {
        $parametros = ['d' => self::base64Url(json_encode(['t' => $pedido->tenantId, 'e' => $pedido->email, 'a' => $pedido->assunto], JSON_THROW_ON_ERROR))];
        $absoluto = $this->urls->generate(self::ROTA, $parametros, UrlGeneratorInterface::ABSOLUTE_URL);
        $caminho = $this->urls->generate(self::ROTA, $parametros, UrlGeneratorInterface::ABSOLUTE_PATH);

        return substr($absoluto, 0, \strlen($absoluto) - \strlen($caminho)) . $this->assinador->sign($caminho);
    }

    /** O pedido do link, ou null se a assinatura não confere ou a carga está malformada. */
    public function ler(Request $request): ?PedidoDeDescadastro
    {
        if (!$this->assinador->check($request->getRequestUri())) {
            return null;
        }

        $json = base64_decode(strtr((string) $request->query->get('d', ''), '-_', '+/'), true);
        $carga = $json === false ? null : json_decode($json, true);
        if (!\is_array($carga) || !\array_key_exists('t', $carga) || !(\is_int($carga['t']) || $carga['t'] === null)
            || !\is_string($carga['e'] ?? null) || !\is_string($carga['a'] ?? null)) {
            return null;
        }

        return new PedidoDeDescadastro($carga['t'], $carga['e'], $carga['a']);
    }

    private static function base64Url(string $texto): string
    {
        return rtrim(strtr(base64_encode($texto), '+/', '-_'), '=');
    }
}
