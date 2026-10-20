<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Audit\AuditLog;
use App\Entity\Tarefa\TarefaMensagem;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Processo\Entity\Processo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O cadeado fora da tela da pasta (spec pasta-cadeado §5): D1 (Lista, busca, CSV, processo), D3 (Push
 * global e Minhas Metas), D4 (auditoria), D5 (imagem embutida na peça) e o critério de DQL do
 * `AcessoAPasta` (`restringirAoVisivel` e `criterioDeVisivel`). D2 está no
 * `BuscarPastasDoAgenteUseCaseTest`; D6 no `ReconciliadorDePasta` (pasta com cadeado sai do sync).
 */
#[CoversNothing]
final class PastaCadeadoForaDaPastaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    private function vincularProcesso(Pasta $pasta, string $numero, string $assunto = 'Indenização por dano moral'): Processo
    {
        $processo = new Processo();
        $processo->setTenant($pasta->getTenant());
        $processo->setNumeroProcesso($numero);
        $processo->setAssuntoProcessual($assunto);
        $this->em()->persist($processo);
        $pasta->vincularProcesso($processo);
        $pasta->setNomeAcao('Ação sigilosa');
        $this->em()->flush();

        return $processo;
    }

    // ── D1 ─────────────────────────────────────────────────────────────

    #[TestDox('D1 Lista: a pasta oculta continua listada só com número, cliente e cadeado; a busca não casa por processo nem ação dela')]
    public function testListaEBusca(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant);
        $oculta = $this->criarPasta($tenant, 'JOANA SIGILOSA');
        $this->vincularProcesso($oculta, '0700001-11.2026.8.07.0001');
        $this->trancar($oculta, $primario);
        $this->entrar($ana, $tenant);

        $c     = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        $linha = $c->filter('#tabelaPastas tbody > tr[data-pasta-id="' . $oculta->getId() . '"]');
        self::assertSame('1', $linha->attr('data-oculta'));
        self::assertNotEmpty($linha->attr('data-csrf-pedir'), 'o pedido da Lista leva o token da pasta');
        self::assertStringContainsString('JOANA SIGILOSA', $linha->filter('td[data-col="cliente"]')->text());
        self::assertCount(1, $linha->filter('td[data-col="nup"] > i.js-cadeado'));
        self::assertSame('', trim($linha->filter('td[data-col="acao"]')->text()), 'sem ação');
        self::assertStringNotContainsString('Ação sigilosa', (string) $this->client->getResponse()->getContent());

        $porProcesso = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=0700001');
        self::assertCount(0, $porProcesso->filter('tr[data-pasta-id="' . $oculta->getId() . '"]'), 'não casa pelo número do processo');
        $porAssunto = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=dano moral');
        self::assertCount(0, $porAssunto->filter('tr[data-pasta-id="' . $oculta->getId() . '"]'), 'não casa pelo assunto');
        $porCliente = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=joana');
        self::assertCount(1, $porCliente->filter('tr[data-pasta-id="' . $oculta->getId() . '"]'), 'casa pelo cliente');
        $porNumero = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . strtolower((string) $oculta->getNup()));
        self::assertCount(1, $porNumero->filter('tr[data-pasta-id="' . $oculta->getId() . '"]'), 'casa pelo número da pasta');

        $this->entrar($primario, $tenant);
        $doPrimario = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=0700001');
        self::assertCount(1, $doPrimario->filter('tr[data-pasta-id="' . $oculta->getId() . '"]'), 'o primário vê tudo');
    }

    #[TestDox('D1 CSV: a pasta oculta sai só com o número e o cliente')]
    public function testCsv(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant);
        $oculta = $this->criarPasta($tenant, 'JOANA SIGILOSA');
        $this->vincularProcesso($oculta, '0700002-22.2026.8.07.0001');
        $this->trancar($oculta, $primario);
        $this->entrar($ana, $tenant);

        $this->client->request('GET', '/expediente/exportar.csv?colunas=cliente,acao,resp,sit');

        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"' . $oculta->getNup() . '";"JOANA SIGILOSA";"";"";""', $csv);
        self::assertStringNotContainsString('Ação sigilosa', $csv);
    }

    #[TestDox('D1 processo: na tela do processo, a pasta oculta aparece com o número, o cliente e o cadeado, sem a ação')]
    public function testProcesso(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe', ['modules.expediente.view', 'resources.pasta.view', 'resources.processo.view']);
        $oculta = $this->criarPasta($tenant, 'JOANA SIGILOSA');
        $processo = $this->vincularProcesso($oculta, '0700003-33.2026.8.07.0001');
        $this->trancar($oculta, $primario);
        $this->entrar($ana, $tenant);

        $c = $this->client->request('GET', '/processos/' . $processo->getId());

        self::assertResponseIsSuccessful();
        $item = $c->filter('.pasta-vinculada');
        self::assertCount(1, $item->filter('i.exp-cadeado'));
        self::assertStringContainsString('JOANA SIGILOSA', $item->text());
        self::assertStringNotContainsString('Ação sigilosa', $item->text());
    }

    // ── D3 ─────────────────────────────────────────────────────────────

    #[TestDox('D3 Push global: a publicação do processo da pasta oculta some da lista e responde 404')]
    public function testPushGlobal(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe', ['modules.expediente.view', 'resources.pasta.view', 'modules.djen.view']);
        $oculta = $this->criarPasta($tenant);
        $this->vincularProcesso($oculta, '0700004-44.2026.8.07.0001');
        $this->trancar($oculta, $primario);
        $publicacao = new PublicacaoDjen();
        $publicacao->setTenant($tenant);
        $publicacao->setDjenId((string) random_int(100000, 999999));
        $publicacao->setNumeroProcesso('07000044420268070001');
        $publicacao->setSiglaTribunal('TJDFT');
        $publicacao->setTipoComunicacao('Intimação sigilosa');
        $publicacao->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-10'));
        $this->em()->persist($publicacao);
        $this->em()->flush();
        $this->entrar($ana, $tenant);

        $this->client->request('GET', '/push-processual');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Intimação sigilosa', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/push-processual/' . $publicacao->getId());
        self::assertResponseStatusCodeSame(404);

        $this->entrar($primario, $tenant);
        $this->client->request('GET', '/push-processual');
        self::assertStringContainsString('Intimação sigilosa', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('D3 Minhas Metas: a meta da pasta oculta some da lista de quem é responsável por ela')]
    public function testMinhasMetas(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant, 'Ana Equipe', ['modules.expediente.view', 'resources.pasta.view', 'modules.tarefas.view']);
        $oculta = $this->criarPasta($tenant);
        $livre  = $this->criarPasta($tenant);
        foreach ([[$oculta, 'Meta da pasta trancada'], [$livre, 'Meta da pasta livre']] as [$pasta, $titulo]) {
            $meta = new Tarefa();
            $meta->setTitulo($titulo);
            $meta->setDescricao('Descrição');
            $meta->setPasta($pasta);
            $meta->setTenant($tenant);
            $meta->setCriadoPor($primario);
            $meta->addResponsavel($ana);
            $this->em()->persist($meta);
        }
        $this->em()->flush();
        $this->trancar($oculta, $primario);
        $this->entrar($ana, $tenant);

        $this->client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Meta da pasta livre', $html);
        self::assertStringNotContainsString('Meta da pasta trancada', $html);

        // As contagens do topo também deixam de contar a meta da pasta oculta.
        $acesso   = static::getContainer()->get(AcessoAPasta::class);
        $ocultas  = $acesso->idsOcultosPara($ana, (int) $tenant->getId());
        $metas    = static::getContainer()->get(\App\Tarefa\Repository\TarefaRepository::class);
        self::assertSame(1, $metas->contarPorAba($ana, $ocultas)[\App\Tarefa\Enum\AbaMetas::RESPONSAVEL->value]);
        self::assertSame(2, $metas->contarPorAba($ana)[\App\Tarefa\Enum\AbaMetas::RESPONSAVEL->value], 'sem o cadeado seriam duas');
        self::assertSame(1, $metas->contarPainelMinhasMetas($ana, $ocultas)['sem_prazo']);
        $trilho = $metas->contarPessoasDoTrilho($ana, \App\Tarefa\Enum\AbaMetas::RESPONSAVEL, $ocultas);
        self::assertSame(1, $trilho[0]->abertas);
    }

    #[TestDox('D3 metas da equipe: a meta da pasta oculta para quem olha não entra na lista nem no total')]
    public function testMetasDaEquipe(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestora = $this->criarColaborador($tenant, 'Gestora', ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view']);
        $ana     = $this->criarColaborador($tenant, 'Ana Equipe', ['modules.expediente.view', 'resources.pasta.view']);
        $oculta  = $this->criarPasta($tenant);
        $livre   = $this->criarPasta($tenant);
        foreach ([[$oculta, 'Meta equipe trancada'], [$livre, 'Meta equipe livre']] as [$pasta, $titulo]) {
            $meta = new Tarefa();
            $meta->setTitulo($titulo);
            $meta->setDescricao('Descrição');
            $meta->setPasta($pasta);
            $meta->setTenant($tenant);
            $meta->setCriadoPor($primario);
            $meta->addResponsavel($ana);
            $this->em()->persist($meta);
        }
        $this->em()->flush();
        $this->trancar($oculta, $primario);
        $this->entrar($gestora, $tenant);

        $this->client->request('GET', '/tarefas/equipe?status=todas');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Meta equipe livre', $html);
        self::assertStringNotContainsString('Meta equipe trancada', $html);
    }

    // ── D4 ─────────────────────────────────────────────────────────────

    #[TestDox('D4 auditoria: a linha da pasta oculta aparece sem o conteúdo e sem desfazer; o primário vê tudo')]
    public function testAuditoria(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $auditora = $this->criarColaborador($tenant, 'Ana Auditora', ['modules.expediente.view', 'admin.audit.view']);
        $oculta = $this->criarPasta($tenant);
        $this->trancar($oculta, $primario);
        // Uma alteração de verdade, numa requisição (a linha da auditoria leva o escritório da sessão).
        $this->entrar($primario, $tenant);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $oculta->getId() . '/prioridade', ['prioridade' => 'urgente', '_token' => 'TOKEN_pasta_prioridade_' . $oculta->getId()]);
        self::assertResponseIsSuccessful();

        $this->entrar($auditora, $tenant);
        $html = (string) $this->client->request('GET', '/auditoria?entity=' . urlencode(Pasta::class))->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pasta trancada pelo usuário primário', $html);
        self::assertStringNotContainsString('<strong>prioridade</strong>', $html);
        self::assertStringNotContainsString('/desfazer', $html);

        $this->entrar($primario, $tenant);
        $html = (string) $this->client->request('GET', '/auditoria?entity=' . urlencode(Pasta::class))->html();
        self::assertStringContainsString('<strong>prioridade</strong>', $html);
    }

    #[TestDox('D4 estendida: linhas da meta, da resposta da meta e da análise da IA de pasta oculta saem sem o conteúdo, e o desfazer é recusado')]
    public function testAuditoriaDasEntidadesLigadasAPasta(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $auditora = $this->criarColaborador($tenant, 'Ana Auditora', ['modules.expediente.view', 'admin.audit.view']);
        $oculta   = $this->criarPasta($tenant);
        $meta = new Tarefa();
        $meta->setTitulo('Meta qualquer');
        $meta->setDescricao('Descrição');
        $meta->setPasta($oculta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($primario);
        $this->em()->persist($meta);
        $this->em()->flush();
        $this->trancar($oculta, $primario);

        $linhas = [
            'meta'     => $this->linhaDeAuditoria($tenant, Tarefa::class, (string) $meta->getId(), ['changes' => ['titulo' => ['from' => 'Meta qualquer', 'to' => 'CONTEUDO SECRETO DA META']]]),
            'resposta' => $this->linhaDeAuditoria($tenant, TarefaMensagem::class, '999999', ['after' => ['conteudo' => 'CONTEUDO SECRETO DA RESPOSTA', 'tarefa' => ['id' => (string) $meta->getId()]]], 'create'),
            'analise'  => $this->linhaDeAuditoria($tenant, AnaliseDeInteligencia::class, '999998', ['after' => ['resumo' => 'CONTEUDO SECRETO DA ANALISE', 'alvoTipo' => 'pasta', 'alvoId' => $oculta->getId()]], 'create'),
        ];
        $this->entrar($auditora, $tenant);

        $html = (string) $this->client->request('GET', '/auditoria')->html();
        self::assertResponseIsSuccessful();
        foreach (['META', 'RESPOSTA', 'ANALISE'] as $segredo) {
            self::assertStringNotContainsString('CONTEUDO SECRETO DA ' . $segredo, $html, $segredo);
        }
        self::assertSame(3, substr_count($html, 'Pasta trancada pelo usuário primário'));

        $this->client->request('POST', '/auditoria/' . $linhas['meta']->getId() . '/desfazer', ['_token' => 'TOKEN_audit_desfazer_' . $linhas['meta']->getId()]);
        self::assertResponseStatusCodeSame(403);

        $this->entrar($primario, $tenant);
        $html = (string) $this->client->request('GET', '/auditoria')->html();
        self::assertStringContainsString('CONTEUDO SECRETO DA META', $html, 'o primário vê tudo');
    }

    /** @param array<string, mixed> $diff */
    private function linhaDeAuditoria(\App\Entity\Tenant\Tenant $tenant, string $classe, string $id, array $diff, string $acao = 'update'): AuditLog
    {
        $linha = (new AuditLog())
            ->setAction($acao)
            ->setEntityClass($classe)
            ->setEntityId($id)
            ->setChanges(['diff' => $diff, 'context' => []])
            ->setTenantId($tenant->getId());
        $this->em()->persist($linha);
        $this->em()->flush();

        return $linha;
    }

    // ── D5 ─────────────────────────────────────────────────────────────

    #[TestDox('D5 imagem da peça: imagem citada por peça de pasta oculta é recusada; a de pasta livre é servida')]
    public function testImagemDaPeca(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant);
        $oculta = $this->criarPasta($tenant);
        $livre  = $this->criarPasta($tenant);
        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', true);
        $chaves = [];
        foreach ([[$oculta, 'img_oculta_' . uniqid() . '.png'], [$livre, 'img_livre_' . uniqid() . '.png']] as [$pasta, $imagem]) {
            $chaves[] = $chaveImagem = ChavesDePasta::imagemDoEditor($tenant, $imagem);
            $armazenamento->gravar($chaveImagem, FonteDeConteudo::deTexto($png));
            $peca = 'peca_' . uniqid() . '.html';
            $chaves[] = $chavePeca = ChavesDePasta::documentoPorNome((int) $tenant->getId(), $peca);
            $armazenamento->gravar($chavePeca, FonteDeConteudo::deTexto('<p><img src="/uploads/pastas/' . $imagem . '"></p>'));
            $doc = new PastaDocumento();
            $doc->setPasta($pasta);
            $doc->setTenant($tenant);
            $doc->setTitulo('Peça');
            $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
            $doc->setCaminhoArquivo($peca);
            $doc->setNomeOriginal('Peça.html');
            $doc->setMimeType('text/html');
            $doc->setTamanhoBytes(10);
            $this->em()->persist($doc);
            $imagens[] = $imagem;
        }
        $this->em()->flush();
        $this->trancar($oculta, $primario);
        $this->entrar($ana, $tenant);

        try {
            $this->client->request('GET', '/uploads/pastas/' . $imagens[0]);
            self::assertResponseStatusCodeSame(403);
            $this->client->request('GET', '/uploads/pastas/' . $imagens[1]);
            self::assertResponseIsSuccessful();
        } finally {
            foreach ($chaves as $chave) {
                $armazenamento->excluir($chave);
            }
        }
    }

    // ── O critério de DQL do AcessoAPasta ──────────────────────────────

    #[TestDox('restringirAoVisivel e niveisEmLote: só as pastas que a pessoa vê (sem cadeado, Visualizar ou Editar)')]
    public function testRestringirAoVisivel(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $livre = $this->criarPasta($tenant);
        $todos = $this->criarPasta($tenant);
        $verAna = $this->criarPasta($tenant);
        $semAna = $this->criarPasta($tenant);
        $this->trancar($todos, $primario);
        $this->trancar($verAna, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        $this->trancar($semAna, $primario, [[$this->criarColaborador($tenant, 'Bia'), NivelDeAcesso::Editar]]);
        $acesso = static::getContainer()->get(AcessoAPasta::class);

        $ids = function (\App\Entity\Auth\User $quem) use ($acesso, $tenant): array {
            $qb = static::getContainer()->get(PastaRepository::class)->createQueryBuilder('p')->select('p.id')
                ->andWhere('p.tenant = :t')->setParameter('t', $tenant)->orderBy('p.id');
            $acesso->restringirAoVisivel($qb, 'p', $quem);

            return array_map('intval', $qb->getQuery()->getSingleColumnResult());
        };

        self::assertSame([(int) $livre->getId(), (int) $verAna->getId()], $ids($ana));
        self::assertSame([(int) $todos->getId(), (int) $semAna->getId()], self::ordenados($acesso->idsOcultosPara($ana, (int) $tenant->getId())), 'para SQL nativo');
        self::assertSame([], $acesso->idsOcultosPara($primario, (int) $tenant->getId()), 'o primário não tem pasta oculta');
        $niveis = $acesso->niveisEmLote($ana, [$livre, $todos, $verAna, $semAna]);
        self::assertSame(['editar', 'oculto', 'visualizar', 'oculto'], array_values(array_map(static fn (NivelDeAcesso $n): string => $n->value, $niveis)));
    }

    /** @param list<int> $ids @return list<int> */
    private static function ordenados(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
