<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Log\MascaraDoTokenNoLog;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O token do link fora do log (contrato da etapa 2, seção 3). O registro de teste é o que o RouterListener do Symfony
 * grava a cada requisição ("Matched route"), que o `fingers_crossed` de produção despeja num erro.
 */
#[CoversClass(MascaraDoTokenNoLog::class)]
final class MascaraDoTokenNoLogTest extends TestCase
{
    private const TOKEN = '33c46ce276c87f851ab315cbf613c768e157aa18c495b94eed10e7e527d8cdc1';

    #[TestDox('o "Matched route" do RouterListener: request_uri com /assinar/**** e route_parameters.token como ****')]
    public function testRegistroDoRouterListener(): void
    {
        $limpo = (new MascaraDoTokenNoLog())(self::registro('Matched route "{route}".', [
            'route' => 'assinatura_eletronica_publica_codigo',
            'route_parameters' => [
                '_route' => 'assinatura_eletronica_publica_codigo',
                '_controller' => 'App\AssinaturaEletronica\Controller\AssinaturaPublicaController::codigo',
                'token' => self::TOKEN,
            ],
            'request_uri' => 'https://app.bluejus.com.br/assinar/' . self::TOKEN . '/codigo',
            'method' => 'POST',
        ]));

        self::assertSame('https://app.bluejus.com.br/assinar/****/codigo', $limpo->context['request_uri']);
        self::assertSame('****', $limpo->context['route_parameters']['token']);
        self::assertSame('assinatura_eletronica_publica_codigo', $limpo->context['route_parameters']['_route']);
        self::assertSame('POST', $limpo->context['method']);
        self::assertStringNotContainsString(self::TOKEN, json_encode($limpo->toArray(), \JSON_THROW_ON_ERROR));
    }

    #[TestDox('a rota de reserva: o token dentro de route_parameters.resto também sai')]
    public function testRotaDeReserva(): void
    {
        $limpo = (new MascaraDoTokenNoLog())(self::registro('Matched route "{route}".', [
            'route_parameters' => ['_route' => 'assinatura_eletronica_publica_invalido', 'resto' => strtoupper(self::TOKEN) . '/outra/coisa'],
            'request_uri' => 'http://localhost/assinar/' . strtoupper(self::TOKEN) . '/outra/coisa',
        ]));

        self::assertSame('****/outra/coisa', $limpo->context['route_parameters']['resto']);
        self::assertSame('http://localhost/assinar/****/outra/coisa', $limpo->context['request_uri']);
    }

    #[TestDox('mensagem e extra (em qualquer profundidade) também saem limpos')]
    public function testMensagemEExtra(): void
    {
        $registro = self::registro('No route found for "PUT https://app.bluejus.com.br/assinar/' . self::TOKEN . '"', [], [
            'url' => '/assinar/' . self::TOKEN,
            'aninhado' => ['de novo' => ['/assinar/' . self::TOKEN . '/logo']],
        ]);

        $limpo = (new MascaraDoTokenNoLog())($registro);

        self::assertSame('No route found for "PUT https://app.bluejus.com.br/assinar/****"', $limpo->message);
        self::assertSame('/assinar/****', $limpo->extra['url']);
        self::assertSame('/assinar/****/logo', $limpo->extra['aninhado']['de novo'][0]);
    }

    #[TestDox('o que não é link nem parâmetro de rota fica como está (um sha256 qualquer, as rotas internas, outros tipos)')]
    public function testOResto(): void
    {
        $contexto = [
            'hash' => hash('sha256', 'conteudo'),
            'url' => '/assinatura/documentos/12/enviar',
            'token' => 'campo de outro contexto',
            'id' => 12,
            'ativo' => true,
            'nada' => null,
        ];

        $limpo = (new MascaraDoTokenNoLog())(self::registro('E-mail da assinatura não enviado.', $contexto));

        self::assertSame($contexto, $limpo->context);
        self::assertSame('E-mail da assinatura não enviado.', $limpo->message);
    }

    /**
     * @param array<mixed> $contexto
     * @param array<mixed> $extra
     */
    private static function registro(string $mensagem, array $contexto, array $extra = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable('2026-11-09 10:00:00'), 'request', Level::Info, $mensagem, $contexto, $extra);
    }
}
