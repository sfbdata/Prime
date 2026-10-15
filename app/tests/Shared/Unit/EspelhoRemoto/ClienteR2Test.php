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
        $s3 = ClienteR2::criar('https://conta.r2.cloudflarestorage.com', 'CHAVE', 'SEGREDO');

        $http = (new \ReflectionProperty(AbstractApi::class, 'httpClient'))->getValue($s3);
        $opcoes = (new \ReflectionProperty($http, 'defaultOptions'))->getValue($http);

        self::assertSame((float) ClienteR2::OCIOSIDADE_MAXIMA, (float) $opcoes['timeout']);
        self::assertSame((float) ClienteR2::DURACAO_MAXIMA_DA_REQUISICAO, (float) $opcoes['max_duration']);
    }
}
