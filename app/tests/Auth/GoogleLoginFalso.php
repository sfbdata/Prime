<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\Infra\GoogleLoginApi;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * O Google do "Entrar com o Google" nos testes: responde à troca do código com um `id_token` montado com o que o teste
 * mandar (sub, e-mail, confirmação, cliente, emissor, validade, nonce). Sem rede.
 */
final class GoogleLoginFalso
{
    public string $sub = 'sub-google-1';
    public string $email = 'pessoa@gmail.com';
    public string $nome = 'Pessoa Google';
    public bool $emailConfirmado = true;
    /** `hd` da conta Workspace; vazio = conta comum (sem `hd` no `id_token`). */
    public string $dominio = '';
    public string $audiencia = 'cliente-login';
    public string $emissor = 'https://accounts.google.com';
    public int $validade = 3600;
    /** O nonce que vai no `id_token`; nulo = o da ida (o teste copia da URL de autorização). */
    public ?string $nonce = null;
    public bool $recusarCodigo = false;
    /** @var list<string> */
    public array $pedidos = [];

    public function api(): GoogleLoginApi
    {
        return new GoogleLoginApi(new MockHttpClient($this->responder(...)), 'cliente-login', 'segredo-login');
    }

    /** @param array<string, mixed> $opcoes */
    private function responder(string $metodo, string $url, array $opcoes): MockResponse
    {
        $this->pedidos[] = $metodo . ' ' . (string) parse_url($url, \PHP_URL_PATH);
        if ($this->recusarCodigo) {
            return new MockResponse((string) json_encode(['error' => 'invalid_grant']), ['http_code' => 400]);
        }
        $carga = [
            'sub' => $this->sub, 'email' => $this->email, 'email_verified' => $this->emailConfirmado, 'name' => $this->nome,
            'aud' => $this->audiencia, 'iss' => $this->emissor, 'exp' => time() + $this->validade, 'nonce' => $this->nonce,
        ] + ('' !== $this->dominio ? ['hd' => $this->dominio] : []);
        $idToken = 'cab.' . rtrim(strtr(base64_encode((string) json_encode($carga)), '+/', '-_'), '=') . '.ass';

        return new MockResponse((string) json_encode(['access_token' => 'acesso', 'id_token' => $idToken, 'scope' => GoogleLoginApi::ESCOPOS]), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
