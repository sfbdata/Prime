<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaChecklistModeloController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaChecklistModeloItem;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Modelos de checklist: salvar a lista de documentos de uma pasta com um nome e
 * aplicá-la em outras.
 *
 * O que mais importa aqui: (a) aplicar ACRESCENTA e nunca apaga o que já estava
 * conferido; (b) modelo de outro escritório responde 404, nunca 403 — um 403
 * confirmaria que o id existe.
 */
#[CoversClass(PastaChecklistModeloController::class)]
final class PastaChecklistModeloControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private bool $csrfInstalado = false;

    /**
     * Token CSRF previsível, mesma receita do `PastaPagamentoControllerTest`.
     * Uma vez só: o contêiner recusa substituir serviço já inicializado, e os
     * testes de isolamento montam DOIS escritórios.
     */
    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function csrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(string $sufixo = ''): array
    {
        $this->instalarCsrfStorage();

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Modelo ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_modelo_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName('Admin Modelo');
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
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-MOD-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    /** @param string[] $titulos */
    private function darChecklistA(Pasta $pasta, Tenant $tenant, array $titulos): void
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $ordem = 1;

        foreach ($titulos as $titulo) {
            $item = new PastaChecklistItem();
            $item->setPasta($pasta);
            $item->setTenant($tenant);
            $item->setTitulo($titulo);
            $item->setOrdem($ordem);
            $em->persist($item);
            ++$ordem;
        }

        $em->flush();
    }

    /** @param string[] $titulos */
    private function criarModelo(Tenant $tenant, string $nome, array $titulos): PastaChecklistModelo
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $modelo = new PastaChecklistModelo();
        $modelo->setTenant($tenant);
        $modelo->setNome($nome);

        $ordem = 1;
        foreach ($titulos as $titulo) {
            $linha = new PastaChecklistModeloItem();
            $linha->setTenant($tenant);
            $linha->setTitulo($titulo);
            $linha->setOrdem($ordem);
            $modelo->adicionarItem($linha);
            ++$ordem;
        }

        $em->persist($modelo);
        $em->flush();

        return $modelo;
    }

    /** @return PastaChecklistItem[] */
    private function relerChecklist(Pasta $pasta): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(PastaChecklistItem::class)
            ->findBy(['pasta' => $pasta->getId()], ['ordem' => 'ASC']);
    }

    /**
     * Conta a linha por SQL cru, fora do alcance do TenantFilter. Serve para as perguntas
     * "isto ainda existe?" que o ORM responderia errado com o filtro ligado.
     */
    private function contarNoBanco(string $tabela, int $id): int
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return (int) $conn->fetchOne("SELECT count(*) FROM {$tabela} WHERE id = :id", ['id' => $id]);
    }

    private function contarLinhasDoModelo(int $modeloId): int
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return (int) $conn->fetchOne(
            'SELECT count(*) FROM pasta_checklist_modelo_item WHERE modelo_id = :id',
            ['id' => $modeloId],
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    // =========================================================================
    // Salvar
    // =========================================================================

    #[TestDox('salva o checklist da pasta como modelo do escritório, na ordem da tela')]
    public function testSalvar(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->darChecklistA($pasta, $tenant, ['Procuração', 'RG', 'Comprovante de residência']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos", [
            '_token' => $this->csrf('checklist_modelo_pasta_' . $pasta->getId()),
            'nome'   => 'Trabalhista',
        ]);

        self::assertResponseStatusCodeSame(201);
        $dados = $this->json($client);

        self::assertSame('TRABALHISTA', $dados['modelo']['nome']);
        self::assertSame(3, $dados['modelo']['totalItens']);
        self::assertSame(['PROCURAÇÃO', 'RG', 'COMPROVANTE DE RESIDÊNCIA'], $dados['modelo']['itens']);
        self::assertNotEmpty($dados['modelo']['csrf'], 'a linha volta com o token das ações dela');

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $modelos = $em->getRepository(PastaChecklistModelo::class)->findBy(['tenant' => $tenant]);
        self::assertCount(1, $modelos);
        self::assertSame($user->getId(), $modelos[0]->getAutor()?->getId());
        self::assertCount(3, $modelos[0]->getItens());
    }

    #[TestDox('nome que o escritório já usa volta 409 sinalizado, sem gravar um segundo modelo')]
    public function testSalvarComNomeRepetido(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->darChecklistA($pasta, $tenant, ['Procuração']);
        $this->criarModelo($tenant, 'Trabalhista', ['Petição inicial']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos", [
            '_token' => $this->csrf('checklist_modelo_pasta_' . $pasta->getId()),
            'nome'   => 'trabalhista',
        ]);

        self::assertResponseStatusCodeSame(409);
        $dados = $this->json($client);
        self::assertTrue($dados['jaExiste'], 'é este sinal que faz a tela perguntar "substituir?"');
        self::assertSame('TRABALHISTA', $dados['nome']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(PastaChecklistModelo::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('substituir troca os itens do modelo existente em vez de criar outro')]
    public function testSalvarSubstituindo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->darChecklistA($pasta, $tenant, ['Petição inicial', 'Contrato']);
        $modelo = $this->criarModelo($tenant, 'Trabalhista', ['Item velho']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos", [
            '_token'     => $this->csrf('checklist_modelo_pasta_' . $pasta->getId()),
            'nome'       => 'Trabalhista',
            'substituir' => '1',
        ]);

        self::assertResponseStatusCodeSame(201);

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $modelos = $em->getRepository(PastaChecklistModelo::class)->findBy(['tenant' => $tenant]);

        self::assertCount(1, $modelos, 'continua sendo um só modelo');
        self::assertSame($modelo->getId(), $modelos[0]->getId());

        $titulos = array_map(
            static fn (PastaChecklistModeloItem $l): string => $l->getTitulo(),
            $modelos[0]->getItens()->toArray(),
        );
        self::assertSame(['PETIÇÃO INICIAL', 'CONTRATO'], array_values($titulos));
    }

    #[TestDox('pasta sem checklist não vira modelo vazio (422)')]
    public function testSalvarChecklistVazio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos", [
            '_token' => $this->csrf('checklist_modelo_pasta_' . $pasta->getId()),
            'nome'   => 'Trabalhista',
        ]);

        self::assertResponseStatusCodeSame(422);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(PastaChecklistModelo::class)->findBy(['tenant' => $tenant]));
    }

    #[TestDox('token CSRF inválido é recusado com 403 e nada é gravado')]
    public function testSalvarSemCsrf(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->darChecklistA($pasta, $tenant, ['Procuração']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos", [
            '_token' => 'token-errado',
            'nome'   => 'Trabalhista',
        ]);

        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(PastaChecklistModelo::class)->findBy(['tenant' => $tenant]));
    }

    // =========================================================================
    // Listar
    // =========================================================================

    #[TestDox('a listagem traz os modelos do escritório com a prévia dos itens')]
    public function testListar(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarModelo($tenant, 'Trabalhista', ['Procuração', 'RG']);
        $this->criarModelo($tenant, 'Cível', ['Contrato']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/checklist/modelos");

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);

        self::assertCount(2, $dados['modelos']);
        self::assertSame('CÍVEL', $dados['modelos'][0]['nome'], 'ordem alfabética');
        self::assertSame('TRABALHISTA', $dados['modelos'][1]['nome']);
        self::assertSame(['PROCURAÇÃO', 'RG'], $dados['modelos'][1]['itens']);
        self::assertSame(2, $dados['modelos'][1]['totalItens']);
    }

    #[TestDox('modelo de OUTRO escritório não aparece na minha listagem')]
    public function testListarNaoVazaDeOutroEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin('a');
        [, $tenantB]     = $this->criarUsuarioAdmin('b');
        $pasta           = $this->criarPasta($tenant);
        $this->criarModelo($tenant, 'Meu modelo', ['Procuração']);
        $this->criarModelo($tenantB, 'Modelo do vizinho', ['Segredo do vizinho']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/checklist/modelos");

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);

        self::assertCount(1, $dados['modelos']);
        self::assertSame('MEU MODELO', $dados['modelos'][0]['nome']);
        self::assertStringNotContainsString(
            'Segredo do vizinho',
            (string) $client->getResponse()->getContent(),
            'nem o nome nem os itens do vizinho podem sair na resposta',
        );
    }

    // =========================================================================
    // Aplicar
    // =========================================================================

    #[TestDox('aplicar cria os itens do modelo na pasta, pendentes e com o token de cada um')]
    public function testAplicar(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $modelo          = $this->criarModelo($tenant, 'Trabalhista', ['Procuração', 'RG', 'CTPS']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos/{$modelo->getId()}/aplicar", [
            '_token' => $this->csrf('checklist_modelo_' . $modelo->getId()),
        ]);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);

        self::assertSame(3, $dados['totalCriados']);
        self::assertSame(0, $dados['totalIgnorados']);

        foreach ($dados['criados'] as $criado) {
            self::assertNotEmpty(
                $criado['csrfItem'],
                'sem o token do item novo, o primeiro clique em marcar/excluir tomaria 403',
            );
        }

        $itens = $this->relerChecklist($pasta);
        self::assertCount(3, $itens);
        self::assertSame(['PROCURAÇÃO', 'RG', 'CTPS'], array_map(
            static fn (PastaChecklistItem $i): string => $i->getTitulo(),
            $itens,
        ));

        foreach ($itens as $item) {
            self::assertFalse($item->isConcluido(), 'item aplicado nasce pendente');
            self::assertSame($tenant->getId(), $item->getTenant()?->getId());
        }
    }

    #[TestDox('aplicar ACRESCENTA: o que já existia é pulado e o item já conferido continua marcado')]
    public function testAplicarNaoDuplicaNemDesmarca(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->darChecklistA($pasta, $tenant, ['Procuração']);

        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $existente = $em->getRepository(PastaChecklistItem::class)->findOneBy(['pasta' => $pasta->getId()]);
        $existente->setConcluido(true);
        $em->flush();

        $modelo = $this->criarModelo($tenant, 'Trabalhista', ['Procuração', 'RG']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos/{$modelo->getId()}/aplicar", [
            '_token' => $this->csrf('checklist_modelo_' . $modelo->getId()),
        ]);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);

        self::assertSame(1, $dados['totalCriados']);
        self::assertSame(['PROCURAÇÃO'], $dados['ignorados']);

        $itens = $this->relerChecklist($pasta);
        self::assertCount(2, $itens, 'a procuração não pode ter duplicado');
        self::assertSame('PROCURAÇÃO', $itens[0]->getTitulo());
        self::assertTrue($itens[0]->isConcluido(), 'a conferência já feita não pode ser desfeita');
        self::assertSame('RG', $itens[1]->getTitulo());
    }

    #[TestDox('modelo de outro escritório responde 404 — nunca 403, que confirmaria o id')]
    public function testAplicarModeloDeOutroEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin('a');
        [, $tenantB]     = $this->criarUsuarioAdmin('b');
        $pasta           = $this->criarPasta($tenant);
        $doVizinho       = $this->criarModelo($tenantB, 'Do vizinho', ['Segredo do vizinho']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos/{$doVizinho->getId()}/aplicar", [
            '_token' => $this->csrf('checklist_modelo_' . $doVizinho->getId()),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->relerChecklist($pasta), 'nada do vizinho pode ter entrado na pasta');
    }

    #[TestDox('pasta excluída (lápide) não aceita aplicar modelo')]
    public function testAplicarEmPastaExcluida(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $modelo          = $this->criarModelo($tenant, 'Trabalhista', ['Procuração']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/checklist/modelos/{$modelo->getId()}/aplicar",
            ['_token' => $this->csrf('checklist_modelo_' . $modelo->getId())],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->relerChecklist($pasta));
    }

    // =========================================================================
    // Renomear e excluir
    // =========================================================================

    #[TestDox('renomear troca o nome do modelo')]
    public function testRenomear(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $modelo          = $this->criarModelo($tenant, 'Teste 2', ['Procuração']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos/{$modelo->getId()}/renomear", [
            '_token' => $this->csrf('checklist_modelo_' . $modelo->getId()),
            'nome'   => 'Trabalhista padrão',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('TRABALHISTA PADRÃO', $this->json($client)['nome']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(
            'TRABALHISTA PADRÃO',
            $em->getRepository(PastaChecklistModelo::class)->find($modelo->getId())?->getNome(),
        );
    }

    #[TestDox('renomear para o nome de outro modelo do escritório volta 409')]
    public function testRenomearComNomeOcupado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $modelo          = $this->criarModelo($tenant, 'Teste 2', ['Procuração']);
        $this->criarModelo($tenant, 'Trabalhista', ['Contrato']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos/{$modelo->getId()}/renomear", [
            '_token' => $this->csrf('checklist_modelo_' . $modelo->getId()),
            'nome'   => 'Trabalhista',
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    #[TestDox('excluir tira o modelo da lista mas não mexe nos checklists já aplicados')]
    public function testExcluir(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->darChecklistA($pasta, $tenant, ['Procuração']);
        $modelo   = $this->criarModelo($tenant, 'Trabalhista', ['Procuração']);
        // O id tem de sair daqui: ao remover a entidade, o Doctrine ZERA o `id` do objeto
        // que o teste ainda segura, e a leitura depois da requisição estouraria.
        $modeloId = (int) $modelo->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos/{$modeloId}/excluir", [
            '_token' => $this->csrf('checklist_modelo_' . $modeloId),
        ]);

        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->contarNoBanco('pasta_checklist_modelo', $modeloId));
        self::assertSame(0, $this->contarLinhasDoModelo($modeloId), 'as linhas caem por cascata');
        self::assertCount(1, $this->relerChecklist($pasta), 'o checklist já aplicado na pasta continua lá');
    }

    #[TestDox('excluir modelo de outro escritório responde 404 e não apaga nada')]
    public function testExcluirModeloDeOutroEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin('a');
        [, $tenantB]     = $this->criarUsuarioAdmin('b');
        $pasta           = $this->criarPasta($tenant);
        $doVizinho       = $this->criarModelo($tenantB, 'Do vizinho', ['Procuração']);
        $vizinhoId       = (int) $doVizinho->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/checklist/modelos/{$vizinhoId}/excluir", [
            '_token' => $this->csrf('checklist_modelo_' . $vizinhoId),
        ]);

        self::assertResponseStatusCodeSame(404);

        // Conferido em SQL cru, não pelo ORM: depois da requisição o TenantFilter continua
        // ligado no escritório A e faria o registro do vizinho "sumir" mesmo intacto — o
        // teste passaria pelo motivo errado, e falharia pelo motivo errado também.
        self::assertSame(1, $this->contarNoBanco('pasta_checklist_modelo', $vizinhoId), 'o modelo do vizinho continua intacto');
    }
}
