<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Message\SincronizarPastaNoDrive;
use App\Sync\Service\CifradorDeSegredo;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class EditarPastaControllerTest extends JusPrimeWebTestCase
{
    private function criarCenario(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Editar ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_editar_' . uniqid() . '@test.com');
        $user->setFullName('Admin Editar');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $pasta = new Pasta();
        $pasta->setNup('NUP-EDIT-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $em->persist($pasta);

        $em->flush();

        return [$user, $tenant, $pasta];
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    /**
     * Cliente PF cadastrado e vinculado à pasta — é o que faz `pasta.clientes` deixar de ser vazio,
     * e era essa condição que escondia o campo `nome_cliente` do formulário de edição.
     */
    private function vincularClienteCadastrado(Pasta $pasta, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('LEANDRO DA SILVA E SOUSA');
        $cliente->setCpf('111.222.333-44');
        $cliente->setRg('');
        $cliente->setRgOrgaoExpedidor('');
        $cliente->setEmail('leandro_' . uniqid() . '@test.com');
        $cliente->setCep('');
        $cliente->setEndereco('');
        $cliente->setCidade('');
        $cliente->setEstado('');
        $em->persist($cliente);

        $pasta->addCliente($cliente);
        $em->flush();
    }

    #[TestDox('Salvar a edição de uma pasta COM cliente cadastrado não apaga o nome do cliente')]
    public function testSalvarNaoApagaONomeDoClienteQuandoHaClienteCadastrado(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pasta->setNomeCliente('APLC TOP LIFE 1 - LEANDRO DA SILVA E SOUSA');
        $pasta->setNomeAcao('AÇÃO MONITÓRIA');
        $em->flush();
        $this->vincularClienteCadastrado($pasta, $tenant);
        $pastaId = (int) $pasta->getId();

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pastaId);
        self::assertResponseIsSuccessful();

        // Submete o formulário COMO O NAVEGADOR O ENVIA. Montar o POST à mão (como fazem os outros
        // testes deste arquivo) incluiria a chave `nome_cliente` que a TELA pode não renderizar — e
        // o teste passaria verde com o defeito de pé. Foi assim que 3 pastas de produção perderam o
        // nome do cliente sem ninguém tocar no campo: bastava abrir "Editar Pasta" e salvar.
        $client->submit($crawler->filter('form[action$="/editar"]')->form());

        $em->clear();
        $atualizada = $em->find(Pasta::class, $pastaId);

        self::assertSame(
            'APLC TOP LIFE 1 - LEANDRO DA SILVA E SOUSA',
            $atualizada->getNomeCliente(),
            'salvar sem mexer no campo não pode apagar o nome do cliente',
        );
        self::assertSame('AÇÃO MONITÓRIA', $atualizada->getNomeAcao(), 'nem trocar a ação');
    }

    #[TestDox('O formulário de editar mostra o campo do nome do cliente mesmo com cliente cadastrado')]
    public function testFormularioMostraOCampoDoNomeDoClienteComClienteCadastrado(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pasta->setNomeCliente('APLC TOP LIFE 1 - LEANDRO DA SILVA E SOUSA');
        $em->flush();
        $this->vincularClienteCadastrado($pasta, $tenant);

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        // O campo tem de existir E vir preenchido: um input vazio devolveria o mesmo apagamento pela
        // porta da frente, agora com a assinatura do usuário.
        self::assertSame(
            'APLC TOP LIFE 1 - LEANDRO DA SILVA E SOUSA',
            $crawler->filter('form[action$="/editar"] input[name="nome_cliente"]')->attr('value'),
        );
    }

    #[TestDox('POST /{id}/editar com dados válidos atualiza pasta e redireciona para pasta_show')]
    public function testSucessoEditaPasta(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();
        $novoNup                 = 'NUP-NOVO-' . strtoupper(uniqid());

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token'       => $this->gerarCsrf('edit_pasta_' . $pasta->getId()),
            'nup'          => $novoNup,
            'nome_cliente' => 'Cliente Editado',
            'nome_acao'    => 'Ação Editada',
            'situacao'     => Pasta::SITUACAO_ARQUIVADA,
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $atualizada = $em->find(Pasta::class, $pasta->getId());

        self::assertNotNull($atualizada);
        self::assertSame($novoNup, $atualizada->getNup());
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $atualizada->getSituacao());
    }

    #[TestDox('POST /{id}/editar com CSRF inválido retorna 403')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token' => 'token-invalido',
            'nup'    => 'QUALQUER-NUP',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST /{id}/editar com NUP vazio exibe flash error e não altera a pasta')]
    public function testNupVazioNaoAlteraPasta(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();
        $nupOriginal             = $pasta->getNup();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token' => $this->gerarCsrf('edit_pasta_' . $pasta->getId()),
            'nup'    => '   ',
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $inalterada = $em->find(Pasta::class, $pasta->getId());

        self::assertNotNull($inalterada);
        self::assertSame($nupOriginal, $inalterada->getNup());
    }

    // ───────────────────────────────────────────────────────────────────────────────────────
    // R3 — o FIO entre a tela de editar e o Drive. Dispatcher e handler já têm teste isolado;
    // o que faltava era esta ponta. O erro clássico aqui é capturar o nome DEPOIS do UseCase:
    // nomeAntes viraria igual a nomeDepois, nada seria despachado, e a suíte inteira ficaria
    // verde mentindo que o R3 funciona.
    // ───────────────────────────────────────────────────────────────────────────────────────

    private function conectarDrive(Tenant $tenant, User $user): void
    {
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $cifrador = static::getContainer()->get(CifradorDeSegredo::class);
        $conexao  = new TenantDriveConexao($tenant);
        $conexao->registrarCredenciais($cifrador->cifrar('tok'), 'a@b.com', 'drive', $user);
        $conexao->definirRootFolder('root-abcdefghij');
        $em->persist($conexao);
        $em->flush();
    }

    private function transporteAsync(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    #[TestDox('R3: editar o nome da pasta enfileira a renomeação no Drive')]
    public function testEditarNomeEnfileiraRenomeacao(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();
        $this->conectarDrive($tenant, $user);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token'       => $this->gerarCsrf('edit_pasta_' . $pasta->getId()),
            'nup'          => $pasta->getNup(),
            'nome_cliente' => 'CLIENTE QUE MUDOU',
            'situacao'     => Pasta::SITUACAO_ATIVA,
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());

        $enviadas = $this->transporteAsync()->getSent();
        self::assertCount(1, $enviadas, 'editar o nome não enfileirou nada — o fio do R3 está partido');
        $msg = $enviadas[0]->getMessage();
        self::assertInstanceOf(SincronizarPastaNoDrive::class, $msg);
        self::assertSame($pasta->getId(), $msg->pastaId);
        self::assertTrue($msg->renomear, 'a mensagem saiu sem o pedido de renomear');
    }

    #[TestDox('R3: editar SÓ a situação não enfileira nada (nome igual não vira write no Drive)')]
    public function testEditarSoSituacaoNaoEnfileira(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();
        $this->conectarDrive($tenant, $user);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        // Mesmíssimo nup e sem cliente/ação — só a situação muda.
        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token'   => $this->gerarCsrf('edit_pasta_' . $pasta->getId()),
            'nup'      => $pasta->getNup(),
            'situacao' => Pasta::SITUACAO_ARQUIVADA,
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $em->find(Pasta::class, $pasta->getId())->getSituacao());

        self::assertCount(
            0,
            $this->transporteAsync()->getSent(),
            'arquivar a pasta gerou renomeação no Drive — write desnecessário na cota da API',
        );
    }

    #[TestDox('R3: escritório SEM Drive conectado não enfileira nada ao editar')]
    public function testEditarSemDriveConectadoNaoEnfileira(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();
        // sem conectarDrive()

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token'       => $this->gerarCsrf('edit_pasta_' . $pasta->getId()),
            'nup'          => $pasta->getNup(),
            'nome_cliente' => 'OUTRO CLIENTE',
            'situacao'     => Pasta::SITUACAO_ATIVA,
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());
        self::assertCount(0, $this->transporteAsync()->getSent());
    }
}
