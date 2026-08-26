<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Bloco "Clientes" da aba Dados: lista plana, principal sempre visível fora do
 * colapso, e documento (CPF/CNPJ) só de quem tem cadastro de verdade.
 *
 * Contexto que decide o desenho, medido na PROD em 2026-08-26: de 1.073 pastas,
 * 1.072 têm ZERO cliente vinculado e só o texto legado `nome_cliente`; uma tem
 * um cliente; nenhuma tem vários. O estado "texto legado" é o que aparece na
 * tela todo dia, por isso ele tem teste próprio aqui.
 */
#[CoversClass(PastaController::class)]
final class PastaShowClientesTest extends JusPrimeWebTestCase
{
    private function criarBase(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Clientes ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('cli_' . uniqid() . '@test.com');
        $user->setFullName('Admin Clientes');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('NUP-CLI-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $em->persist($pasta);

        return [$em, $user, $tenant, $pasta];
    }

    private function novoPf(Tenant $tenant, string $nome, string $cpf): ClientePF
    {
        $c = new ClientePF();
        $c->setEmail(strtolower(str_replace(' ', '.', $nome)) . uniqid() . '@test.com');
        $c->setCep('80000-000');
        $c->setEndereco('Rua Um, 1');
        $c->setCidade('Curitiba');
        $c->setEstado('PR');
        $c->setTenant($tenant);
        $c->setNomeCompleto($nome);
        $c->setCpf($cpf);
        $c->setRg('12.345.678-9');
        $c->setRgOrgaoExpedidor('SSP');

        return $c;
    }

    private function novoPj(Tenant $tenant, string $razao, string $cnpj): ClientePJ
    {
        $c = new ClientePJ();
        $c->setEmail('pj' . uniqid() . '@test.com');
        $c->setCep('80000-000');
        $c->setEndereco('Av. Dois, 2');
        $c->setCidade('Curitiba');
        $c->setEstado('PR');
        $c->setTenant($tenant);
        $c->setRazaoSocial($razao);
        $c->setCnpj($cnpj);
        $c->setEnderecSede('Av. Dois, 2');
        $c->setRepresentanteLegal('Fulano Representante');
        $c->setRepresentanteCpf('111.222.333-44');
        $c->setRepresentanteRg('11.222.333-4');
        $c->setRepresentanteCargo('Sócio');

        return $c;
    }

    #[TestDox('com vários clientes, o principal fica FORA da área colapsável e os outros dentro dela')]
    public function testPrincipalForaDoColapsoEOsOutrosDentro(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        // O primeiro vinculado vira o principal (Pasta::addCliente).
        $principal = $this->novoPf($tenant, 'Ana Principal', '111.111.111-11');
        $outro1    = $this->novoPf($tenant, 'Bruno Segundo', '222.222.222-22');
        $outro2    = $this->novoPf($tenant, 'Carla Terceira', '333.333.333-33');
        foreach ([$principal, $outro1, $outro2] as $c) {
            $em->persist($c);
            $pasta->addCliente($c);
        }
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // Filho DIRETO da lista: "existe na página" seria verdade mesmo com o
        // principal caído dentro do colapso, que é justamente o defeito.
        $princ = $crawler->filter('#clientesList > .cliente-principal');
        self::assertCount(1, $princ, 'o principal é filho direto da lista, não do bloco que colapsa');
        // MAIUSCULAS de proposito: ClientePF::setNomeCompleto normaliza com
        // mb_strtoupper. E regra do dominio, nao do template.
        self::assertStringContainsString('ANA PRINCIPAL', $princ->text());

        self::assertCount(
            0,
            $crawler->filter('.clientes-outros .cliente-principal'),
            'o principal NÃO pode estar dentro do colapso — ele tem de aparecer sempre'
        );

        $outros = $crawler->filter('.clientes-outros .cliente-linha');
        self::assertCount(2, $outros, 'os não-principais moram na área que colapsa e rola');
        // each(): o ->text() de uma lista do crawler devolve só o PRIMEIRO nó,
        // então afirmar sobre ele daria falso negativo no segundo cliente.
        $nomes = $outros->each(static fn ($linha) => trim($linha->filter('.cliente-nome')->text()));
        self::assertSame(['BRUNO SEGUNDO', 'CARLA TERCEIRA'], $nomes);
    }

    #[TestDox('o botão de mostrar/ocultar aparece com vários e anuncia quantos estão escondidos')]
    public function testToggleAnunciaQuantosOutrosExistem(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        foreach ([
            $this->novoPf($tenant, 'Ana Principal', '111.111.111-11'),
            $this->novoPf($tenant, 'Bruno Segundo', '222.222.222-22'),
            $this->novoPf($tenant, 'Carla Terceira', '333.333.333-33'),
        ] as $c) {
            $em->persist($c);
            $pasta->addCliente($c);
        }
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $toggle = $crawler->filter('.clientes-outros-toggle');
        self::assertCount(1, $toggle);
        self::assertStringContainsString('2', $toggle->text(), 'o botão diz quantos estão escondidos');
    }

    #[TestDox('com um único cliente não existe botão de expandir: não há nada a esconder')]
    public function testSemToggleQuandoSoHaOPrincipal(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $unico = $this->novoPf($tenant, 'Ana Sozinha', '111.111.111-11');
        $em->persist($unico);
        $pasta->addCliente($unico);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertCount(1, $crawler->filter('#clientesList > .cliente-principal'));
        self::assertCount(
            0,
            $crawler->filter('.clientes-outros-toggle'),
            'botão de expandir sem ninguém para expandir é ruído'
        );
    }

    #[TestDox('quem tem cadastro mostra o documento com o rótulo certo: CPF para PF, CNPJ para PJ')]
    public function testMostraDocumentoComORotuloCerto(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        // Os três formatos que a PROD realmente tem (medido em 2026-08-26):
        // CPF mascarado (3 dos 4 PF), CPF só dígitos (1 dos 4) e CNPJ só dígitos
        // (os 3 PJ — a coluna é varchar(14) e o CNPJ mascarado tem 18, não cabe).
        $pfMascarado = $this->novoPf($tenant, 'Ana Mascarada', '444.555.666-77');
        $pfCru       = $this->novoPf($tenant, 'Bruno Cru', '55566677788');
        $pj          = $this->novoPj($tenant, 'ACME Servicos Ltda', '12345678000190');
        foreach ([$pfMascarado, $pfCru, $pj] as $c) {
            $em->persist($c);
            $pasta->addCliente($c);
        }
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertCount(3, $crawler->filter('.cliente-linha'));

        $linha = fn (int $id) => $crawler->filter('.cliente-linha[data-cliente-id="' . $id . '"]');

        $a = $linha($pfMascarado->getId());
        self::assertSame('CPF', trim($a->filter('.cliente-doc-rotulo')->text()));
        self::assertSame('444.555.666-77', trim($a->filter('.cliente-doc-valor')->text()));

        // Gravado sem máscara, mas a tela não pode mostrar "55566677788".
        $b = $linha($pfCru->getId());
        self::assertSame('CPF', trim($b->filter('.cliente-doc-rotulo')->text()));
        self::assertSame(
            '555.666.777-88',
            trim($b->filter('.cliente-doc-valor')->text()),
            'CPF gravado cru tem de sair formatado na tela'
        );

        $c = $linha($pj->getId());
        self::assertSame(
            'CNPJ',
            trim($c->filter('.cliente-doc-rotulo')->text()),
            'pessoa jurídica não tem CPF; rotular errado é mentira na tela'
        );
        self::assertSame(
            '12.345.678/0001-90',
            trim($c->filter('.cliente-doc-valor')->text()),
            'CNPJ é gravado sem máscara e tem de sair formatado'
        );
        self::assertStringContainsString('ACME SERVICOS LTDA', $c->text());
    }

    #[TestDox('o texto legado nome_cliente aparece como não vinculado e SEM documento')]
    public function testTextoLegadoNaoInventaDocumento(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        // O estado de 1.072 das 1.073 pastas em produção.
        $pasta->setNomeCliente('MARIA DAS GRACAS');
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $legado = $crawler->filter('#clientesList .cliente-nao-vinculado');
        self::assertCount(1, $legado, 'a pasta sem vínculo mostra o nome solto, marcado como não vinculado');
        self::assertStringContainsString('MARIA DAS GRACAS', $legado->text());

        self::assertCount(
            0,
            $crawler->filter('#clientesList .cliente-doc-rotulo'),
            'sem cadastro não há CPF; imprimir o rótulo vazio seria inventar dado'
        );
        self::assertCount(
            0,
            $crawler->filter('.clientes-outros-toggle'),
            'texto legado não é uma lista: não há o que expandir'
        );
    }

    #[TestDox('o upload de arquivo por cliente saiu da tela da pasta')]
    public function testUploadPorClienteSaiuDaTela(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $c = $this->novoPf($tenant, 'Ana Principal', '111.111.111-11');
        $em->persist($c);
        $pasta->addCliente($c);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertCount(
            0,
            $crawler->filter('.form-upload-cliente-inline'),
            'anexar arquivo de cliente passou a ser na ficha do cliente'
        );
        self::assertCount(
            0,
            $crawler->filter('#clienteAcc' . $c->getId()),
            'o acordeão de arquivos por cliente não existe mais'
        );

        // O caminho para os arquivos não pode ter sumido junto: a ficha continua a um clique.
        self::assertCount(
            1,
            $crawler->filter('#clientesList a[href="/clientes/' . $c->getId() . '"]'),
            'a ficha do cliente tem de continuar alcançável a partir da pasta'
        );
    }
}
