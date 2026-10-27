<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\EspelhoRemoto\R2\ClienteR2;
use AsyncAws\Core\AbstractApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O teto de duração do PUT só protege a posse do worker se o cliente HTTP montado por
 * `ClienteR2::criar()` (sem `$http`, como em produção) carregar de fato `timeout`/`max_duration`.
 * Comparar as constantes não basta: tirar as opções do `HttpClient::create()` deixaria esse teste
 * verde. Aqui se lê o cliente montado, sem rede.
 */
#[CoversClass(ClienteR2::class)]
final class ClienteR2Test extends TestCase
{
    #[TestDox('sem cliente HTTP injetado, o S3Client sai com ociosidade e duração máxima definidas')]
    public function testClientePadraoCarregaOTetoDeDuracao(): void
    {
        // Sem `timeout`, o HttpClient herda o `default_socket_timeout` do PHP — que no container é 60,
        // o mesmo valor da constante, e esconderia a falta da opção. Um ini diferente desmascara.
        $iniAnterior = ini_set('default_socket_timeout', '7');
        try {
            $s3 = ClienteR2::criar('https://conta.r2.cloudflarestorage.com', 'CHAVE', 'SEGREDO');
        } finally {
            ini_set('default_socket_timeout', (string) $iniAnterior);
        }

        $http = (new \ReflectionProperty(AbstractApi::class, 'httpClient'))->getValue($s3);
        $opcoes = (new \ReflectionProperty($http, 'defaultOptions'))->getValue($http);

        self::assertSame((float) ClienteR2::OCIOSIDADE_MAXIMA, (float) $opcoes['timeout']);
        self::assertSame((float) ClienteR2::DURACAO_MAXIMA_DA_REQUISICAO, (float) $opcoes['max_duration']);
    }

    #[TestDox('sem cliente HTTP injetado, o S3Client sai pedindo Accept-Encoding: identity')]
    public function testClientePadraoPedeRespostaSemCompressao(): void
    {
        $s3 = ClienteR2::criar('https://conta.r2.cloudflarestorage.com', 'CHAVE', 'SEGREDO');

        $http = (new \ReflectionProperty(AbstractApi::class, 'httpClient'))->getValue($s3);
        $opcoes = (new \ReflectionProperty($http, 'defaultOptions'))->getValue($http);

        self::assertContains('Accept-Encoding: identity', $opcoes['headers']);
    }
}
