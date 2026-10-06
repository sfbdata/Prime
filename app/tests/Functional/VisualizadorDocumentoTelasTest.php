<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Cliente\Controller\ClienteController;
use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * As três telas que têm o modal #previewDocModal (pasta, cliente, tarefa) passaram a usar UM
 * módulo só: public/js/visualizador-documento.js (+ o CSS do visualizador). Os ids do modal
 * (#previewDocModal, #previewDocConteudo, #previewDocNome, #previewDocDownload) são contrato
 * com o módulo, com o pasta-arquivos.js e com os botões data-bs-target — este teste trava
 * que continuam lá, e que nenhuma tela voltou a ter a própria cópia do preview.
 *
 * Teste de PHPUnit lê HTML: não prova que o DOCX/planilha abre no navegador (smoke do dono).
 */
#[CoversClass(PastaController::class)]
#[CoversClass(ClienteController::class)]
#[CoversClass(TarefaController::class)]
final class VisualizadorDocumentoTelasTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('pasta_show carrega o módulo do visualizador e mantém o modal com os mesmos ids')]
    public function testPastaShowUsaOModulo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $pasta  = $this->criarPasta($tenant, $gestor);
        $id     = (int) $pasta->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $crawler = $client->request('GET', "/pasta/{$id}");
        self::assertResponseIsSuccessful();

        $this->assertTelaUsaOModulo($crawler);
        /* O rodapé com o Baixar é o arranjo desta tela (o cliente tem o botão no cabeçalho). */
        self::assertCount(1, $crawler->filter('#previewDocModal .modal-footer #previewDocDownload'));
    }

    #[TestDox('cliente_show carrega o módulo do visualizador e mantém o modal com os mesmos ids')]
    public function testClienteShowUsaOModulo(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant);
        $id      = (int) $cliente->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $crawler = $client->request('GET', "/clientes/{$id}");
        self::assertResponseIsSuccessful();

        $this->assertTelaUsaOModulo($crawler);
        /* Diferença histórica desta tela, preservada: Download no cabeçalho, com `download`. */
        self::assertCount(1, $crawler->filter('#previewDocModal .modal-header #previewDocDownload[download]'));
        self::assertStringContainsString(
            'textoSemSuporteMidia: false',
            $this->scriptsInline($crawler),
            'a tela de cliente nunca teve o texto "Seu navegador não suporta…" em áudio/vídeo'
        );
    }

    #[TestDox('tarefa_show carrega o módulo do visualizador e mantém o modal com os mesmos ids')]
    public function testTarefaShowUsaOModulo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $tarefa = $this->criarTarefa($tenant, $gestor);
        $id     = (int) $tarefa->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $crawler = $client->request('GET', "/tarefas/{$id}");
        self::assertResponseIsSuccessful();

        $this->assertTelaUsaOModulo($crawler);
        self::assertCount(1, $crawler->filter('#previewDocModal .modal-footer #previewDocDownload'));
    }

    // ------------------------------------------------------------------ asserts

    private function assertTelaUsaOModulo(Crawler $crawler): void
    {
        self::assertCount(1, $crawler->filter('script[src*="/js/visualizador-documento.js"]'), 'módulo carregado uma vez');
        self::assertCount(1, $crawler->filter('link[href*="/css/visualizador-documento.css"]'), 'CSS do visualizador carregado uma vez');

        self::assertCount(1, $crawler->filter('#previewDocModal'));
        self::assertCount(1, $crawler->filter('#previewDocModal #previewDocConteudo'));
        self::assertCount(1, $crawler->filter('#previewDocModal #previewDocNome'));
        self::assertCount(1, $crawler->filter('#previewDocModal #previewDocDownload'));

        /* O módulo precisa existir antes do script que o chama no DOMContentLoaded. */
        $html      = $crawler->html();
        $posModulo = strpos($html, '/js/visualizador-documento.js');
        $posChamada = strpos($html, 'VisualizadorDocumento.ligarModal(');
        self::assertNotFalse($posModulo);
        self::assertNotFalse($posChamada);
        self::assertLessThan($posChamada, $posModulo, 'o <script src> do módulo vem antes da chamada');

        $inline = $this->scriptsInline($crawler);
        self::assertStringNotContainsString('previewConteudo.innerHTML', $inline, 'a cópia antiga do preview não pode voltar');
        self::assertStringNotContainsString('Pré-visualização não disponível', $inline, 'o texto do fallback mora só no módulo');
    }

    private function scriptsInline(Crawler $crawler): string
    {
        return implode("\n", $crawler->filter('script:not([src])')->each(
            static fn (Crawler $s): string => $s->text('', false)
        ));
    }

    // ------------------------------------------------------------------ helpers

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant VISUALIZADOR ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('visualizador_' . uniqid() . '@test.com');
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant, User $criador): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('VIS-' . (++$this->seq) . '-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarTarefa(Tenant $tenant, User $criador): Tarefa
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = $this->criarPasta($tenant, $criador);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta ' . uniqid());
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($criador);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    private function criarClientePF(Tenant $tenant): ClientePF
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $n  = ++$this->seq;

        $cliente = new ClientePF();
        $cliente->setNomeCompleto('ClienteVis' . $n . 'Z' . substr(uniqid(), -6));
        $cliente->setCpf(str_pad((string) (900 + $n), 11, '0', STR_PAD_LEFT));
        $cliente->setRg('654321' . $n);
        $cliente->setRgOrgaoExpedidor('SSP/SP');
        $cliente->setEmail('cliente_vis_' . uniqid() . '@test.com');
        $cliente->setCep('01310100');
        $cliente->setEndereco('Av. Paulista, 1000');
        $cliente->setCidade('São Paulo');
        $cliente->setEstado('SP');
        $cliente->setTenant($tenant);
        $em->persist($cliente);
        $em->flush();

        return $cliente;
    }
}
