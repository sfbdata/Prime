<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\ChatIa\Controller\ChatIaMensagensController;
use App\ChatIa\Service\ConsultaDePrazosNoChat;
use App\ChatIa\Service\FerramentasDaConsulta;
use App\ChatIa\UseCase\ConsultarPrazosEMetasUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ChamadaDeFerramenta;
use App\Inteligencia\DTO\FerramentaDeLinguagem;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A I.A do chat consulta os prazos e metas da pessoa pela HTTP, com o provedor falso (spec `chat-ia-prazos-e-metas`): o modelo
 * pede `consultar_prazos_e_metas`, o servidor roda a consulta da tela Minhas Metas e a segunda chamada leva o resultado.
 *
 * Prova: (a) só as metas da própria pessoa; (b) a do colega fica de fora; (c) a de pasta com cadeado também; (d) a de processo
 * em segredo de justiça (do tribunal ou manual) nunca vai, só a contagem; (e) a de outro escritório não aparece; (f) nada é
 * gravado nas metas, na auditoria nem nas propostas.
 */
#[CoversClass(ChatIaMensagensController::class)]
#[CoversClass(ConsultaDePrazosNoChat::class)]
#[CoversClass(ConsultarPrazosEMetasUseCase::class)]
final class ConsultaDePrazosPeloChatTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const PERMISSOES = ['modules.inteligencia.view', 'modules.tarefas.view'];

    /** @return array{0: KernelBrowser, 1: Tenant, 2: User, 3: User} */
    private function cenario(array $permissoesDaAna = self::PERMISSOES): array
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, $permissoesDaAna, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::PERMISSOES, 'bruno');

        return [$client, $tenant, $ana, $bruno];
    }

    /** Uma pasta com um processo vinculado (para o segredo de justiça ter onde morar). */
    private function pastaComProcesso(Tenant $tenant, string $cliente): array
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setNomeCliente($cliente);
        $processo = $this->criarProcesso($tenant, (string) random_int(1_000_000_000, 9_999_999_999) . '0000000000');
        $this->vincular($pasta, $processo);

        return [$pasta, $processo];
    }

    private function conversaComTitulo(KernelBrowser $client): int
    {
        $this->post($client, '/chat-ia/conversas', []);
        self::assertResponseStatusCodeSame(201);
        $id = (int) $this->json($client)['id'];
        // Com título manual não há a chamada curta do título: os pedidos do falso são só os da resposta.
        $client->request('PATCH', "/chat-ia/conversas/$id", [], [], self::CSRF, '{"titulo":"Prazos"}');

        return $id;
    }

    /** @param array<string, string> $entrada */
    private function programarConsulta(array $entrada, string $resposta = 'Hoje vence uma meta.'): void
    {
        $this->provedorFalso()->limpar();
        $this->provedorFalso()->transmitirComFerramenta(['Vou consultar.'], [new ChamadaDeFerramenta(FerramentasDaConsulta::PRAZOS_E_METAS, $entrada)], 200, 30);
        $this->provedorFalso()->transmitirTrechos([$resposta], 500, 40);
    }

    /** @return list<array{evento: string, dados: array<string, mixed>}> */
    private function perguntar(KernelBrowser $client, int $id, string $texto): array
    {
        $this->post($client, "/chat-ia/conversas/$id/mensagens", ['texto' => $texto]);
        self::assertResponseIsSuccessful();
        $eventos = [];
        foreach (array_filter(explode("\n\n", $client->getInternalResponse()->getContent())) as $bloco) {
            preg_match('/^event: (\w+)\ndata: (.*)$/s', trim($bloco), $m);
            $eventos[] = ['evento' => $m[1], 'dados' => json_decode($m[2], true, flags: \JSON_THROW_ON_ERROR)];
        }

        return $eventos;
    }

    /** @return list<string> */
    private static function nomes(PedidoDeLinguagem $pedido): array
    {
        return array_map(static fn (FerramentaDeLinguagem $f): string => $f->nome, $pedido->ferramentas);
    }

    private static function ultimaMensagem(PedidoDeLinguagem $pedido): string
    {
        return $pedido->mensagens[\count($pedido->mensagens) - 1]['conteudo'];
    }

    /** Retrato do que a consulta NÃO pode mudar: as metas (linha a linha), a auditoria e as propostas. */
    private function retrato(): array
    {
        $conexao = $this->em()->getConnection();

        return [
            'tarefas' => $conexao->fetchOne("SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t.id), '')) FROM tarefa t"),
            'acompanhamentos' => $this->contarNoBanco('SELECT COUNT(*) FROM tarefa_acompanhamento'),
            'auditoriaDasMetas' => $this->contarNoBanco("SELECT COUNT(*) FROM audit_log WHERE entity_class LIKE '%Tarefa%'"),
            'propostas' => $this->contarNoBanco('SELECT COUNT(*) FROM chat_ia_proposta'),
        ];
    }

    private function marcarSegredoManual(Processo $processo): void
    {
        (new \ReflectionProperty(Processo::class, 'segredoManual'))->setValue($processo, true);
        $this->em()->flush();
    }

    #[TestDox('"Tem prazo hoje?": só as metas da própria pessoa; colega, cadeado, segredo e outro escritório ficam de fora; nada é gravado')]
    public function testSoAsMetasDaPropriaPessoa(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        [$comum] = $this->pastaComProcesso($tenant, 'Cliente Visivel');
        $this->criarMeta($tenant, $comum, $ana, 'Meta da Ana para hoje', 'today');
        $this->criarMeta($tenant, $comum, $ana, 'Meta da Ana atrasada', '-2 days');
        $this->criarMeta($tenant, $comum, $bruno, 'Meta do colega Bruno', 'today');

        [$trancada] = $this->pastaComProcesso($tenant, 'Cliente Trancado');
        $this->criarMeta($tenant, $trancada, $ana, 'Meta da pasta trancada', 'today');
        $this->em()->persist(new CadeadoDaPasta($tenant, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));

        [$sigilosa, $processoSigiloso] = $this->pastaComProcesso($tenant, 'Cliente Sigiloso');
        $processoSigiloso->setNivelSigilo(1);
        $this->criarMeta($tenant, $sigilosa, $ana, 'Meta em segredo do tribunal', 'today');
        [$manual, $processoManual] = $this->pastaComProcesso($tenant, 'Cliente Segredo Manual');
        $this->marcarSegredoManual($processoManual);
        $this->criarMeta($tenant, $manual, $ana, 'Meta em segredo manual', 'today');

        [, $tenant2] = $this->criarAdmin();
        [$deFora] = $this->pastaComProcesso($tenant2, 'Cliente De Fora');
        $this->criarMeta($tenant2, $deFora, $ana, 'Meta de outro escritorio', 'today');
        $this->em()->flush();

        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->conversaComTitulo($client);
        $this->programarConsulta(['periodo' => 'hoje']);
        $antes = $this->retrato();

        $eventos = $this->perguntar($client, $id, 'tem prazo hoje?');

        $pedidos = $this->provedorFalso()->pedidos;
        self::assertCount(2, $pedidos, 'a chamada que pediu a consulta e a que respondeu; sem terceira');
        self::assertContains(FerramentasDaConsulta::PRAZOS_E_METAS, self::nomes($pedidos[0]));
        self::assertStringContainsString('CONSULTA DE PRAZOS E METAS:', $pedidos[0]->sistema);
        self::assertSame([], $pedidos[1]->ferramentas, 'a segunda chamada não tem ferramenta: não há laço');

        $bloco = self::ultimaMensagem($pedidos[1]);
        self::assertStringContainsString('<prazos_e_metas>', $bloco);
        self::assertStringContainsString('Meta da Ana para hoje', $bloco, '(a) a meta dela que vence hoje');
        self::assertStringContainsString('CLIENTE VISIVEL', $bloco);
        self::assertStringContainsString('vence hoje', $bloco);
        self::assertStringNotContainsString('Meta da Ana atrasada', $bloco, 'o período é hoje');
        self::assertStringNotContainsString('Meta do colega Bruno', $bloco, '(b) a meta do colega');
        self::assertStringNotContainsString('Meta da pasta trancada', $bloco, '(c) a pasta com cadeado');
        self::assertStringNotContainsString('CLIENTE TRANCADO', $bloco);
        self::assertStringNotContainsString('Meta em segredo', $bloco, '(d) o segredo de justiça');
        self::assertStringNotContainsString('CLIENTE SIGILOSO', $bloco);
        self::assertStringNotContainsString('CLIENTE SEGREDO MANUAL', $bloco);
        self::assertStringContainsString('• 2 meta(s) do período ficaram de fora por serem de processo em segredo de justiça', $bloco);
        self::assertStringNotContainsString('Meta de outro escritorio', $bloco, '(e) outro escritório');
        self::assertStringNotContainsString('CLIENTE DE FORA', $bloco);
        self::assertStringEndsWith('tem prazo hoje?', $bloco);

        self::assertSame(['delta', 'delta', 'delta', 'fim'], array_column($eventos, 'evento'));
        self::assertSame(700, $eventos[3]['dados']['tokensEntrada'], 'as duas chamadas somadas');
        self::assertSame(70, $eventos[3]['dados']['tokensSaida']);
        self::assertSame($antes, $this->retrato(), '(f) a consulta não grava nada');

        $this->em()->clear();
        $client->request('GET', "/chat-ia/conversas/$id");
        $falas = $this->json($client)['mensagens'];
        self::assertSame(['usuario', 'assistente'], array_column($falas, 'papel'), 'uma resposta só');
        self::assertSame("Vou consultar.\n\nHoje vence uma meta.", $falas[1]['conteudo']);
    }

    #[TestDox('"O que está atrasado?" traz as atrasadas dela, com os dias de atraso')]
    public function testAtrasadas(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        [$pasta] = $this->pastaComProcesso($tenant, 'Cliente Atrasado');
        $this->criarMeta($tenant, $pasta, $ana, 'Recurso atrasado da Ana', '-3 days');
        $this->criarMeta($tenant, $pasta, $ana, 'Meta futura da Ana', '+2 days');
        $this->criarMeta($tenant, $pasta, $bruno, 'Atrasada do Bruno', '-3 days');
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->conversaComTitulo($client);
        $this->programarConsulta(['periodo' => 'atrasadas']);

        $this->perguntar($client, $id, 'o que está atrasado?');

        $bloco = self::ultimaMensagem($this->provedorFalso()->pedidos[1]);
        self::assertStringContainsString('Recurso atrasado da Ana', $bloco);
        self::assertStringContainsString('atrasada há 3 dia(s)', $bloco);
        self::assertStringNotContainsString('Meta futura da Ana', $bloco);
        self::assertStringNotContainsString('Atrasada do Bruno', $bloco);
    }

    #[TestDox('Sem o módulo Tarefas a ferramenta não é oferecida; se o modelo a pedir mesmo assim, a consulta é recusada sem dado')]
    public function testSemModuloTarefas(): void
    {
        [$client, $tenant, $ana] = $this->cenario(['modules.inteligencia.view']);
        [$pasta] = $this->pastaComProcesso($tenant, 'Cliente Qualquer');
        $this->criarMeta($tenant, $pasta, $ana, 'Meta que nao pode sair', 'today');
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->conversaComTitulo($client);
        $this->programarConsulta(['periodo' => 'hoje']);

        $this->perguntar($client, $id, 'tem prazo hoje?');

        $pedidos = $this->provedorFalso()->pedidos;
        self::assertNotContains(FerramentasDaConsulta::PRAZOS_E_METAS, self::nomes($pedidos[0]));
        self::assertStringNotContainsString('CONSULTA DE PRAZOS E METAS:', $pedidos[0]->sistema);
        $bloco = self::ultimaMensagem($pedidos[1]);
        self::assertStringContainsString('• Consulta não realizada: a pessoa não tem acesso às metas por aqui.', $bloco);
        self::assertStringNotContainsString('Meta que nao pode sair', $bloco);
    }

    #[TestDox('Sem o aceite de envio do escritório a ferramenta não é oferecida e a consulta é recusada')]
    public function testSemAceite(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        [$pasta] = $this->pastaComProcesso($tenant, 'Cliente Qualquer');
        $this->criarMeta($tenant, $pasta, $ana, 'Meta sem aceite', 'today');
        $this->em()->getConnection()->executeStatement('UPDATE inteligencia_configuracao SET consentimento_envio_externo_em = NULL WHERE tenant_id = ?', [$tenant->getId()]);
        $this->em()->clear();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->conversaComTitulo($client);
        $this->programarConsulta(['periodo' => 'hoje']);

        $this->perguntar($client, $id, 'tem prazo hoje?');

        $pedidos = $this->provedorFalso()->pedidos;
        self::assertNotContains(FerramentasDaConsulta::PRAZOS_E_METAS, self::nomes($pedidos[0]));
        self::assertStringNotContainsString('Meta sem aceite', self::ultimaMensagem($pedidos[1]));
        self::assertStringContainsString('Consulta não realizada', self::ultimaMensagem($pedidos[1]));
    }

    #[TestDox('O modelo não escolhe a pessoa: entrada com outro usuário é recusada como formato inválido')]
    public function testEntradaComOutraPessoa(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        [$pasta] = $this->pastaComProcesso($tenant, 'Cliente Qualquer');
        $this->criarMeta($tenant, $pasta, $bruno, 'Meta do Bruno hoje', 'today');
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->conversaComTitulo($client);
        $this->programarConsulta(['periodo' => 'hoje', 'usuario' => (string) $bruno->getId()]);

        $this->perguntar($client, $id, 'o que o Bruno tem hoje?');

        $bloco = self::ultimaMensagem($this->provedorFalso()->pedidos[1]);
        self::assertStringContainsString('• Consulta não realizada: o pedido de consulta veio num formato que o sistema não reconhece.', $bloco);
        self::assertStringNotContainsString('Meta do Bruno hoje', $bloco);
    }
}
