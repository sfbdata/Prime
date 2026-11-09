<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\CatalogoDeRecursos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Catálogo fixo das telas e recursos (spec perfis-1.2-telas-e-recursos, R1, R3, R8 e §4).
 */
#[CoversClass(CatalogoDeRecursos::class)]
final class CatalogoDeRecursosTest extends TestCase
{
    #[TestDox('23 linhas, 22 chaves (Contribua e o atalho dividem a chave) e 2 essenciais')]
    public function testTamanhoDoCatalogo(): void
    {
        self::assertCount(23, CatalogoDeRecursos::LINHAS);
        self::assertCount(22, CatalogoDeRecursos::chaves());
        self::assertSame(2, count(array_filter(CatalogoDeRecursos::LINHAS, static fn (array $l): bool => $l['base'])));
        self::assertTrue(CatalogoDeRecursos::ehEssencial('lista'));
        self::assertTrue(CatalogoDeRecursos::ehEssencial('meuperfil'));
        self::assertFalse(CatalogoDeRecursos::ehEssencial('dashboard'));
        self::assertFalse(CatalogoDeRecursos::ehEssencial('ponto'), 'a tela do Ponto desliga (E7)');
        self::assertFalse(CatalogoDeRecursos::ehEssencial('ponto-geo'));
        self::assertSame(['contribua', 'contribua'], array_values(array_map(
            static fn (array $l): string => $l['chave'],
            array_filter(CatalogoDeRecursos::LINHAS, static fn (array $l): bool => str_starts_with($l['t'], 'Contribua')),
        )));
    }

    #[TestDox('grupos e ordem do bj-recursos.js, mais a Assinatura e o Ponto: 13 Telas, 7 Menu do usuário, 3 Funções')]
    public function testGruposEOrdem(): void
    {
        $porGrupo = array_count_values(array_column(CatalogoDeRecursos::LINHAS, 'g'));
        self::assertSame(['Telas' => 13, 'Menu do usuário' => 7, 'Funções' => 3], $porGrupo);
        self::assertSame('dashboard', CatalogoDeRecursos::LINHAS[0]['chave']);
        self::assertSame('assinatura', CatalogoDeRecursos::LINHAS[12]['chave']);
        self::assertSame('Assinatura de documentos', CatalogoDeRecursos::titulo('assinatura'));
        self::assertSame('assistente', CatalogoDeRecursos::LINHAS[21]['chave']);
        self::assertSame('ponto', CatalogoDeRecursos::LINHAS[8]['chave'], 'Ponto entra nas Telas, antes do Voto');
        self::assertSame('Ponto Eletrônico', CatalogoDeRecursos::titulo('ponto'));
        self::assertTrue(CatalogoDeRecursos::LINHAS[8]['tela']);
        $geo = CatalogoDeRecursos::LINHAS[22];
        self::assertSame(['Funções', 'ponto-geo', 'Ponto: geolocalização', 'Exige que o registro seja feito dentro do raio de uma sede.', false], [$geo['g'], $geo['chave'], $geo['t'], $geo['d'], $geo['tela']]);
        self::assertSame('Contribua', CatalogoDeRecursos::titulo('contribua'), 'título da primeira linha da chave');
        self::assertSame('Voto', CatalogoDeRecursos::titulo('voto'), 'Voto entra nas Telas, depois do Contribua (bj-recursos.js do desenho 18)');
        self::assertNull(CatalogoDeRecursos::titulo('inexistente'));
        self::assertFalse(CatalogoDeRecursos::existe('inexistente'));
    }

    #[DataProvider('rotas')]
    #[TestDox('mapa de rotas da §4: $rota => $chave')]
    public function testChaveDaRota(string $rota, ?string $chave): void
    {
        self::assertSame($chave, CatalogoDeRecursos::chaveDaRota($rota));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function rotas(): iterable
    {
        yield 'dashboard' => ['dashboard_index', 'dashboard'];
        yield 'assinatura' => ['assinatura_eletronica_index', 'assinatura'];
        yield 'assinatura (JSON)' => ['assinatura_eletronica_documentos', 'assinatura'];
        yield 'pasta' => ['pasta_show', 'expedientes'];
        yield 'metas' => ['tarefa_minhas', 'metas'];
        yield 'trabalho externo' => ['trabalho_externo_index', 'trabalho'];
        yield 'convite público do trabalho externo fica aberto' => ['trabalho_externo_convite', null];
        yield 'aceite do convite fica aberto' => ['trabalho_externo_convite_aceitar', null];
        yield 'criação de conta pelo convite fica aberta' => ['trabalho_externo_convite_criar_conta', null];
        yield 'reenviar convite (API logada) segue a chave' => ['trabalho_externo_convite_reenviar', 'trabalho'];
        yield 'revogar convite (API logada) segue a chave' => ['trabalho_externo_convite_revogar', 'trabalho'];
        yield 'peticionar' => ['pasta_peticionar_upload', 'peticionar'];
        yield 'novidades' => ['novidades_index', 'novidades'];
        yield 'agentes da pasta' => ['inteligencia_agentes_painel', 'assistente'];
        yield 'página pública do push compartilhado' => ['push_compartilhado', 'pushpub'];
        yield 'código do push compartilhado' => ['push_compartilhado_codigo', 'pushpub'];
        yield 'gerar link externo do push na pasta' => ['pasta_push_links_gerar', 'pushpub'];
        yield 'renovar link externo do push segue a chave' => ['pasta_push_links_renovar', 'pushpub'];
        yield 'revogar link externo do push fica sempre aberto' => ['pasta_push_links_revogar', null];
        yield 'listar links do push segue a chave' => ['pasta_push_links_listar', 'pushpub'];
        yield 'recusar renovação do link segue a chave' => ['pasta_push_links_recusar', 'pushpub'];
        yield 'pedido de renovação na página pública segue a chave' => ['push_compartilhado_renovacao', 'pushpub'];
        yield 'página pública de vagas é vagaspub, não trabalho' => ['trabalho_externo_vagas_publicas', 'vagaspub'];
        yield 'link curto de vagas é vagaspub' => ['trabalho_externo_vagas_link', 'vagaspub'];
        yield 'compartilhar vagas é vagaspub' => ['trabalho_externo_vagas_compartilhar', 'vagaspub'];
        yield 'demais rotas de vagas seguem trabalho' => ['trabalho_externo_vagas', 'trabalho'];
        yield 'página do Contribua' => ['contribua_index', 'contribua'];
        yield 'tela do Voto' => ['voto_index', 'voto'];
        yield 'tela do Ponto' => ['ponto_index', 'ponto'];
        yield 'batida do Ponto cai com a tela' => ['ponto_batida', 'ponto'];
        yield 'chave Escritório do Ponto cai com a tela' => ['ponto_configuracao_funcao', 'ponto'];
        yield 'estado do dia do Ponto (atalho do avatar) fica aberto' => ['ponto_estado_do_dia', null];
        yield 'alerta de horário do Ponto fica aberto' => ['ponto_alerta_horario', null];
        yield 'pendência do dia do Ponto fica aberta' => ['ponto_pendencia_do_dia', null];
        yield 'pedido de espaço do Contribua' => ['contribua_espaco_pedido', 'contribua'];
        yield 'webhook do banco fica sempre aberto' => ['contribua_webhook_cora', null];
        yield 'lista de pastas (essencial) não é mapeada' => ['expediente_index', null];
        yield 'política de privacidade continua aberta' => ['legal_politica_privacidade', null];
        yield 'painel do Master' => ['master_painel', null];
        yield 'sem rota' => ['', null];
    }
}
