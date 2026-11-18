<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Controller\PastaRecebidaController;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Pasta\Service\LeitorDaPastaRecebida;
use App\Pasta\Service\PastaRecebidaDoUsuario;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Routing\RouterInterface;

/**
 * Fatia 9a da spec `docs/specs/acesso-restrito-e-compartilhar.md` (§4.5.1): quem recebeu a pasta de outra conta e
 * aceitou abre a pasta, só leitura, pelas rotas `expediente_recebida_*`, sem sair do próprio escritório (B). A pasta é
 * do escritório A. Cobre a lista "Testes obrigatórios (9a)" do adendo.
 */
#[CoversClass(PastaRecebidaController::class)]
#[CoversClass(PastaRecebidaDoUsuario::class)]
#[CoversClass(LeitorDaPastaRecebida::class)]
final class AbrirPastaRecebidaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $contaA;
    private Tenant $contaB;
    private User $donoA;
    private User $olga;
    private User $bia;
    private Pasta $pasta;
    private PastaDocumento $documento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
        [$this->donoA, $this->contaA] = $this->criarEscritorio();
        [, $this->contaB] = $this->criarEscritorio();
        $this->olga = $this->criarColaborador($this->contaA, 'Olga Origem', self::PERMISSOES);
        $this->bia = $this->criarColaborador($this->contaB, 'Bia Parceira', self::PERMISSOES);
        $this->pasta = $this->criarPasta($this->contaA, 'CLIENTE DA CONTA A');
        $this->pasta->setNomeAcao('AÇÃO DE COBRANÇA');
        $this->pasta->setResponsavel($this->donoA);
        $this->em()->flush();
        $this->documento = $this->documento($this->pasta, 'PETIÇÃO INICIAL');
    }

    #[TestDox('convite aceito abre: janela, página e documento (ver e baixar com a caixa); a trilha de A registra a leitura')]
    public function testAbre(): void
    {
        $this->mensagem($this->pasta, 'ANOTAÇÃO DA EQUIPE A', null);
        $this->mensagem($this->pasta, 'FASE AVANÇOU PARA INSTRUÇÃO', 'fase');
        $this->mensagem($this->pasta, 'RESPONSÁVEL TROCADO', 'resp');
        $this->meta($this->pasta, 'META ABERTA DA PASTA');
        $this->meta($this->pasta, 'META CONCLUÍDA DA PASTA', Tarefa::STATUS_CONCLUIDA);
        $id = $this->convite(permissoes: new PermissoesDoCompartilhamento(true, false, true));
        $this->entrar($this->bia, $this->contaB);

        $janela = $this->ver($id);
        self::assertSame(
            [$id, (string) $this->pasta->getNup(), 'CLIENTE DA CONTA A', 'AÇÃO DE COBRANÇA', 'Paula Primária', $this->contaA->getName(), true, false, true, 1, false],
            [$janela['id'], $janela['nup'], $janela['cliente'], $janela['acao'], $janela['de'], $janela['conta'], $janela['movimentar'], $janela['enviarDocumentos'], $janela['baixarDocumentos'], $janela['metas'], $janela['soLeitura']],
        );
        self::assertSame('/expediente/recebidas/' . $id . '/pasta', $janela['urlPasta']);
        self::assertSame('/expediente/recebidas/' . $id . '/pasta?aba=metas', $janela['urlMetas']);

        $crawler = $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');
        self::assertResponseIsSuccessful();
        $pagina = $crawler->filter('.pr-page')->text();
        foreach (['CLIENTE DA CONTA A', 'AÇÃO DE COBRANÇA', 'ANOTAÇÃO DA EQUIPE A', 'FASE AVANÇOU PARA INSTRUÇÃO', 'PETIÇÃO INICIAL', 'META ABERTA DA PASTA', $this->contaA->getName()] as $texto) {
            self::assertStringContainsString($texto, $pagina);
        }
        foreach (['RESPONSÁVEL TROCADO', 'META CONCLUÍDA DA PASTA'] as $texto) {
            self::assertStringNotContainsString($texto, $pagina, 'cartão do sistema fora do andamento e meta concluída ficam de fora');
        }
        self::assertSame('andamentos-tab', $crawler->filter('.ps-aba.active')->attr('id'));
        $this->assertFiltroLigadoEmB();

        $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta?aba=metas');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#metas-tab.active');

        $this->client->request('GET', $this->urlDocumento($id, (int) $this->documento->getId(), 'ver'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', $this->urlDocumento($id, (int) $this->documento->getId(), 'baixar'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $trilha = $this->trilhaEmA();
        self::assertContains(['leitura', Pasta::class, (string) $this->pasta->getId()], $trilha);
        self::assertContains(['leitura', PastaDocumento::class, (string) $this->documento->getId()], $trilha);
        self::assertCount(4, $trilha, 'duas páginas e dois documentos; a janela não grava');
    }

    #[TestDox('a página não tem link para cliente, processo, metas, cobrança nem push')]
    public function testPaginaSemLinksParaOutrasTelasDeA(): void
    {
        $id = $this->convite(permissoes: new PermissoesDoCompartilhamento(false, false, true));
        $this->entrar($this->bia, $this->contaB);

        $crawler = $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');
        self::assertResponseIsSuccessful();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $rotas = [];
        foreach ($crawler->filter('.pr-page a[href]') as $a) {
            $caminho = (string) parse_url((string) $a->getAttribute('href'), PHP_URL_PATH);
            $rotas[] = (string) ($router->match($caminho)['_route'] ?? '');
        }

        self::assertNotEmpty($rotas);
        foreach ($rotas as $rota) {
            foreach (['cliente_', 'processo_', 'tarefa_', 'cobranca_', 'push_', 'pasta_'] as $proibido) {
                self::assertStringStartsNotWith($proibido, $rota);
            }
        }
        self::assertSame(['expediente_index', 'expediente_recebida_documento', 'expediente_recebida_documento'], $rotas);
    }

    #[TestDox('restrito no escritório de quem recebeu também abre')]
    public function testRestritoEmBAbre(): void
    {
        $this->vinculo($this->bia, $this->contaB)->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $this->em()->flush();
        $id = $this->convite();
        $this->entrar($this->bia, $this->contaB);

        $this->ver($id);
        $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('quem republicou sem ser o primário, ativo e editando a pasta: abre')]
    public function testOrigemColaboradoraAbre(): void
    {
        $id = $this->convite($this->olga);
        $this->entrar($this->bia, $this->contaB);

        self::assertSame('Olga Origem', $this->ver($id)['de']);
    }

    #[TestDox('escritório de origem suspenso: abre só para leitura')]
    public function testASuspensoAbreSoLeitura(): void
    {
        $id = $this->convite();
        $this->contaA->setIsActive(false);
        $this->em()->flush();
        $this->entrar($this->bia, $this->contaB);

        self::assertTrue($this->ver($id)['soLeitura']);
        $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{0: string}> */
    public static function casosQueNaoAbrem(): iterable
    {
        foreach ([
            'pendente', 'recusado', 'encerrado', 'vencido', 'de outra pessoa', 'pasta excluída', 'cadeado Todos',
            'cadeado PorPessoa', 'A excluído', 'origem removida', 'origem inativa', 'origem sem edição',
            'origem restrita', 'origem com a conta desativada', 'destino com vínculo ativo em A', 'sem convite',
        ] as $caso) {
            yield $caso => [$caso];
        }
    }

    #[DataProvider('casosQueNaoAbrem')]
    #[TestDox('não abre ($caso): 404 na página, na janela e no documento, sem dizer o motivo')]
    public function testNaoAbre(string $caso): void
    {
        $id = $this->cenarioQueNaoAbre($caso);
        $this->entrar($this->bia, $this->contaB);

        foreach (['/expediente/recebidas/' . $id, '/expediente/recebidas/' . $id . '/pasta', $this->urlDocumento($id, (int) $this->documento->getId(), 'ver')] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $caso . ': ' . $url);
            self::assertStringNotContainsString('CLIENTE DA CONTA A', $this->conteudo());
        }
        self::assertSame([], $this->trilhaEmA(), 'a recusa do localizador não grava');
    }

    #[TestDox('aba "para todos" oculta pela origem primária some para quem recebeu')]
    public function testAbaOcultaParaTodosSome(): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->contaA, $this->pasta, AbaDaPasta::Documentos, $this->donoA, new \DateTimeImmutable()));
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->contaA, $this->pasta, AbaDaPasta::Tarefas, $this->donoA, new \DateTimeImmutable()));
        $this->em()->flush();
        $id = $this->convite(permissoes: new PermissoesDoCompartilhamento(false, false, true));
        $this->entrar($this->bia, $this->contaB);

        self::assertNull($this->ver($id)['metas'], 'aba Metas oculta: a janela não conta as metas');
        $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta?aba=metas');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#documentos-tab');
        self::assertSelectorNotExists('#metas-tab');
        self::assertSelectorExists('#andamentos-tab.active');
        self::assertStringNotContainsString('PETIÇÃO INICIAL', $this->conteudo());
        $this->client->request('GET', $this->urlDocumento($id, (int) $this->documento->getId(), 'ver'));
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('contrato com a aba Financeiro oculta: 404 e fora da lista')]
    public function testContratoComFinanceiroOculto(): void
    {
        $contrato = $this->documento($this->pasta, 'CONTRATO DE HONORÁRIOS', PastaDocumento::CATEGORIA_CONTRATO);
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->contaA, $this->pasta, AbaDaPasta::Financeiro, $this->donoA, new \DateTimeImmutable()));
        $this->em()->flush();
        $id = $this->convite();
        $this->entrar($this->bia, $this->contaB);

        $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');
        self::assertStringNotContainsString('CONTRATO DE HONORÁRIOS', $this->conteudo());
        self::assertStringContainsString('PETIÇÃO INICIAL', $this->conteudo());
        $this->client->request('GET', $this->urlDocumento($id, (int) $contrato->getId(), 'ver'));
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('documento de outra pasta de A, de B e na lixeira: 404; baixar sem a caixa: 403 e "negado" na trilha de A')]
    public function testDocumentoForaDoConvite(): void
    {
        $outraDeA = $this->criarPasta($this->contaA, 'OUTRO CLIENTE DE A');
        $docDeOutra = $this->documento($outraDeA, 'DOCUMENTO DE OUTRA PASTA');
        $docDeB = $this->documento($this->criarPasta($this->contaB, 'CLIENTE DE B'), 'DOCUMENTO DE B');
        $naLixeira = $this->documento($this->pasta, 'DOCUMENTO NA LIXEIRA');
        $naLixeira->marcarExcluido($this->donoA, new \DateTimeImmutable());
        $this->em()->flush();
        $id = $this->convite();
        $this->entrar($this->bia, $this->contaB);

        foreach ([$docDeOutra, $docDeB, $naLixeira] as $doc) {
            $this->client->request('GET', $this->urlDocumento($id, (int) $doc->getId(), 'ver'));
            self::assertResponseStatusCodeSame(404, $doc->getTitulo());
        }
        $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');
        self::assertStringNotContainsString('DOCUMENTO NA LIXEIRA', $this->conteudo());

        $this->client->request('GET', $this->urlDocumento($id, (int) $this->documento->getId(), 'baixar'));
        self::assertResponseStatusCodeSame(403);
        self::assertContains(['negado', PastaDocumento::class, (string) $this->documento->getId()], $this->trilhaEmA());
    }

    #[TestDox('o leitor não traz andamento, meta nem documento de outra pasta de A nem do escritório B')]
    public function testLeitorSoTrazAPastaDoConvite(): void
    {
        $outraDeA = $this->criarPasta($this->contaA, 'OUTRO CLIENTE DE A');
        $deB = $this->criarPasta($this->contaB, 'CLIENTE DE B');
        foreach (['DE OUTRA PASTA' => $outraDeA, 'DO ESCRITÓRIO B' => $deB] as $sufixo => $p) {
            $this->mensagem($p, 'ANOTAÇÃO ' . $sufixo, null);
            $this->meta($p, 'META ' . $sufixo);
            $this->documento($p, 'DOCUMENTO ' . $sufixo);
        }
        $id = $this->convite();
        $this->entrar($this->bia, $this->contaB);

        self::assertSame(0, $this->ver($id)['metas']);
        $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');
        self::assertResponseIsSuccessful();
        foreach (['DE OUTRA PASTA', 'DO ESCRITÓRIO B', 'OUTRO CLIENTE DE A', 'CLIENTE DE B'] as $texto) {
            self::assertStringNotContainsString($texto, $this->conteudo());
        }
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('a caixa Recebidas marca "abre" só no convite que abre')]
    public function testCaixaMarcaAbre(): void
    {
        $trancada = $this->criarPasta($this->contaA, 'PASTA TRANCADA');
        $this->trancar($trancada, $this->donoA);
        $abre = $this->convite();
        $comCadeado = $this->convite(pasta: $trancada);
        $semEdicao = $this->convite($this->criarColaborador($this->contaA, 'Sem Edição', ['modules.expediente.view', 'resources.pasta.view']), pasta: $this->criarPasta($this->contaA, 'PASTA SEM EDIÇÃO'));
        $pendente = $this->convite(aceito: false, pasta: $this->criarPasta($this->contaA, 'PASTA PENDENTE'));
        $this->entrar($this->bia, $this->contaB);

        $this->client->request('GET', '/expediente/recebidas');
        self::assertResponseIsSuccessful();
        $abrem = [];
        foreach (json_decode($this->conteudo(), true, flags: JSON_THROW_ON_ERROR)['itens'] as $item) {
            $abrem[$item['id']] = $item['abre'];
        }

        self::assertSame(
            [$abre => true, $comCadeado => false, $semEdicao => false, $pendente => false],
            [$abre => $abrem[$abre] ?? null, $comCadeado => $abrem[$comCadeado] ?? null, $semEdicao => $abrem[$semEdicao] ?? null, $pendente => $abrem[$pendente] ?? null],
        );
    }

    /** Monta o caso que não abre e devolve o id do compartilhamento que a Bia vai tentar. */
    private function cenarioQueNaoAbre(string $caso): int
    {
        $agora = new \DateTimeImmutable();
        switch ($caso) {
            case 'pendente':
                return $this->convite(aceito: false);
            case 'recusado':
                $c = $this->compartilhamento($this->donoA, $this->bia, $this->pasta, new PermissoesDoCompartilhamento());
                $c->recusar($this->bia, $agora);
                $this->em()->flush();

                return (int) $c->getId();
            case 'encerrado':
                $c = $this->compartilhamento($this->donoA, $this->bia, $this->pasta, new PermissoesDoCompartilhamento());
                $c->aceitar($this->bia, $agora);
                $c->encerrar($this->donoA, $agora);
                $this->em()->flush();

                return (int) $c->getId();
            case 'vencido':
                $id = $this->convite();
                $c = $this->em()->find(PastaCompartilhamento::class, $id);
                (new \ReflectionProperty(PastaCompartilhamento::class, 'validoAte'))->setValue($c, new \DateTimeImmutable('-1 day'));
                $this->em()->flush();

                return $id;
            case 'de outra pessoa':
                return $this->convite(destino: $this->criarColaborador($this->contaB, 'Caio de B', self::PERMISSOES));
            case 'pasta excluída':
                $this->pasta->marcarExcluida($this->donoA, $agora);
                $this->em()->flush();

                return $this->convite();
            case 'cadeado Todos':
                $this->trancar($this->pasta, $this->donoA);

                return $this->convite();
            case 'cadeado PorPessoa':
                $this->trancar($this->pasta, $this->donoA, [[$this->olga, NivelDeAcesso::Editar]]);

                return $this->convite($this->olga);
            case 'A excluído':
                $this->contaA->setExcluidoEm($agora);
                $this->em()->flush();

                return $this->convite();
            case 'origem removida':
                $this->em()->remove($this->vinculo($this->olga, $this->contaA));
                $this->em()->flush();

                return $this->convite($this->olga);
            case 'origem inativa':
                $this->vinculo($this->olga, $this->contaA)->alterarSituacao(SituacaoNaEquipe::Suspenso);
                $this->em()->flush();

                return $this->convite($this->olga);
            case 'origem sem edição':
                return $this->convite($this->criarColaborador($this->contaA, 'Vera Só Vê', ['modules.expediente.view', 'resources.pasta.view']));
            case 'origem restrita':
                $this->vinculo($this->olga, $this->contaA)->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
                $this->em()->flush();

                return $this->convite($this->olga);
            case 'origem com a conta desativada':
                $this->olga->setIsActive(false);
                $this->em()->flush();

                return $this->convite($this->olga);
            case 'destino com vínculo ativo em A':
                $this->vincular($this->bia, $this->contaA, false, self::PERMISSOES);

                return $this->convite();
            case 'sem convite':
                // Ninguém de B sem convite chega a nada: nem com o id de um compartilhamento interno de A.
                $interno = PastaCompartilhamento::interno($this->pasta, $this->donoA, $this->olga, new PermissoesDoCompartilhamento(), null, null, $agora);
                $this->em()->persist($interno);
                $this->em()->flush();

                return (int) $interno->getId();
        }

        throw new \LogicException('caso desconhecido: ' . $caso);
    }

    private function convite(?User $origem = null, ?User $destino = null, ?Pasta $pasta = null, bool $aceito = true, ?PermissoesDoCompartilhamento $permissoes = null): int
    {
        $destino ??= $this->bia;
        $c = $this->compartilhamento($origem ?? $this->donoA, $destino, $pasta ?? $this->pasta, $permissoes ?? new PermissoesDoCompartilhamento());
        if ($aceito) {
            $c->aceitar($destino, new \DateTimeImmutable('-1 day'));
        }
        $this->em()->flush();

        return (int) $c->getId();
    }

    private function compartilhamento(User $origem, User $destino, Pasta $pasta, PermissoesDoCompartilhamento $permissoes): PastaCompartilhamento
    {
        $c = PastaCompartilhamento::externo($pasta, $origem, $destino, $permissoes, null, null, new \DateTimeImmutable('-2 days'));
        $this->em()->persist($c);
        $this->em()->flush();

        return $c;
    }

    private function vinculo(User $pessoa, Tenant $tenant): UserTenant
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $pessoa, 'tenant' => $tenant]);
        self::assertInstanceOf(UserTenant::class, $vinculo);

        return $vinculo;
    }

    private function documento(Pasta $pasta, string $titulo, string $categoria = PastaDocumento::CATEGORIA_DEMAIS): PastaDocumento
    {
        $tenant = $pasta->getTenant();
        self::assertNotNull($tenant);
        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        $nome = $armazenamento->gravar(ChavesDePasta::novoDocumento((new PastaDocumento())->setTenant($tenant), 'pdf'), FonteDeConteudo::deTexto('conteúdo de ' . $titulo))->chave->nome;

        $doc = new PastaDocumento();
        $doc->setTitulo($titulo);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo($nome);
        $doc->setNomeOriginal('documento.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(20);
        $doc->setOrdem(1);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    private function mensagem(Pasta $pasta, string $texto, ?string $sistema): void
    {
        $m = new PastaMensagem();
        $m->setPasta($pasta);
        $m->setAutor($this->donoA);
        $m->setTenant($pasta->getTenant());
        $m->setConteudo($texto);
        $m->setSistema($sistema);
        $this->em()->persist($m);
        $this->em()->flush();
    }

    private function meta(Pasta $pasta, string $titulo, string $status = Tarefa::STATUS_PENDENTE): void
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Descrição');
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($this->donoA);
        $meta->addResponsavel($this->donoA);
        $meta->setStatus($status);
        $this->em()->persist($meta);
        $this->em()->flush();
    }

    /** @return array<string, mixed> */
    private function ver(int $id): array
    {
        $this->client->request('GET', '/expediente/recebidas/' . $id, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return json_decode($this->conteudo(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function urlDocumento(int $id, int $documentoId, string $modo): string
    {
        return '/expediente/recebidas/' . $id . '/documento/' . $documentoId . '/' . $modo;
    }

    /** @return list<array{0: string, 1: string, 2: string}> ação, classe e id gravados em A com a Bia como autora */
    private function trilhaEmA(): array
    {
        $linhas = static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT action, entity_class, entity_id FROM audit_log WHERE tenant_id = :a AND actor_user_id = :bia AND action IN (\'leitura\', \'negado\') ORDER BY id',
            ['a' => $this->contaA->getId(), 'bia' => $this->bia->getId()],
        );

        return array_map(static fn (array $l): array => [(string) $l['action'], (string) $l['entity_class'], (string) $l['entity_id']], $linhas);
    }

    /** Depois da leitura, o filtro do escritório volta ligado com o escritório da sessão (B). */
    private function assertFiltroLigadoEmB(): void
    {
        $filtros = $this->em()->getFilters();
        self::assertTrue($filtros->isEnabled('tenant'));
        self::assertSame((int) $this->contaB->getId(), (int) trim((string) $filtros->getFilter('tenant')->getParameter('tenant'), "'"));
    }

    private function conteudo(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
