<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\ChatIa\Service\ContextoDasMetasNoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\DadosManuaisDoProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O contexto `metas` do Chat I.A (painel da IA em Minhas Metas) e a regra 4 da Inteligencia: meta de pasta com processo em
 * segredo de justiça (sigilo do tribunal OU "Sim" manual do cartão) nunca chega ao provedor; dela sai só a contagem. O
 * contexto exige o módulo Tarefas (a guarda da tela) e só lê metas do escritório da sessão. O provedor é o falso: o texto
 * conferido é o pedido que ele recebeu.
 */
#[CoversClass(ContextoDasMetasNoChat::class)]
#[CoversClass(ProcessoRepository::class)]
final class MetasEmSegredoNoChatTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const COM_TAREFAS = ['modules.inteligencia.view', 'modules.tarefas.view'];
    private const OMITIDAS = 'meta(s) ficaram de fora destas listas por serem de processo em segredo de justiça';

    private int $numeroDoProcesso = 0;

    /**
     * @param list<string> $permissoes
     *
     * @return array{0: KernelBrowser, 1: Tenant, 2: User, 3: User}
     */
    private function cenario(array $permissoes = self::COM_TAREFAS): array
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, $permissoes, 'ana');

        return [$client, $tenant, $ana, $admin];
    }

    /**
     * Uma pasta com um processo vinculado e uma meta da pessoa nela (atrasada: entra em "primeiro" e nas atrasadas).
     *
     * @return array{0: Pasta, 1: Processo}
     */
    private function metaEmPasta(Tenant $tenant, User $responsavel, string $titulo, string $nup, string $cliente): array
    {
        $pasta = $this->criarPasta($tenant);
        $pasta->setNup($nup);
        $pasta->setNomeCliente($cliente);
        $processo = $this->novoProcesso($tenant);
        $this->vincular($pasta, $processo);
        $this->criarMeta($tenant, $pasta, $responsavel, $titulo, '-2 days');

        return [$pasta, $processo];
    }

    private function novoProcesso(Tenant $tenant): Processo
    {
        return $this->criarProcesso($tenant, sprintf('070113457202580700%02d', ++$this->numeroDoProcesso));
    }

    /** Pergunta com o sinal `metas` e devolve TODO o texto que foi ao provedor (sistema e mensagens). */
    private function perguntarComMetas(KernelBrowser $client): string
    {
        $id = $this->novaConversaComTitulo($client);
        $this->provedorFalso()->transmitirTrechos(['Resposta']);

        $this->post($client, "/chat-ia/conversas/$id/mensagens", ['texto' => 'O que fazer primeiro?', 'contexto' => 'metas']);

        self::assertResponseIsSuccessful();
        $pedido = $this->provedorFalso()->ultimoPedido();
        self::assertNotNull($pedido);

        return $pedido->sistema . "\n" . json_encode($pedido->mensagens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Com título manual não há a chamada curta do título: o último pedido do falso é o da resposta. */
    private function novaConversaComTitulo(KernelBrowser $client): int
    {
        $this->post($client, '/chat-ia/conversas', []);
        self::assertResponseStatusCodeSame(201);
        $id = (int) $this->json($client)['id'];
        $client->request('PATCH', "/chat-ia/conversas/$id", [], [], self::CSRF, '{"titulo":"Metas"}');
        self::assertResponseIsSuccessful();

        return $id;
    }

    private function afirmarQueNaoSaiu(string $texto, string $titulo, string $nup, string $cliente): void
    {
        self::assertStringNotContainsStringIgnoringCase($titulo, $texto, 'o título da meta em segredo nunca vai ao provedor');
        self::assertStringNotContainsStringIgnoringCase($nup, $texto, 'o número da pasta em segredo nunca vai ao provedor');
        self::assertStringNotContainsStringIgnoringCase($cliente, $texto, 'o cliente da pasta em segredo nunca vai ao provedor');
    }

    #[TestDox('(a) meta de pasta com processo de sigilo do tribunal (nivel_sigilo > 0) não entra no prompt; só a contagem; a normal entra')]
    public function testSigiloDoTribunalNaoEntra(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        [, $secreto] = $this->metaEmPasta($tenant, $ana, 'Peticionar no sigiloso', 'PASTA-SIGILO-TRIBUNAL', 'Cliente Sigiloso Tribunal');
        $secreto->setNivelSigilo(1);
        $this->metaEmPasta($tenant, $ana, 'Protocolar recurso comum', 'PASTA-NORMAL-UM', 'Cliente Comum Um');
        $this->em()->flush();
        $this->logarComTenant($client, $ana, $tenant);

        $texto = $this->perguntarComMetas($client);

        self::assertStringContainsString('<metas>', $texto);
        self::assertStringContainsString('Protocolar recurso comum', $texto);
        self::assertStringContainsString('PASTA-NORMAL-UM', $texto);
        $this->afirmarQueNaoSaiu($texto, 'Peticionar no sigiloso', 'PASTA-SIGILO-TRIBUNAL', 'Cliente Sigiloso Tribunal');
        // A mesma meta está em "o que fazer primeiro" e nas atrasadas: conta uma vez.
        self::assertStringContainsString('• 1 ' . self::OMITIDAS, $texto);
    }

    #[TestDox('(b) meta de pasta com o "Sim" manual de segredo no cartão não entra, mesmo com outro processo público na pasta')]
    public function testSegredoManualNaoEntra(): void
    {
        [$client, $tenant, $ana, $admin] = $this->cenario();
        [$pasta, $secreto] = $this->metaEmPasta($tenant, $ana, 'Audiencia reservada', 'PASTA-SIGILO-MANUAL', 'Cliente Reservado Manual');
        $secreto->setNivelSigilo(0);
        $secreto->definirDadosManuais(new DadosManuaisDoProcesso(segredo: true), $admin);
        // Basta UM processo em segredo na pasta: o público ao lado não libera a meta.
        $this->vincular($pasta, $this->novoProcesso($tenant));
        $this->metaEmPasta($tenant, $ana, 'Juntar procuracao comum', 'PASTA-NORMAL-DOIS', 'Cliente Comum Dois');
        $this->em()->flush();
        $this->logarComTenant($client, $ana, $tenant);

        $texto = $this->perguntarComMetas($client);

        self::assertStringContainsString('Juntar procuracao comum', $texto);
        $this->afirmarQueNaoSaiu($texto, 'Audiencia reservada', 'PASTA-SIGILO-MANUAL', 'Cliente Reservado Manual');
        self::assertStringContainsString('• 1 ' . self::OMITIDAS, $texto);
    }

    #[TestDox('(c) meta normal continua entrando com título, pasta e cliente, sem linha de omitidas')]
    public function testMetaNormalEntra(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->metaEmPasta($tenant, $ana, 'Elaborar contestacao comum', 'PASTA-NORMAL-TRES', 'Cliente Comum Tres');
        $this->logarComTenant($client, $ana, $tenant);

        $texto = $this->perguntarComMetas($client);

        self::assertStringContainsString('Elaborar contestacao comum', $texto);
        self::assertStringContainsString('PASTA-NORMAL-TRES', $texto);
        self::assertStringContainsString('CLIENTE COMUM TRES', $texto);
        self::assertStringNotContainsString(self::OMITIDAS, $texto);
    }

    #[TestDox('(d) sem o módulo Tarefas o contexto metas não monta: 403 sem_acesso, nada gravado e o provedor não é chamado')]
    public function testSemModuloTarefasRecusa(): void
    {
        [$client, $tenant, $ana] = $this->cenario(['modules.inteligencia.view']);
        $this->metaEmPasta($tenant, $ana, 'Meta de quem nao ve tarefas', 'PASTA-SEM-MODULO', 'Cliente Sem Modulo');
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversaComTitulo($client);
        $this->provedorFalso()->transmitirTrechos(['Resposta']);

        $this->post($client, "/chat-ia/conversas/$id/mensagens", ['texto' => 'O que fazer primeiro?', 'contexto' => 'metas']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('sem_acesso', $this->json($client)['codigo']);
        self::assertFalse($this->provedorFalso()->foiChamado(), 'recusa antes do provedor');
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem WHERE conversa_id = ?', [$id]));

        // Sem o sinal, a mesma pessoa conversa normalmente, sem bloco de metas.
        $this->post($client, "/chat-ia/conversas/$id/mensagens", ['texto' => 'Oi']);
        self::assertResponseIsSuccessful();
        $mensagens = $this->provedorFalso()->ultimoPedido()?->mensagens ?? [];
        self::assertStringNotContainsString('<metas>', end($mensagens)['conteudo'] ?? '');
    }

    #[TestDox('(e) meta de OUTRO escritório não entra, mesmo sem o TenantFilter (a guarda é do próprio contexto)')]
    public function testOutroEscritorioNaoEntra(): void
    {
        [, $tenant, $ana] = $this->cenario();
        $this->metaEmPasta($tenant, $ana, 'Meta do escritorio certo', 'PASTA-DO-ESCRITORIO', 'Cliente Do Escritorio');
        $outro = $this->criarTenant();
        $this->metaEmPasta($outro, $ana, 'Meta do outro escritorio', 'PASTA-DE-FORA', 'Cliente De Fora');
        // Sem requisição o TenantFilter não liga: é o caminho em que só a guarda explícita segura.
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'));

        $texto = implode("\n", static::getContainer()->get(ContextoDasMetasNoChat::class)->ler($ana, $tenant));

        self::assertStringContainsString('Meta do escritorio certo', $texto);
        self::assertStringNotContainsStringIgnoringCase('Meta do outro escritorio', $texto);
        self::assertStringNotContainsStringIgnoringCase('PASTA-DE-FORA', $texto);
        self::assertStringNotContainsStringIgnoringCase('Cliente De Fora', $texto);
    }
}
