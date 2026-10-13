<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Aba Financeiro, Ciclo 7 (lote S7-FIN):
 *
 * - C7-FPN-01: a escrita que o servidor recusa não aparece (convenção PAS-014/PAS-025c, a mesma
 *   de Detalhes no C6-PAS-02). Toda rota de escrita da aba exige EDIT na pasta, e na lápide o
 *   `PastaSomenteLeituraListener` recusa todo POST. Quem só vê a pasta, e a lápide, não recebem
 *   compositor, Responder, Editar/Excluir (nem "· bloqueado"), lápis de honorários, selos
 *   clicáveis, Adicionar/Renomear/Excluir arquivo nem a escrita de Pagamentos. Ficam ver, copiar,
 *   baixar e imprimir. Na lápide entra a nota de só leitura (a de Detalhes).
 * - C6-FPN-05: o cartão leva `data-janela-ate` (epoch do servidor) e o envio devolve `criadaEmEpoch`.
 * - Menção do Registro: a edição de um card do Registro diz a URL da lista do "@" explicitamente.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaFinanceiroEscritaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function criarRegistro(Pasta $pasta, Tenant $tenant, User $autor, string $quando = 'now'): PastaObservacaoFinanceira
    {
        $obs = (new PastaObservacaoFinanceira())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('Honorário combinado em três parcelas.');
        (new \ReflectionProperty(PastaObservacaoFinanceira::class, 'criadaEm'))->setValue($obs, new \DateTimeImmutable($quando));
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo('Contrato de honorários.pdf');
        $doc->setCategoria(PastaDocumento::CATEGORIA_CONTRATO);
        $doc->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf');
        $doc->setNomeOriginal('contrato.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(14);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    private function criarPagamento(Pasta $pasta, Tenant $tenant): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setDescricao('Entrada');
        $pagamento->setValor('1000.00');
        $pagamento->setVencimento(new \DateTimeImmutable('2026-09-10'));
        $this->em()->persist($pagamento);
        $this->em()->flush();

        return $pagamento;
    }

    /** O painel da aba, para os seletores não pegarem nada de outra aba. */
    private function painel(Crawler $crawler): Crawler
    {
        $painel = $crawler->filter('#pastaTabsContent > #financeiro');
        self::assertCount(1, $painel);

        return $painel;
    }

    /** Toda a escrita da aba que o leitor e a lápide NÃO podem receber. */
    private const ESCRITA = [
        '#formFinanceiroObservacao', '#financeiroObsConteudo', '#btnEnviarObsFinanceiro',
        '.btn-responder-obs', '.js-fin-obs-responder',
        '.btn-editar-obs', '.btn-excluir-obs', '.js-fin-obs-editar', '.js-fin-obs-excluir',
        '#financeiro-situacao-btn', '#financeiro-probono-btn',
        '#financeiro-valor-causa-lapis', '#financeiro-valor-causa-input',
        '#financeiro-upload-input', '.ps-fin-adicionar', '#financeiro-reduzir-btn',
        '.btn-renomear-doc', '.btn-excluir-doc',
        '.ps-fin-btn-add', '[data-bs-target="#modalNovoPagamento"]', '.js-pag-editar', '#psPagamentosConcluir',
        '.js-pag-entrar-edicao', '[data-acao]', '.ps-pag-excluir', '.ps-pag-valor-editar',
    ];

    private function assertSemEscrita(Crawler $painel): void
    {
        foreach (self::ESCRITA as $seletor) {
            self::assertCount(0, $painel->filter($seletor), $seletor . ' não pode aparecer para quem não escreve');
        }
        // Nem "· bloqueado" com motivo de autoria/prazo: o motivo seria permissão.
        self::assertStringNotContainsString('bloqueado', $painel->filter('#financeiroObsMenu')->text(''));
        self::assertCount(0, $painel->filter('#financeiroObsMenu > .ps-fin-menu-sep'));
    }

    /** Leitura que fica: ver o estado, copiar, baixar, imprimir. */
    private function assertLeituraFica(Crawler $painel, PastaDocumento $doc, PastaObservacaoFinanceira $obs): void
    {
        self::assertCount(1, $painel->filter('#financeiro-obs-' . $obs->getId()));
        self::assertCount(1, $painel->filter('#financeiroRelatorio > #financeiroObsMenu > .js-fin-obs-copiar'));
        self::assertCount(1, $painel->filter('.ps-fin-faixa > .ps-fin-bloco > .ps-fin-corpo--selo > span#financeiro-situacao-selo.ps-fin-selo'));
        self::assertCount(1, $painel->filter('.ps-fin-faixa > .ps-fin-bloco > .ps-fin-corpo--selo > span#financeiro-probono-selo.ps-fin-selo'));
        self::assertCount(1, $painel->filter('#financeiro-valor-causa-bloco > .ps-fin-corpo > #financeiro-valor-causa:not([role])'));

        $menuDoc = $painel->filter('#financeiro-doc-' . $doc->getId() . ' #financeiro-doc-menu-' . $doc->getId());
        self::assertCount(1, $menuDoc);
        self::assertCount(1, $menuDoc->filter('[data-bs-target="#previewDocModal"]'), 'Abrir fica');
        self::assertCount(1, $menuDoc->filter('a[href*="/download"]'), 'Baixar fica');
        self::assertCount(1, $menuDoc->filter('.js-fin-doc-copiar-nome'), 'Copiar nome fica');
        self::assertCount(0, $menuDoc->filter('.ps-fin-menu-sep'));

        $menuPag = $painel->filter('#psPagamentos > .ps-card-cab > #psPagamentosMenu');
        self::assertCount(1, $menuPag);
        self::assertCount(1, $menuPag->filter('.js-pag-ver-todos'));
        self::assertCount(1, $menuPag->filter('.js-pag-copiar-resumo'));
        self::assertCount(1, $menuPag->filter('.js-pag-imprimir-extrato'));
        // A linha mostra o estado sem ser gatilho de menu.
        self::assertCount(1, $painel->filter('#psPagamentosCorpo > .ps-pag-linha:not([aria-controls]) > .ps-pag-direita > span.ps-pag-selo'));
        self::assertCount(1, $painel->filter('#psPagamentosCorpo > .ps-pag-total > .ps-pag-total-linha > span.ps-pag-recebido'));
    }

    #[TestDox('C7-FPN-01: quem só vê a pasta não recebe escrita no Financeiro — nem no registro que ele mesmo escreveu, dentro da janela — e segue vendo, copiando, baixando e imprimindo')]
    public function testLeitorNaoRecebeEscrita(): void
    {
        $client           = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $leitor           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta            = $this->criarPasta($tenant);
        $alheio           = $this->criarRegistro($pasta, $tenant, $admin, '-3 minutes');
        $proprio          = $this->criarRegistro($pasta, $tenant, $leitor, '-2 minutes');
        $doc              = $this->criarDocumento($pasta, $tenant);
        $this->criarPagamento($pasta, $tenant);

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $painel = $this->painel($crawler);
        $this->assertSemEscrita($painel);
        self::assertCount(0, $painel->filter('#financeiroObsSomenteLeitura'), 'pasta viva: sem nota inventada (a da lápide é outro caso)');
        $this->assertLeituraFica($painel, $doc, $alheio);
        self::assertCount(1, $painel->filter('#financeiro-obs-' . $proprio->getId()));
        self::assertCount(0, $painel->filter('#financeiro-obs-' . $proprio->getId() . ' .ps-anotacao-acoes'));
    }

    #[TestDox('C7-FPN-01: sem registro nem lançamento, quem só vê não lê os convites "Anote acima…" / "Use Adicionar pagamento" e não recebe um ⋮ de Pagamentos vazio')]
    public function testLeitorVazioSemConvites(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $leitor     = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta      = $this->criarPasta($tenant);

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $painel = $this->painel($crawler);
        self::assertCount(1, $painel->filter('#financeiroObsLista > #financeiroObsVazio > .ps-vazio-titulo'));
        self::assertCount(0, $painel->filter('#financeiroObsLista > #financeiroObsVazio > .ps-vazio-nota'));
        self::assertSame('Nenhum lançamento.', trim($painel->filter('#psPagamentosCorpo > .ps-pag-vazio-linha')->text()));
        self::assertCount(0, $painel->filter('#psPagamentosMenuBtn, #psPagamentosMenu'));
    }

    #[TestDox('C7-FPN-01: quem edita a pasta continua recebendo toda a escrita do Financeiro')]
    public function testQuemEditaRecebeEscrita(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarRegistro($pasta, $tenant, $user, '-5 minutes');
        $doc             = $this->criarDocumento($pasta, $tenant);
        $this->criarPagamento($pasta, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $painel = $this->painel($crawler);
        $id     = $obs->getId();
        self::assertCount(1, $painel->filter('#financeiroRelatorio > .ps-compositor > #formFinanceiroObservacao'));
        self::assertCount(0, $painel->filter('#financeiroObsSomenteLeitura'));
        self::assertCount(1, $painel->filter("#financeiro-obs-{$id} > .ps-anotacao-topo > .btn-responder-obs"));
        self::assertCount(1, $painel->filter("#financeiro-obs-{$id} > .ps-anotacao-topo > .ps-anotacao-acoes > .btn-editar-obs"));
        self::assertCount(1, $painel->filter("#financeiro-obs-{$id} > .ps-anotacao-topo > .ps-anotacao-acoes > .btn-excluir-obs"));
        self::assertCount(1, $painel->filter('#financeiroRelatorio > #financeiroObsMenu > .js-fin-obs-responder'));
        self::assertCount(1, $painel->filter('#financeiroRelatorio > #financeiroObsMenu > .ps-fin-menu-sep'));
        self::assertCount(1, $painel->filter('#financeiroRelatorio > #financeiroObsMenu > .js-fin-obs-editar'));
        self::assertCount(1, $painel->filter('#financeiroRelatorio > #financeiroObsMenu > .js-fin-obs-excluir'));
        self::assertCount(1, $painel->filter('.ps-fin-corpo--selo > button#financeiro-situacao-btn'));
        self::assertCount(1, $painel->filter('.ps-fin-corpo--selo > button#financeiro-probono-btn'));
        self::assertCount(1, $painel->filter('#financeiro-valor-causa-bloco > .ps-fin-rotulo-linha > #financeiro-valor-causa-lapis'));
        self::assertCount(1, $painel->filter('#financeiro-valor-causa-bloco > .ps-fin-corpo > #financeiro-valor-causa-input'));
        self::assertCount(1, $painel->filter('[data-trilho="arquivos"] > .ps-card-cab > .ps-fin-adicionar > #financeiro-upload-input'));
        self::assertCount(1, $painel->filter('#financeiro-doc-menu-' . $doc->getId() . ' > .btn-renomear-doc'));
        self::assertCount(1, $painel->filter('#financeiro-doc-menu-' . $doc->getId() . ' > .btn-excluir-doc'));
        self::assertCount(1, $painel->filter('[data-trilho="pagamentos"] > .ps-fin-rodape > button.ps-fin-btn-add'));
        self::assertCount(1, $painel->filter('#psPagamentosMenu > .js-pag-editar'));
        self::assertCount(1, $painel->filter('#psPagamentosCorpo > .ps-pag-linha[aria-controls] > .ps-pag-direita > button.ps-pag-selo[data-acao="quitacao"]'));
        self::assertCount(1, $painel->filter('#psPagamentosCorpo > .ps-pag-linha > .ps-pag-excluir[data-acao="excluir"]'));
        self::assertCount(1, $painel->filter('#psPagamentosCorpo > .ps-pag-total > .ps-pag-total-linha > button.js-pag-entrar-edicao'));
    }

    #[TestDox('C7-FPN-01: na lápide nem o autor dentro da janela recebe escrita no Financeiro; entra a nota de só leitura')]
    public function testLapideSemEscrita(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarRegistro($pasta, $tenant, $user, '-5 minutes');
        $doc             = $this->criarDocumento($pasta, $tenant);
        $this->criarPagamento($pasta, $tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $painel = $this->painel($crawler);
        $this->assertSemEscrita($painel);
        $nota = $painel->filter('#financeiroRelatorio > .ps-compositor--leitura#financeiroObsSomenteLeitura');
        self::assertCount(1, $nota);
        self::assertStringContainsString('Pasta excluída', $nota->text());
        $this->assertLeituraFica($painel, $doc, $obs);
    }

    #[TestDox('C7-FPN-01 (isolamento): usuário de outro escritório não abre a pasta nem vê o Financeiro dela')]
    public function testOutroEscritorioNaoVe(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaA            = $this->criarPasta($tenantA);
        $obs               = $this->criarRegistro($pastaA, $tenantA, $userA, '-2 minutes');
        $idPasta           = (int) $pastaA->getId();
        $idObs             = (int) $obs->getId();

        $this->logarComTenant($client, $userB, $tenantB);
        // Sem o clear() a pasta fica no cache do Doctrine e o TenantFilter não tem o que filtrar.
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $idPasta);

        self::assertResponseStatusCodeSame(404);
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('financeiro-obs-' . $idObs, $corpo);
        self::assertStringNotContainsString('Honorário combinado em três parcelas.', $corpo);
    }

    #[TestDox('C6-FPN-05: o cartão do registro leva data-janela-ate = criadaEm + 15 min (epoch do servidor)')]
    public function testCartaoLevaOFimDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarRegistro($pasta, $tenant, $user, '-5 minutes');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $cartao = $crawler->filter('#financeiroObsLista > article.ps-anotacao#financeiro-obs-' . $obs->getId());
        self::assertCount(1, $cartao);
        self::assertSame(
            (string) ($obs->getCriadaEm()->getTimestamp() + JanelaDeEdicaoDeComentario::MINUTOS * 60),
            $cartao->attr('data-janela-ate'),
        );
    }

    /** @return array<string, mixed> o JSON do `pasta_financeiro_observacao_enviar` (201) */
    private function enviarRegistro(KernelBrowser $client, Pasta $pasta): array
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        $token   = $crawler->filter('#formFinanceiroObservacao input[name="_token"]')->attr('value');

        $client->request('POST', '/pasta/' . $pasta->getId() . '/financeiro/observacao', [
            '_token'   => $token,
            'conteudo' => '<p>Registro novo</p>',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(201);

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    #[TestDox('C6-FPN-05: o envio do Relatório financeiro devolve `criadaEmEpoch` inteiro, o mesmo instante gravado, para o JS calcular a janela')]
    public function testEnvioDevolveOEpochDaCriacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $antes = time();
        $data  = $this->enviarRegistro($client, $pasta);

        self::assertIsInt($data['criadaEmEpoch']);
        self::assertGreaterThanOrEqual($antes - 5, $data['criadaEmEpoch']);
        self::assertLessThanOrEqual(time() + 5, $data['criadaEmEpoch']);

        $obs = $this->em()->find(PastaObservacaoFinanceira::class, $data['id']);
        self::assertNotNull($obs);
        self::assertSame($obs->getCriadaEm()->getTimestamp(), $data['criadaEmEpoch'], 'a mesma base do `|date(\'U\')` do Twig');
    }

    #[TestDox('Menção: a edição de um card do Registro liga o "@" à lista do REGISTRO, explicitamente, e não ao primeiro [data-mencionaveis-url] da página')]
    public function testEdicaoDoRegistroDizAUrlDasMencoes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $html   = (string) $client->getResponse()->getContent();
        $url    = static::getContainer()->get('router')->generate('pasta_mencionaveis', ['id' => $pasta->getId()]);
        $ligar  = strpos($html, 'PastaMencoes.ligar(textareaEd, edicao.mencoes)');
        self::assertNotFalse($ligar);
        $antes  = substr($html, max(0, $ligar - 400), 400);
        self::assertStringContainsString("textareaEd._mencoesUrl = '" . $url . "';", $antes);
        self::assertStringNotContainsString('origem=financeiro', $antes);
    }
}
