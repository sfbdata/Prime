<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\EventListener\BarreiraDeAnaliseDeAbaOcultaListener;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\EventListener\BarreiraDeAbaOcultaListener;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Correções da revisão de permissão do ocultar aba: análises de IA por aba, documentos do agente,
 * resumo impresso sem Processos e Metas, contrato de honorários com o Financeiro oculto, a leitura do
 * Push e os painéis de todas as abas.
 */
#[CoversClass(BarreiraDeAnaliseDeAbaOcultaListener::class)]
#[CoversClass(VisibilidadeDaAnaliseDaPasta::class)]
#[CoversClass(BarreiraDeAbaOcultaListener::class)]
#[Group('pasta')]
final class PastaAbaOcultaRevisaoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $primario;
    private User $ana;
    private Pasta $pasta;
    private Processo $processo;

    private function cenario(): void
    {
        $this->client = $this->cliente();
        $this->tenant = $this->criarTenant();
        $this->primario = $this->criarPrimario($this->tenant);
        $this->ana = $this->criarUsuarioComPermissoes($this->tenant, ['resources.pasta.view', 'modules.inteligencia.view', 'modules.expediente.view']);
        [$this->pasta, $this->processo] = $this->criarPastaComPush($this->tenant);
    }

    private function como(User $quem): KernelBrowser
    {
        $this->logarComTenant($this->client, $quem, $this->tenant);

        return $this->client;
    }

    private function codigo(User $quem, string $caminho, string $metodo = 'GET'): int
    {
        $this->como($quem)->request($metodo, $caminho, [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @param array<string, mixed> $resumo */
    private function analise(TipoDeAnalise $tipo, ?Agente $agente = null, array $resumo = []): AnaliseDeInteligencia
    {
        $a = new AnaliseDeInteligencia($this->tenant, $this->primario, $tipo, AnaliseDeInteligencia::ALVO_PASTA, (int) $this->pasta->getId(), 'v1', hash('sha256', uniqid()), $resumo, $agente);
        $this->em()->persist($a);
        $this->em()->flush();

        return $a;
    }

    private function documento(string $categoria, string $nome): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTenant($this->tenant);
        $doc->setPasta($this->pasta);
        $doc->setTitulo($nome);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo('teste/' . uniqid() . '.pdf');
        $doc->setNomeOriginal($nome . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1234);
        $this->em()->persist($doc);
        $this->em()->flush();
        $this->em()->refresh($this->pasta); // a coleção `documentos` da pasta relê o banco

        return $doc;
    }

    #[TestDox('Análises de IA: a de uma aba oculta some do painel dos agentes e por id é 404 (status, .txt, registro, lida); a de aba visível segue; o primário lê todas')]
    public function testAnalisesPorAba(): void
    {
        $this->cenario();
        $documental = $this->analise(TipoDeAnalise::AnalisePasta, Agente::Documental, ['financeiro' => false]);
        $processual = $this->analise(TipoDeAnalise::AnalisePasta, Agente::Processual, ['financeiro' => false]);
        $this->ocultarAba($this->pasta, AbaDaPasta::Documentos, $this->primario, [$this->ana]);
        $base = "/pasta/{$this->pasta->getId()}/ia";

        $painel = (string) $this->como($this->ana)->request('GET', "{$base}/agentes")->html();
        self::assertStringNotContainsString('data-ia-analise="' . $documental->getId() . '"', $painel);
        self::assertStringContainsString('data-ia-analise="' . $processual->getId() . '"', $painel);
        self::assertSame(404, $this->codigo($this->ana, "{$base}/analises/{$documental->getId()}"));
        self::assertSame(404, $this->codigo($this->ana, "{$base}/analises/{$documental->getId()}.txt"));
        self::assertSame(404, $this->codigo($this->ana, "{$base}/analises/{$documental->getId()}/registro", 'POST'));
        self::assertSame(404, $this->codigo($this->ana, "{$base}/analises/{$documental->getId()}/lida", 'POST'));
        self::assertSame(200, $this->codigo($this->ana, "{$base}/analises/{$processual->getId()}"));

        $doPrimario = (string) $this->como($this->primario)->request('GET', "{$base}/agentes")->html();
        self::assertStringContainsString('data-ia-analise="' . $documental->getId() . '"', $doPrimario);
        self::assertSame(200, $this->codigo($this->primario, "{$base}/analises/{$documental->getId()}"));
    }

    #[TestDox('Resumo do Push com o Push oculto: por id é 404, e a lista do Push (inteligencia_push_listar) é barrada')]
    public function testResumoDoPushOculto(): void
    {
        $this->cenario();
        $push = $this->analise(TipoDeAnalise::ResumoPush);
        $this->ocultarAba($this->pasta, AbaDaPasta::Push, $this->primario, [$this->ana]);
        $base = "/pasta/{$this->pasta->getId()}/ia";

        self::assertSame(404, $this->codigo($this->ana, "{$base}/analises/{$push->getId()}"));
        self::assertSame(403, $this->codigo($this->ana, "{$base}/push/analises"));
        self::assertSame(200, $this->codigo($this->primario, "{$base}/analises/{$push->getId()}"));
    }

    /** @return iterable<string, array{string}> */
    public static function leituraDoPush(): iterable
    {
        yield 'teor' => ['/push/999999'];
        yield 'texto' => ['/push/999999/texto'];
        yield 'análises da IA do Push' => ['/ia/push/analises'];
    }

    #[DataProvider('leituraDoPush')]
    #[TestDox('Leitura do Push com ele oculto: $caminho dá 403 com o aviso; quem vê passa pela barreira')]
    public function testLeituraDoPush(string $caminho): void
    {
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Push, $this->primario, [$this->ana]);
        $url = "/pasta/{$this->pasta->getId()}{$caminho}";

        self::assertSame(403, $this->codigo($this->ana, $url));
        self::assertSame('A aba Push Processual está oculta para você nesta pasta.', json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['erro']);
        $this->codigo($this->primario, $url);
        self::assertStringNotContainsString('oculta para voc', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Documentos do Agente de Pastas: barrados com Documentos oculto; com o Financeiro oculto, sem o contrato')]
    public function testDocumentosDoAgente(): void
    {
        $this->cenario();
        $this->documento(PastaDocumento::CATEGORIA_CONTRATO, 'contrato-honorarios');
        $this->documento(PastaDocumento::CATEGORIA_DEMAIS, 'procuracao');
        $url = "/api/agente/pastas/{$this->pasta->getId()}/documentos";

        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario, [$this->ana]);
        self::assertSame(200, $this->codigo($this->ana, $url));
        $corpo = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('procuracao', $corpo);
        self::assertStringNotContainsString('contrato-honorarios', $corpo);

        $this->ocultarAba($this->pasta, AbaDaPasta::Documentos, $this->primario, [$this->ana]);
        self::assertSame(403, $this->codigo($this->ana, $url));
    }

    #[TestDox('Resumo impresso: sem a seção Processos com Processo oculto e sem Metas abertas com Metas oculto; o número do processo segue no cabeçalho')]
    public function testResumoSemProcessosEMetas(): void
    {
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Processo, $this->primario, [$this->ana]);
        $this->ocultarAba($this->pasta, AbaDaPasta::Tarefas, $this->primario, [$this->ana]);
        $url = "/pasta/{$this->pasta->getId()}/resumo/imprimir?secao=resumo";

        $this->como($this->primario)->request('GET', $url);
        $doPrimario = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-secao="processos"', $doPrimario);
        self::assertStringContainsString('data-secao="metas"', $doPrimario);

        $this->como($this->ana)->request('GET', $url);
        self::assertResponseIsSuccessful();
        $daAna = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('data-secao="processos"', $daAna);
        self::assertStringNotContainsString('data-secao="metas"', $daAna);
        self::assertStringContainsString('<b>Processo</b>', $daAna, 'o número do processo fica no cabeçalho da folha');
    }

    #[TestDox('Contrato com o Financeiro oculto: fora do explorador, do cartão do trilho e da contagem; por id (ver, baixar) 404; o primário vê')]
    public function testContratoComFinanceiroOculto(): void
    {
        $this->cenario();
        $contrato = $this->documento(PastaDocumento::CATEGORIA_CONTRATO, 'contrato-honorarios');
        $this->documento(PastaDocumento::CATEGORIA_DEMAIS, 'procuracao');
        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario, [$this->ana]);

        $crawler = $this->como($this->ana)->request('GET', "/pasta/{$this->pasta->getId()}");
        $explorador = (string) $crawler->filter('#pexDados')->text();
        self::assertStringContainsString('procuracao.pdf', $explorador);
        self::assertStringNotContainsString('contrato-honorarios', $explorador);
        self::assertStringNotContainsString('contrato-honorarios', $crawler->filter('[data-trilho="documentos"]')->text());
        self::assertSame('1', trim($crawler->filter('#documentos-tab .ps-aba-badge')->text()));
        self::assertSame(404, $this->codigo($this->ana, "/pasta/documento/{$contrato->getId()}/visualizar"));
        self::assertSame(404, $this->codigo($this->ana, "/pasta/documento/{$contrato->getId()}/download"));

        $doPrimario = $this->como($this->primario)->request('GET', "/pasta/{$this->pasta->getId()}");
        self::assertStringContainsString('contrato-honorarios', (string) $doPrimario->filter('#pexDados')->text());
        self::assertSame('2', trim($doPrimario->filter('#documentos-tab .ps-aba-badge')->text()));
        self::assertNotSame(404, $this->codigo($this->primario, "/pasta/documento/{$contrato->getId()}/visualizar"));
    }

    #[TestDox('Contrato com o Financeiro oculto: o envio dele sai do Histórico do sistema')]
    public function testContratoForaDoHistorico(): void
    {
        $this->cenario();
        $contrato = $this->documento(PastaDocumento::CATEGORIA_CONTRATO, 'contrato-honorarios');
        $this->em()->getConnection()->insert('audit_log', [
            'action' => 'create', 'entity_class' => 'App\\Pasta\\Entity\\PastaDocumento', 'entity_id' => (string) $contrato->getId(),
            'changes' => json_encode(['diff' => ['after' => ['nomeOriginal' => 'contrato-honorarios.pdf', 'categoria' => 'CONTRATO']]]),
            'tenant_id' => $this->tenant->getId(), 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->em()->getConnection()->insert('audit_log', [
            'action' => 'update', 'entity_class' => 'App\\Pasta\\Entity\\PastaDocumento', 'entity_id' => (string) $contrato->getId(),
            'changes' => json_encode(['diff' => ['changes' => ['titulo' => ['from' => 'a', 'to' => 'Contrato revisto']]]]),
            'tenant_id' => $this->tenant->getId(), 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario, [$this->ana]);

        $doPrimario = $this->como($this->primario)->request('GET', "/pasta/{$this->pasta->getId()}")->filter('#psHistorico')->text('');
        $daAna = $this->como($this->ana)->request('GET', "/pasta/{$this->pasta->getId()}")->filter('#psHistorico')->text('');

        self::assertStringContainsString('Documento enviado', $doPrimario);
        self::assertStringContainsString('Documento atualizado', $doPrimario);
        self::assertStringNotContainsString('Documento enviado', $daAna);
        self::assertStringNotContainsString('Documento atualizado', $daAna, 'a edição do contrato sai pelo id');
    }

    /** @return iterable<string, array{AbaDaPasta}> */
    public static function abasSemPainel(): iterable
    {
        foreach ([AbaDaPasta::Processo, AbaDaPasta::Detalhes, AbaDaPasta::Tarefas, AbaDaPasta::Documentos] as $aba) {
            yield $aba->rotulo() => [$aba];
        }
    }

    #[DataProvider('abasSemPainel')]
    #[TestDox('Tela com a aba $aba oculta: sem o botão e sem o painel; o primário vê os dois')]
    public function testTelaSemPainel(AbaDaPasta $aba): void
    {
        $this->cenario();
        $this->ocultarAba($this->pasta, $aba, $this->primario, [$this->ana]);

        $daAna = $this->como($this->ana)->request('GET', "/pasta/{$this->pasta->getId()}");
        self::assertCount(0, $daAna->filter("#{$aba->value}-tab"));
        self::assertCount(0, $daAna->filter("#pastaTabsContent > #{$aba->value}"));

        $doPrimario = $this->como($this->primario)->request('GET', "/pasta/{$this->pasta->getId()}");
        self::assertCount(1, $doPrimario->filter("#pastaTabs > #{$aba->value}-tab"));
        self::assertCount(1, $doPrimario->filter("#{$aba->value}.tab-pane"));
    }
}
