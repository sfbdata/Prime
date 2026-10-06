<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lote L10: ONDE o visor em tela cheia nasce e como o cabeçalho dele é arranjado (desenho
 * 02 - EXPEDIENTES 1.2.3, dc L299-329). Filho direto em tudo (precedente:
 * PastaExploradorArranjoTelaTest): pega a ordem dos grupos e um `</div>` sobrando no partial.
 * O JS leva o visor para o <body> ao carregar; aqui é o markup servido, antes disso.
 */
#[CoversClass(PastaController::class)]
final class PastaExploradorVisorArranjoTelaTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Visor Explorador ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_pex_visor_' . uniqid() . '@test.com');
        $user->setFullName('Admin Visor Explorador');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, ?string $nup): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        if ($nup !== null) {
            $pasta->setNup($nup);
        }
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, string $nomeOriginal): void
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setTitulo($nomeOriginal);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/' . $nomeOriginal);
        $doc->setNomeOriginal($nomeOriginal);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function novoClientePf(Tenant $tenant, string $nome): ClientePF
    {
        $c = new ClientePF();
        $c->setEmail('pex.visor.' . uniqid() . '@test.com');
        $c->setCep('80000-000');
        $c->setEndereco('Rua Um, 1');
        $c->setCidade('Curitiba');
        $c->setEstado('PR');
        $c->setTenant($tenant);
        $c->setNomeCompleto($nome);
        $c->setCpf('111.111.111-11');
        $c->setRg('12.345.678-9');
        $c->setRgOrgaoExpedidor('SSP');

        return $c;
    }

    /** @return array{Crawler, Pasta} */
    private function telaComUmArquivo(?string $nup = 'NUP-PEX-VIS', ?string $cliente = null): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, $nup === null ? null : $nup . '-' . uniqid());
        if ($cliente !== null) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $c  = $this->novoClientePf($tenant, $cliente);
            $em->persist($c);
            $pasta->addCliente($c); // o primeiro vinculado vira o principal
            $em->flush();
        }
        $this->criarDocumento($pasta, $tenant, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);

        return [$this->abrir($client, $pasta), $pasta];
    }

    #[TestDox('o visor nasce UMA vez, oculto, como diálogo modal dentro do corpo do explorador — e o #previewDocModal continua na página para as outras abas')]
    public function testVisorNasceOcultoNoExplorador(): void
    {
        [$crawler] = $this->telaComUmArquivo();

        self::assertCount(1, $crawler->filter('#pexVisor'));
        $visor = $crawler->filter('#pexExplorador > .pex-corpo > #pexVisor.pex-visor[role="dialog"][aria-modal="true"][tabindex="-1"]');
        self::assertCount(1, $visor);
        self::assertNotNull($visor->attr('hidden'), 'fechado até alguém abrir um arquivo');
        self::assertSame('Visualizar arquivo', $visor->attr('aria-label'), 'rótulo do dc');
        // Não é `.modal`: o teste dos modais fora do painel animado não pode passar a acusá-lo.
        self::assertCount(0, $crawler->filter('#pexVisor.modal'));
        // O rodapé continua fechando o cartão: o visor não é empurrado para depois dele.
        self::assertCount(1, $crawler->filter('#pexExplorador > .pex-rodape:last-child'));

        // O modal de sempre (Dados, Financeiro, outras telas) fica intacto, com os 3 ids do contrato.
        self::assertCount(1, $crawler->filter('#previewDocModal #previewDocConteudo'));
        self::assertCount(1, $crawler->filter('#previewDocModal #previewDocNome'));
        self::assertCount(1, $crawler->filter('#previewDocModal #previewDocDownload'));

        self::assertCount(1, $crawler->filter('.pex-corpo > script[src*="js/pasta-explorador-visor.js"][defer]'));
    }

    #[TestDox('cabeçalho do visor na ordem do dc: ícone, nome+meta, ← n de N →, zoom, Baixar · Imprimir · Abrir em nova aba · Fechar')]
    public function testCabecalhoNaOrdemDoDesenho(): void
    {
        [$crawler] = $this->telaComUmArquivo();

        self::assertCount(1, $crawler->filter('#pexVisor > .pex-visor-cab:first-child + .pex-visor-area + #pexVisorAviso[role="status"][hidden]'));
        self::assertCount(1, $crawler->filter(
            '.pex-visor-cab > #pexVisorIcone.pex-visor-ico:first-child + .pex-visor-titulo + .pex-visor-nav + #pexVisorZoom.pex-visor-zoom + .pex-visor-acoes:last-child'
        ));
        self::assertCount(1, $crawler->filter('.pex-visor-titulo > #pexVisorNome:first-child + #pexVisorMeta'));
        self::assertCount(1, $crawler->filter('.pex-visor-nav > #pexVisorAnterior:first-child + #pexVisorPosicao + #pexVisorProximo'));
        self::assertCount(1, $crawler->filter('#pexVisorAnterior > .bi-arrow-left'));
        self::assertCount(1, $crawler->filter('#pexVisorProximo > .bi-arrow-right'));
        self::assertCount(1, $crawler->filter('#pexVisorZoom > #pexVisorMenos:first-child + #pexVisorZoomTxt + #pexVisorMais'));
        self::assertCount(1, $crawler->filter('#pexVisorMenos > .bi-zoom-out'));
        self::assertCount(1, $crawler->filter('#pexVisorMais > .bi-zoom-in'));
        self::assertSame('100%', trim($crawler->filter('#pexVisorZoomTxt')->text()));

        self::assertSame(
            ['pexVisorBaixar', 'pexVisorImprimir', 'pexVisorNovaAba', 'pexVisorFechar'],
            $crawler->filter('.pex-visor-acoes > button')->each(fn (Crawler $n) => (string) $n->attr('id'))
        );
        self::assertSame(
            ['Baixar', 'Imprimir', 'Abrir em nova aba', 'Fechar (Esc)'],
            $crawler->filter('.pex-visor-acoes > button')->each(fn (Crawler $n) => (string) $n->attr('title'))
        );
        self::assertSame(
            ['bi bi-download', 'bi bi-printer', 'bi bi-box-arrow-up-right', 'bi bi-x-lg'],
            $crawler->filter('.pex-visor-acoes > button > i')->each(fn (Crawler $n) => (string) $n->attr('class'))
        );
        self::assertCount(1, $crawler->filter('.pex-visor-acoes > #pexVisorFechar.pex-visor-fechar:last-child'));
        // Todo botão é <button type="button">: nenhum envia formulário.
        self::assertCount(0, $crawler->filter('#pexVisor button:not([type="button"])'));
    }

    #[TestDox('a área de conteúdo nasce vazia (quem preenche é o VisualizadorDocumento) e NÃO há área de soltar arquivo (S-7)')]
    public function testAreaVaziaESemSoltar(): void
    {
        [$crawler] = $this->telaComUmArquivo();

        $alvo = $crawler->filter('#pexVisor > .pex-visor-area#pexVisorArea > #pexVisorAlvo.pex-visor-alvo');
        self::assertCount(1, $alvo);
        self::assertSame('', trim($alvo->html()));
        self::assertStringNotContainsString('Arraste', $crawler->filter('#pexVisor')->text(''));
        self::assertStringNotContainsString('Solte', $crawler->filter('#pexVisor')->text(''));
    }

    #[TestDox('a meta "Pasta N · Cliente" sai do cadastro: rótulo = NUP; pasta sem cliente vinculado não inventa nome')]
    public function testDadosDaPastaParaAMeta(): void
    {
        [$crawler, $pasta] = $this->telaComUmArquivo();

        $visor = $crawler->filter('#pexVisor');
        // O setter grava em maiúsculas: compara com o getter, não com o literal digitado.
        self::assertSame($pasta->getNup(), $visor->attr('data-pasta-rotulo'));
        self::assertSame('', $visor->attr('data-pasta-cliente'), 'sem cliente principal: só "Pasta N"');
    }

    #[TestDox('com cliente principal vinculado, a meta leva o nome de exibição DELE (do cadastro)')]
    public function testClientePrincipalNaMeta(): void
    {
        [$crawler, $pasta] = $this->telaComUmArquivo('NUP-PEX-VIS', 'Ana Visor Principal');

        $principal = $pasta->getClientePrincipal();
        self::assertNotNull($principal);
        // O setter grava em maiúsculas: compara com o getter, não com o literal digitado.
        self::assertSame($principal->getNomeExibicao(), $crawler->filter('#pexVisor')->attr('data-pasta-cliente'));
        self::assertNotSame('', $crawler->filter('#pexVisor')->attr('data-pasta-cliente'));
    }

    // Sem caso "pasta sem NUP": `pasta.nup` é NOT NULL no banco, então o fallback
    // `pasta.nup ?? pasta.id` do template nunca dispara — testá-lo exigiria um estado impossível.
}
