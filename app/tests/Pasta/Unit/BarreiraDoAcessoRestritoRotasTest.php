<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\EventListener\BarreiraDoAcessoRestritoListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Spec acesso-restrito-e-compartilhar §5.1 item 2: quais rotas o restrito alcança. */
#[CoversClass(BarreiraDoAcessoRestritoListener::class)]
final class BarreiraDoAcessoRestritoRotasTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function liberadas(): iterable
    {
        foreach ([
            'expediente_index', 'expediente_busca_rapida', 'expediente_exportar_csv', 'pasta_show', 'pasta_new',
            'processo_show', 'cliente_index', 'app_profile', 'app_profile_senha', 'contribua_index', 'app_logout',
            'tenant_selecionar', 'notificacao_count', 'termo_aceite',
        ] as $rota) {
            yield $rota => [$rota];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function fechadas(): iterable
    {
        foreach ([
            'dashboard_index', 'central_index', 'agenda_index', 'chat_index', 'app_tenant_role_index', 'app_tenant_users',
            'push_processual_index', 'tarefa_index', 'cobranca_index', 'voto_index', 'service_desk_index', 'audit_log_index',
            'expediente_lixeira', 'expediente_acervo_geral', 'expediente_relatorio_processos', 'expediente_pastas_paradas',
            'expediente_marcador_criar', 'auth_gerenciar_convites', 'conector_mcp', 'rota_que_ainda_nao_existe',
        ] as $rota) {
            yield $rota => [$rota];
        }
    }

    #[DataProvider('liberadas')]
    #[TestDox('restrito alcança $rota')]
    public function testLiberada(string $rota): void
    {
        self::assertTrue(BarreiraDoAcessoRestritoListener::liberada($rota));
    }

    #[DataProvider('fechadas')]
    #[TestDox('restrito não alcança $rota (lista de liberação: rota nova fica fechada)')]
    public function testFechada(string $rota): void
    {
        self::assertFalse(BarreiraDoAcessoRestritoListener::liberada($rota));
    }
}
