<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Arranjo da faixa do topo da aba Financeiro, contra o desenho aprovado.
 *
 * O combinador de FILHO DIRETO é o que distingue "está no lugar certo" de
 * "existe em algum lugar da página" — a diferença que uma suíte verde já deixou
 * passar neste projeto com o layout visivelmente quebrado. Estilo (tamanho de
 * fonte, cor, borda) continua invisível para o teste e é conferido no smoke.
 */
#[CoversClass(PastaController::class)]
final class PastaFinanceiroArranjoTelaTest extends JusPrimeWebTestCase
{
    use Factories;

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Arranjo Financeiro ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_arranjo_fin_' . uniqid() . '@test.com');
        $user->setFullName('Admin Arranjo');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, ?string $valorCausa = null): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-ARR-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setValorCausa($valorCausa);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function vincularCliente(Pasta $pasta, Tenant $tenant, string $nome = 'Cliente Teste'): ClientePF
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $cliente = ClientePFFactory::createOne([
            'tenant'       => $tenant,
            'nomeCompleto' => $nome,
        ])->_real();

        $pasta->addCliente($cliente);
        $em->flush();

        return $cliente;
    }

    #[TestDox('Os quatro blocos da faixa são filhos diretos da MESMA linha do grid')]
    public function testQuatroBlocosNaMesmaLinha(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertSame(
            1,
            $crawler->filter('.financeiro-faixa')->count(),
            'sumiu a linha do grid que segura os indicadores e os controles'
        );

        foreach ([
            '#financeiro-media-cpf'     => 'Média por CPF',
            '#financeiro-valor-causa'   => 'Valor da causa',
            '#financeiro-situacao-btn'  => 'CONTRATO',
            '#financeiro-probono-check' => 'PRÓ-BONO',
        ] as $seletor => $rotulo) {
            self::assertSame(
                1,
                $crawler->filter('.financeiro-faixa > div ' . $seletor)->count(),
                sprintf('o bloco "%s" saiu da faixa', $rotulo)
            );
        }

        self::assertSame(
            1,
            $crawler->filter('.financeiro-faixa > div #financeiro-docs-lista')->count(),
            'a lista de Arquivos tem de continuar na mesma faixa, à esquerda'
        );
    }

    #[TestDox('A ordem dos blocos segue o desenho: Arquivos, Média por CPF, Valor da causa, CONTRATO, PRÓ-BONO')]
    public function testOrdemDosBlocosSegueODesenho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $marcadores = [
            '#financeiro-docs-lista'    => 'arquivos',
            '#financeiro-media-cpf'     => 'media',
            '#financeiro-valor-causa'   => 'valor',
            '#financeiro-situacao-btn'  => 'contrato',
            '#financeiro-probono-check' => 'probono',
        ];

        $ordemNaTela = [];
        foreach ($crawler->filter('.financeiro-faixa > div') as $indice => $noColuna) {
            $coluna = $crawler->filter('.financeiro-faixa > div')->eq($indice);
            foreach ($marcadores as $seletor => $apelido) {
                if ($coluna->filter($seletor)->count() > 0) {
                    $ordemNaTela[] = $apelido;
                    break;
                }
            }
        }

        self::assertSame(
            ['arquivos', 'media', 'valor', 'contrato', 'probono'],
            $ordemNaTela,
            'a faixa tem de sair na ordem do desenho aprovado'
        );
    }

    #[TestDox('Sem cliente vinculado a média mostra travessão e convida a vincular')]
    public function testSemClienteVinculadoMostraTravessao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame('—', trim($crawler->filter('#financeiro-media-cpf')->text()));
        self::assertStringContainsString(
            'Vincule o cliente',
            $crawler->filter('.financeiro-faixa')->text(),
            'sem CPF a tela precisa dizer o que falta, não só mostrar um traço'
        );
    }

    #[TestDox('Com cliente vinculado a média aparece calculada e nomeia o cliente')]
    public function testComClienteVinculadoMostraMedia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $pastaA = $this->criarPasta($tenant, '10000.00');
        $cliente = $this->vincularCliente($pastaA, $tenant, 'Maria Souza');

        $pastaB = $this->criarPasta($tenant, '30000.00');
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $pastaB->addCliente($cliente);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pastaA->getId()}");

        self::assertSame('R$ 20.000,00', trim($crawler->filter('#financeiro-media-cpf')->text()));
        // O sistema guarda nome de cliente em maiúsculas (ClientePF::setNomeCompleto),
        // então é assim que ele aparece na faixa.
        self::assertStringContainsString(
            'MARIA SOUZA',
            $crawler->filter('.financeiro-faixa')->text(),
            'a média é de um cliente específico — a tela tem de dizer de quem'
        );
    }

    #[TestDox('Com vários clientes, manda o de cadastro mais antigo — e a tela diz qual é')]
    public function testVariosClientesUsaODeCadastroMaisAntigo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        // Cadastrado primeiro (id menor) — é este que deve mandar.
        $primeiro = ClientePFFactory::createOne([
            'tenant'       => $tenant,
            'nomeCompleto' => 'Antonio Primeiro',
        ])->_real();
        $segundo = ClientePFFactory::createOne([
            'tenant'       => $tenant,
            'nomeCompleto' => 'Zulmira Segunda',
        ])->_real();

        self::assertLessThan(
            $segundo->getId(),
            $primeiro->getId(),
            'o cenário depende de o primeiro cliente ter id menor'
        );

        // A pasta em tela tem os DOIS; a média mostrada tem de ser a do primeiro.
        $pasta = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($segundo);
        $pasta->addCliente($primeiro);

        // Outra pasta só do primeiro, para a média dele diferir do valor desta.
        $outraDoPrimeiro = $this->criarPasta($tenant, '30000.00');
        $outraDoPrimeiro->addCliente($primeiro);

        // E uma do segundo, com valor bem diferente, para o teste falhar se a
        // escolha do cliente inverter.
        $doSegundo = $this->criarPasta($tenant, '90000.00');
        $doSegundo->addCliente($segundo);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame(
            'R$ 20.000,00',
            trim($crawler->filter('#financeiro-media-cpf')->text()),
            'a média tem de ser a do cliente de cadastro mais antigo (10.000 e 30.000)'
        );
        self::assertStringContainsString('ANTONIO PRIMEIRO', $crawler->filter('.financeiro-faixa')->text());
        self::assertStringNotContainsString('ZULMIRA SEGUNDA', $crawler->filter('.financeiro-faixa')->text());
    }

    #[TestDox('Cliente empresa troca o rótulo para "Média por CNPJ" — empresa não tem CPF')]
    public function testClientePessoaJuridicaMostraRotuloDeCnpj(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $empresa = new ClientePJ();
        $empresa->setRazaoSocial('Construtora Exemplo Ltda');
        $empresa->setNomeFantasia('Construtora Exemplo');
        // A coluna guarda 14 caracteres — só os dígitos, sem máscara.
        $empresa->setCnpj('1234567800' . random_int(1000, 9999));
        $empresa->setEnderecSede('Av. Teste, 1000');
        $empresa->setRepresentanteLegal('Joana Representante');
        $empresa->setRepresentanteCpf('111.222.333-44');
        $empresa->setRepresentanteRg('12.345.678-9');
        $empresa->setRepresentanteCargo('Sócia-administradora');
        $empresa->setEmail('contato_' . uniqid() . '@exemplo.com');
        $empresa->setCep('80000-000');
        $empresa->setEndereco('Rua Teste, 1');
        $empresa->setCidade('Curitiba');
        $empresa->setEstado('PR');
        $empresa->setTenant($tenant);
        $em->persist($empresa);

        $pasta = $this->criarPasta($tenant, '50000.00');
        $pasta->addCliente($empresa);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $faixa = $crawler->filter('.financeiro-faixa')->text();
        self::assertStringContainsString('Média por CNPJ', $faixa);
        self::assertStringNotContainsString('Média por CPF', $faixa);
        self::assertSame('R$ 50.000,00', trim($crawler->filter('#financeiro-media-cpf')->text()));
    }

    #[TestDox('Pasta sem valor da causa mostra travessão no lugar do número')]
    public function testSemValorMostraTravessao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame('—', trim($crawler->filter('#financeiro-valor-causa')->text()));
    }

    #[TestDox('O valor da causa é editável na própria tela, com token CSRF e rota de gravação')]
    public function testValorCausaTemGanchoDeEdicao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $bloco   = $crawler->filter('#financeiro-valor-causa-bloco');

        self::assertSame(1, $bloco->count());
        self::assertNotEmpty($bloco->attr('data-csrf'));
        self::assertStringContainsString(
            "/pasta/{$pasta->getId()}/valor-causa",
            (string) $bloco->attr('data-url')
        );
    }
}
