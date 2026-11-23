<?php

declare(strict_types=1);

namespace App\Tests\Produto\Unit;

use App\Produto\DTO\ClassificacaoDaRota;
use App\Produto\Enum\Produto;
use App\Produto\Service\CatalogoDeProdutos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Catálogo dos produtos e classificação das rotas (spec multiproduto-contratacao-e-voto, §3).
 */
#[CoversClass(CatalogoDeProdutos::class)]
#[CoversClass(ClassificacaoDaRota::class)]
#[CoversClass(Produto::class)]
final class CatalogoDeProdutosTest extends TestCase
{
    #[TestDox('Todo produto do enum tem linha no catálogo, e o catálogo não tem produto a mais')]
    public function testCatalogoCobreOEnum(): void
    {
        $doEnum = array_map(static fn (Produto $p): string => $p->value, Produto::cases());

        self::assertSame($doEnum, array_keys(CatalogoDeProdutos::PRODUTOS));
    }

    #[TestDox('Ferramentas: 8, sem o Completo')]
    public function testFerramentasSemCompleto(): void
    {
        $ferramentas = Produto::ferramentas();

        self::assertCount(8, $ferramentas);
        self::assertNotContains(Produto::Completo, $ferramentas);
    }

    #[TestDox('O dono liga toda ferramenta (Voto, Ponto e I.A. inclusive), e nunca o Completo')]
    public function testLigavelPeloDono(): void
    {
        self::assertFalse(CatalogoDeProdutos::autoativavel(Produto::Completo));
        foreach (Produto::ferramentas() as $produto) {
            self::assertTrue(CatalogoDeProdutos::autoativavel($produto), $produto->value);
        }
    }

    #[TestDox('Título e rota inicial saem do catálogo')]
    public function testTituloERotaInicial(): void
    {
        self::assertSame('I.A.', CatalogoDeProdutos::titulo(Produto::Ia));
        self::assertSame('voto_index', CatalogoDeProdutos::rotaInicial(Produto::Voto));
        self::assertSame('expediente_index', CatalogoDeProdutos::rotaInicial(Produto::Completo));
    }

    /**
     * @return iterable<string, array{string, list<Produto>}>
     */
    public static function rotasDeProduto(): iterable
    {
        yield 'Voto' => ['voto_index', [Produto::Voto]];
        yield 'assinatura logada' => ['assinatura_eletronica_index', [Produto::Assinatura]];
        yield 'painel da I.A.' => ['inteligencia_agentes_painel', [Produto::Ia]];
        yield 'chat da I.A.' => ['chat_ia_pagina', [Produto::Ia]];
        yield 'Central da I.A. vence o núcleo da Central' => ['central_ia_pasta', [Produto::Ia]];
        yield 'alerta do Dashboard não é a I.A.' => ['dashboard_inteligencia_alerta_marcar', [Produto::Expediente]];
        yield 'inteligência cadastral do cliente não é a I.A.' => ['cliente_inteligencia_cadastral', [Produto::Expediente, Produto::Cobrancas]];
        yield 'excluir sede vence o app_tenant_ do núcleo' => ['app_tenant_sede_delete', [Produto::Ponto]];
        yield 'lista de sedes' => ['app_tenant_sedes', [Produto::Ponto]];
        yield 'clientes abrem com Expediente ou Cobranças' => ['cliente_show', [Produto::Expediente, Produto::Cobrancas]];
        yield 'Judicializar depende das pastas' => ['cobranca_caso_judicializar', [Produto::Expediente]];
        yield 'cancelar a judicialização fica nas Cobranças' => ['cobranca_caso_cancelar_judicializacao', [Produto::Cobrancas]];
        yield 'API logada do convite do Trabalho Externo' => ['trabalho_externo_convite_reenviar', [Produto::Expediente]];
        yield 'tela do Conector (admin)' => ['conector_admin_alterar', [Produto::Expediente]];
        yield 'tela do Conector (perfil)' => ['conector_perfil_guia', [Produto::Expediente]];
        yield 'Ponto' => ['ponto_estado_do_dia', [Produto::Ponto]];
    }

    /**
     * @param list<Produto> $esperados
     */
    #[DataProvider('rotasDeProduto')]
    #[TestDox('Rota de produto: $rota')]
    public function testRotaDeProduto(string $rota, array $esperados): void
    {
        $c = CatalogoDeProdutos::classificar($rota);

        self::assertTrue($c->exigeProduto());
        self::assertTrue($c->explicita);
        self::assertSame($esperados, $c->produtos);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rotasSemProduto(): iterable
    {
        yield 'cédula do Voto' => ['voto_assembleia_cedula', ClassificacaoDaRota::PUBLICA];
        yield 'escolher no portal da assembleia' => ['voto_assembleia_portal_escolher', ClassificacaoDaRota::PUBLICA];
        yield 'saúde do container do Voto' => ['voto_publico_saude', ClassificacaoDaRota::PUBLICA];
        yield 'link do signatário' => ['assinatura_eletronica_publica_pdf', ClassificacaoDaRota::PUBLICA];
        yield 'auto-cadastro vence o auth_ do núcleo' => ['auth_cadastro_criar', ClassificacaoDaRota::PUBLICA];
        yield 'convite do Trabalho Externo por token' => ['trabalho_externo_convite', ClassificacaoDaRota::PUBLICA];
        yield 'link do Push compartilhado' => ['push_compartilhado', ClassificacaoDaRota::PUBLICA];
        yield 'login' => ['app_login', ClassificacaoDaRota::NUCLEO];
        yield 'Central do escritório' => ['central_index', ClassificacaoDaRota::NUCLEO];
        yield 'equipe' => ['app_tenant_users', ClassificacaoDaRota::NUCLEO];
        yield 'servidor MCP do Conector' => ['conector_mcp', ClassificacaoDaRota::NUCLEO];
        yield 'painel do Master' => ['master_painel', ClassificacaoDaRota::PLATAFORMA];
        yield 'falhas do agendador' => ['agendador_falhas_dispensar', ClassificacaoDaRota::PLATAFORMA];
    }

    #[DataProvider('rotasSemProduto')]
    #[TestDox('Rota sem produto: $rota é $tipo')]
    public function testRotaSemProduto(string $rota, string $tipo): void
    {
        $c = CatalogoDeProdutos::classificar($rota);

        self::assertSame($tipo, $c->tipo);
        self::assertFalse($c->exigeProduto());
        self::assertSame([], $c->produtos);
    }

    #[TestDox('Rota sem mapa cai no Expediente e não é explícita (nasce fechada)')]
    public function testRotaSemMapa(): void
    {
        $c = CatalogoDeProdutos::classificar('rota_que_ninguem_mapeou');

        self::assertTrue($c->exigeProduto());
        self::assertFalse($c->explicita);
        self::assertSame([Produto::Expediente], $c->produtos);
    }
}
