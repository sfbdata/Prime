<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\ConferenciaDeAnexosDoChecklist;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * O checklist de documentação na TELA (lote L3): itens em grade como no desenho (dc L2172-2179),
 * o selo "sem anexo" com a regra real do servidor, a pendência da aba Documentos que sai dele, e
 * os assets próprios (o JS saiu do inline da pasta/show).
 *
 * Arranjo por combinador de FILHO DIRETO: distingue "a caixa está dentro do botão da linha" de
 * "existe uma caixa em algum lugar". Estilo (19px, cor, risco) é do smoke. Os títulos passam pelo
 * setter, que grava em MAIÚSCULAS: as asserções comparam com o getter.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(ConferenciaDeAnexosDoChecklist::class)]
final class PastaChecklistTelaTest extends JusPrimeWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(string $sufixo = ''): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Checklist Tela ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_ck_tela_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName('Admin Checklist Tela');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('NUP-CK-TELA-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function item(Pasta $pasta, Tenant $tenant, string $titulo, bool $concluido, int $ordem): PastaChecklistItem
    {
        $item = (new PastaChecklistItem())
            ->setPasta($pasta)
            ->setTenant($tenant)
            ->setTitulo($titulo)
            ->setConcluido($concluido)
            ->setOrdem($ordem);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function anexar(Pasta $pasta, Tenant $tenant, string $titulo, string $nomeOriginal = 'arquivo.pdf'): void
    {
        $doc = (new PastaDocumento())
            ->setTenant($tenant)
            ->setPasta($pasta)
            ->setTitulo($titulo)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf')
            ->setNomeOriginal($nomeOriginal)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10);
        $this->em()->persist($doc);
        $this->em()->flush();
    }

    private function abrir(KernelBrowser $client, int $pastaId): Crawler
    {
        // Sem o clear a pasta segue no cache do Doctrine sem os documentos recém-anexados.
        $this->em()->clear();
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function linha(Crawler $crawler, PastaChecklistItem $item): Crawler
    {
        return $crawler->filter('#checklistLista > li.checklist-item[data-item-id="' . $item->getId() . '"]');
    }

    #[TestDox('cada item é uma linha-botão com a caixa e o título dentro, e as peças do modo de edição como irmãs (dc L2172-2179)')]
    public function testArranjoDosItens(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $feito           = $this->item($pasta, $tenant, 'Procuração', true, 0);
        $pendente        = $this->item($pasta, $tenant, 'Certidão de casamento', false, 1);
        $pastaId         = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pastaId);

        self::assertSame(2, $crawler->filter('#pexChecklist > .pex-ck-corpo > #checklistLista > li.checklist-item')->count());
        foreach ([$feito, $pendente] as $item) {
            $li = $this->linha($crawler, $item);
            self::assertSame(1, $li->count());
            self::assertSame(1, $li->filter('li > button.js-checklist-toggle > .pex-ck-caixa > i.bi-check2')->count(), 'caixa de marcar dentro do botão da linha');
            self::assertSame(1, $li->filter('li > button.js-checklist-toggle > .checklist-item-titulo')->count());
            self::assertSame(1, $li->filter('li > .checklist-drag-handle.d-none')->count(), 'alça para reordenar (só o sistema tem), escondida fora do modo de edição');
            self::assertSame(1, $li->filter('li > .checklist-item-input.d-none')->count(), 'renomear inline (só o sistema tem)');
            self::assertSame(1, $li->filter('li > button.js-checklist-excluir.d-none > i.bi-trash3')->count(), 'lixeira do modo de edição');
            self::assertNotEmpty($li->attr('data-csrf-item'));
        }

        $liFeito = $this->linha($crawler, $feito);
        self::assertSame('1', $liFeito->attr('data-concluido'));
        self::assertStringContainsString('concluido', (string) $liFeito->attr('class'));
        self::assertSame('true', $liFeito->filter('.js-checklist-toggle')->attr('aria-pressed'));
        self::assertSame($feito->getTitulo(), trim($liFeito->filter('.checklist-item-titulo')->text()));

        $liPendente = $this->linha($crawler, $pendente);
        self::assertSame('0', $liPendente->attr('data-concluido'));
        self::assertStringNotContainsString('concluido', (string) $liPendente->attr('class'));
        self::assertSame('false', $liPendente->filter('.js-checklist-toggle')->attr('aria-pressed'));

        // Os ícones antigos (círculo verde / X vermelho) saíram: o desenho tem caixa.
        self::assertSame(0, $crawler->filter('#checklistLista .bi-x-circle-fill, #checklistLista .bi-check-circle-fill')->count());
    }

    #[TestDox('cabeçalho: Sugerir documentos antes das ações; corpo: faixa de adicionar ANTES dos itens (dc L2166)')]
    public function testCabecalhoEFaixaDeAdicionar(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        self::assertSame(1, $crawler->filter('#pexChecklist > .pex-ck-cab > #btnDocumentosSugeridos ~ #btnChecklistEditar ~ #btnChecklistAdicionar')->count());
        self::assertSame(1, $crawler->filter('#pexChecklist > .pex-ck-corpo > #checklistFormAdicionar.d-none ~ #checklistLista')->count());

        $form = $crawler->filter('#checklistFormAdicionar');
        self::assertSame('Nome do documento, ex.: Certidão de casamento', $form->filter('#checklistNovoTitulo')->attr('placeholder'));
        self::assertSame('Adicionar', trim($form->filter('#btnChecklistSalvarNovo')->text()));
        self::assertSame(1, $form->filter('#checklistErroNovo')->count());

        // O vazio continua dizendo o que fazer.
        self::assertSame(1, $crawler->filter('#checklistLista > #checklistVazio')->count());
    }

    #[TestDox('selo "sem anexo": item marcado SEM arquivo correspondente mostra; com arquivo, não; pendente sem arquivo tem o selo escondido')]
    public function testSeloSemAnexo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $comArquivo      = $this->item($pasta, $tenant, 'Procuração', true, 0);
        $semArquivo      = $this->item($pasta, $tenant, 'Contrato de honorários', true, 1);
        $pendente        = $this->item($pasta, $tenant, 'Comprovante de residência', false, 2);
        $this->anexar($pasta, $tenant, 'Documento escaneado', 'procuracao_assinada.pdf');
        $pastaId = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pastaId);

        $li = $this->linha($crawler, $comArquivo);
        self::assertSame('1', $li->attr('data-tem-anexo'), 'o nome original do arquivo casa com "procura"');
        self::assertSame(0, $li->filter('.pex-ck-sem-anexo')->count());

        $li   = $this->linha($crawler, $semArquivo);
        $selo = $li->filter('li > .js-checklist-toggle > .pex-ck-sem-anexo');
        self::assertSame('0', $li->attr('data-tem-anexo'));
        self::assertSame(1, $selo->count(), 'o selo mora na linha, ao lado do título (dc L2175)');
        self::assertNull($selo->attr('hidden'), 'marcado e sem arquivo: o selo aparece');
        self::assertSame('sem anexo', trim($selo->text()));
        self::assertStringContainsString('conferência por regras', (string) $selo->attr('title'), 'o title diz que é regra de nome, não prova');

        $li   = $this->linha($crawler, $pendente);
        $selo = $li->filter('.pex-ck-sem-anexo');
        self::assertSame('0', $li->attr('data-tem-anexo'));
        self::assertSame(1, $selo->count(), 'existe para o JS mostrar quando o item for marcado');
        self::assertNotNull($selo->attr('hidden'), 'pendente sem arquivo é o esperado: nada a acusar');
    }

    #[TestDox('pendência da aba Documentos: acende com item marcado sem anexo e o title diz quantos')]
    public function testPendenciaDaAbaComItemMarcadoSemAnexo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->item($pasta, $tenant, 'Contrato de honorários', true, 0);
        $this->item($pasta, $tenant, 'Declaração de hipossuficiência', true, 1);
        $this->item($pasta, $tenant, 'Procuração', false, 2);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        $aba = $crawler->filter('#pastaTabs > #documentos-tab.ps-aba--pend');
        self::assertSame(1, $aba->count(), 'a aba Documentos carrega a marca de pendência');
        self::assertSame(1, $crawler->filter('#documentos-tab > .ps-aba-pend')->count());
        self::assertStringContainsString('2 itens do checklist marcados sem anexo', (string) $aba->attr('title'), 'o pendente sem arquivo não conta');
    }

    #[TestDox('pendência da aba Documentos: some quando todo item marcado tem arquivo (o filtro remove tudo)')]
    public function testSemPendenciaQuandoTudoTemArquivo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->item($pasta, $tenant, 'Contrato de honorários', true, 0);
        $this->item($pasta, $tenant, 'Declaração de hipossuficiência', true, 1);
        $this->anexar($pasta, $tenant, 'Contrato assinado');
        $this->anexar($pasta, $tenant, 'Pedido de gratuidade');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        self::assertSame(0, $crawler->filter('#pastaTabs > #documentos-tab.ps-aba--pend')->count());
        self::assertSame(0, $crawler->filter('#documentos-tab > .ps-aba-pend')->count());
        self::assertSame(0, $crawler->filter('#checklistLista .pex-ck-sem-anexo')->count());
    }

    #[TestDox('arquivo de OUTRO escritório pendurado na pasta não conta como anexo do checklist')]
    public function testArquivoDeOutroEscritorioNaoContaComoAnexo(): void
    {
        $client          = static::createClient();
        [$user, $tA]     = $this->criarUsuarioAdmin('a');
        [, $tB]          = $this->criarUsuarioAdmin('b');
        $pasta           = $this->criarPasta($tA);
        $item            = $this->item($pasta, $tA, 'Procuração', true, 0);
        // Linha corrompida de propósito: documento do escritório B apontando para a pasta de A.
        $this->anexar($pasta, $tB, 'Procuração do outro escritório', 'procuracao.pdf');
        $pastaId = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tA);

        $crawler = $this->abrir($client, $pastaId);

        self::assertSame('0', $this->linha($crawler, $item)->attr('data-tem-anexo'));
        self::assertSame(1, $crawler->filter('#pastaTabs > #documentos-tab.ps-aba--pend')->count());
    }

    #[TestDox('pasta de outro escritório: 404, e nada do checklist dela vaza')]
    public function testPastaDeOutroEscritorio(): void
    {
        $client      = static::createClient();
        [$user, $tA] = $this->criarUsuarioAdmin('a');
        [, $tB]      = $this->criarUsuarioAdmin('b');
        $pastaB      = $this->criarPasta($tB);
        $this->item($pastaB, $tB, 'Procuração secreta', true, 0);
        $idB = (int) $pastaB->getId();
        $this->logarComTenant($client, $user, $tA);

        $this->em()->clear();
        $client->request('GET', "/pasta/{$idB}");

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('PROCURAÇÃO SECRETA', (string) $client->getResponse()->getContent());
    }

    #[TestDox('a tela carrega o JS e a folha próprios do checklist, uma vez cada')]
    public function testAssetsDoChecklist(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        self::assertSame(1, $crawler->filter('script[src*="js/pasta-checklist.js"]')->count());
        self::assertSame(1, $crawler->filter('#pexChecklist > link[href*="css/pasta-checklist.css"]')->count());

        $raiz = $crawler->filter('#pexChecklist');
        self::assertSame((string) $pasta->getId(), $raiz->attr('data-pasta-id'), 'o JS estático lê o id da pasta daqui');
        self::assertNotEmpty($raiz->attr('data-csrf-pasta'));
    }
}
