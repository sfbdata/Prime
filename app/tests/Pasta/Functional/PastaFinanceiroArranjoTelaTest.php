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

    #[TestDox('A faixa do topo tem QUATRO cards, todos filhos diretos dela')]
    public function testQuatroCardsNaFaixa(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertSame(
            1,
            $crawler->filter('.ps-fin-faixa')->count(),
            'sumiu a faixa que segura os quatro cards de status'
        );

        self::assertSame(
            4,
            $crawler->filter('.ps-fin-faixa > .ps-fin-bloco')->count(),
            'o desenho pede quatro cards de mesmo peso, filhos diretos da faixa'
        );

        foreach ([
            '#financeiro-situacao-btn'  => 'Contrato',
            '#financeiro-probono-btn'   => 'Pró-bono',
            '#financeiro-valor-causa'   => 'Honorários contratuais',
            '#financeiro-media-cpf'     => 'Média por CPF',
        ] as $seletor => $rotulo) {
            self::assertSame(
                1,
                $crawler->filter('.ps-fin-faixa > .ps-fin-bloco ' . $seletor)->count(),
                sprintf('o card "%s" saiu da faixa', $rotulo)
            );
        }
    }

    #[TestDox('O card do valor mostra "Honorários contratuais", não mais "Valor da causa"')]
    public function testRotuloDoCardEHonorariosContratuais(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $bloco = $crawler->filter('#financeiro-valor-causa-bloco');
        self::assertSame(1, $bloco->count());
        self::assertSame(
            'Honorários contratuais',
            trim($bloco->filter('.ps-fin-rotulo')->text()),
            'o rótulo do card não é o nome que o dono pediu'
        );

        /* O nome antigo não pode sobrar em canto nenhum do card — nem no rótulo,
           nem no lápis, nem no rótulo acessível do campo de edição. O escopo é o
           card, não a página: ids e rota seguem chamando `valor-causa`. */
        self::assertStringNotContainsStringIgnoringCase(
            'Valor da causa',
            $bloco->html(),
            'o nome antigo continua visível em algum ponto do card'
        );
        self::assertSame(
            'Editar honorários contratuais',
            $bloco->filter('#financeiro-valor-causa-lapis')->attr('title')
        );
        self::assertSame(
            'Honorários contratuais',
            $bloco->filter('#financeiro-valor-causa-input')->attr('aria-label')
        );
    }

    #[TestDox('A ordem dos cards segue o desenho: Contrato, Pró-bono, Honorários contratuais, Média')]
    public function testOrdemDosCardsSegueODesenho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $marcadores = [
            '#financeiro-situacao-btn'  => 'contrato',
            '#financeiro-probono-btn'   => 'probono',
            '#financeiro-valor-causa'   => 'valor',
            '#financeiro-media-cpf'     => 'media',
        ];

        $ordemNaTela = [];
        $cards       = $crawler->filter('.ps-fin-faixa > .ps-fin-bloco');
        foreach ($cards as $indice => $no) {
            $card = $cards->eq($indice);
            foreach ($marcadores as $seletor => $apelido) {
                if ($card->filter($seletor)->count() > 0) {
                    $ordemNaTela[] = $apelido;
                    break;
                }
            }
        }

        self::assertSame(
            ['contrato', 'probono', 'valor', 'media'],
            $ordemNaTela,
            'a faixa tem de sair na ordem do desenho aprovado'
        );
    }

    #[TestDox('Arquivos saiu da faixa e virou card do TRILHO, ao lado de Pagamentos')]
    public function testArquivosEPagamentosVivemNoTrilho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        // Combinador de filho DIRETO: distingue "está no trilho" de "existe em
        // algum lugar da aba", que era verdade mesmo com o layout antigo.
        self::assertSame(
            1,
            $crawler->filter('#financeiro .ps-grade > .ps-trilho > [data-trilho="arquivos"]')->count(),
            'Arquivos é o primeiro card do trilho'
        );
        self::assertSame(
            1,
            $crawler->filter('#financeiro .ps-grade > .ps-trilho > [data-trilho="pagamentos"]')->count(),
            'Pagamentos é o segundo card do trilho'
        );
        self::assertSame(
            0,
            $crawler->filter('.ps-fin-faixa #financeiro-docs-lista')->count(),
            'a lista de arquivos não pode ter ficado na faixa do topo'
        );

        self::assertSame(
            ['arquivos', 'pagamentos'],
            $crawler->filter('#financeiro .ps-grade > .ps-trilho > [data-trilho]')
                ->each(fn ($n) => $n->attr('data-trilho')),
            'ordem do trilho no desenho: Arquivos, depois Pagamentos'
        );
    }

    #[TestDox('O gerenciador de arquivos NÃO existe nesta aba: nem busca, nem filtro, nem dropzone')]
    public function testAbaNaoTemMaisGerenciadorDeArquivos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $aba     = $crawler->filter('#financeiro')->text();

        /* Revisão de 28/08: busca, categorias, "mostrar mais 5" e dropzone saíram
           daqui. O gerenciador completo continua sendo a aba Documentos. */
        foreach (['Buscar arquivo', 'Comprovantes', 'Mostrar mais', 'Arraste contratos'] as $queSaiu) {
            self::assertStringNotContainsString(
                $queSaiu,
                $aba,
                "\"{$queSaiu}\" é do gerenciador, que saiu da aba Financeiro"
            );
        }
    }

    #[TestDox('"Observações financeiras" virou "Relatório financeiro", o nome da produção')]
    public function testRelatorioFinanceiroTemONomeDaProducao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame(
            'Relatório financeiro',
            trim($crawler->filter('#financeiroRelatorio > .ps-card-cab h2')->text())
        );
        // O compositor é o mesmo das anotações: mexer só aqui desalinharia os três da tela.
        self::assertSame(
            1,
            $crawler->filter('#financeiroRelatorio .ps-compositor #formFinanceiroObservacao')->count(),
            'o Relatório financeiro perdeu o compositor'
        );
    }

    #[TestDox('Contrato e Pró-bono são SELOS clicáveis, sem link "marcar como" ao lado')]
    public function testContratoEProBonoSaoSelosClicaveis(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $contrato = $crawler->filter('#financeiro-situacao-btn');
        self::assertSame('button', $contrato->nodeName());
        self::assertStringContainsString('ps-fin-selo', (string) $contrato->attr('class'));
        self::assertSame('Pendente', trim($contrato->text()), 'pasta nova nasce com contrato pendente');
        self::assertStringContainsString(
            'clique para marcar como assinado',
            (string) $contrato->attr('title'),
            'o próprio selo tem de dizer o que o clique faz'
        );

        $proBono = $crawler->filter('#financeiro-probono-btn');
        self::assertSame('button', $proBono->nodeName());
        self::assertStringContainsString('ps-fin-selo', (string) $proBono->attr('class'));
        self::assertSame('Não é pró-bono', trim($proBono->text()));

        // O link "marcar como assinado / pendente" saiu na revisão de 28/08.
        self::assertStringNotContainsString(
            'marcar como',
            $crawler->filter('.ps-fin-faixa')->text(),
            'dois alvos para a mesma ação ensinam o usuário a ignorar o selo'
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
            $crawler->filter('.ps-fin-faixa')->text(),
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
            $crawler->filter('.ps-fin-faixa')->text(),
            'a média é de um cliente específico — a tela tem de dizer de quem'
        );
    }

    /**
     * A PROVA DA REGRA NOVA, e ela é deliberadamente montada contra o critério antigo: o cliente
     * vinculado PRIMEIRO é o de id MAIOR. Pelo critério que valia antes (cliente de cadastro mais
     * antigo, id menor), a tela mostraria o Antonio. Agora tem de mostrar a Zulmira, porque foi
     * ela que entrou primeiro na pasta.
     */
    #[TestDox('Com vários clientes, manda o PRIMEIRO VINCULADO — mesmo que outro seja mais antigo no cadastro')]
    public function testVariosClientesUsaOPrimeiroVinculado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $antigoNoCadastro = ClientePFFactory::createOne([
            'tenant'       => $tenant,
            'nomeCompleto' => 'Antonio Primeiro',
        ])->_real();
        $primeiroVinculado = ClientePFFactory::createOne([
            'tenant'       => $tenant,
            'nomeCompleto' => 'Zulmira Segunda',
        ])->_real();

        self::assertLessThan(
            $primeiroVinculado->getId(),
            $antigoNoCadastro->getId(),
            'o cenário só distingue as duas regras se o vinculado primeiro tiver id MAIOR'
        );

        // A ordem destas duas linhas É o teste: quem entra primeiro manda.
        $pasta = $this->criarPasta($tenant, '10000.00');
        $pasta->addCliente($primeiroVinculado);
        $pasta->addCliente($antigoNoCadastro);

        // Outra pasta só da Zulmira, para a média dela diferir do valor desta.
        $outraDaZulmira = $this->criarPasta($tenant, '30000.00');
        $outraDaZulmira->addCliente($primeiroVinculado);

        // E uma do Antonio, com valor bem diferente, para o teste falhar se a escolha inverter.
        $doAntonio = $this->criarPasta($tenant, '90000.00');
        $doAntonio->addCliente($antigoNoCadastro);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame(
            'R$ 20.000,00',
            trim($crawler->filter('#financeiro-media-cpf')->text()),
            'a média tem de ser a do primeiro cliente vinculado (10.000 e 30.000)'
        );
        self::assertStringContainsString('ZULMIRA SEGUNDA', $crawler->filter('.ps-fin-faixa')->text());
        self::assertStringNotContainsString('ANTONIO PRIMEIRO', $crawler->filter('.ps-fin-faixa')->text());
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

        $faixa = $crawler->filter('.ps-fin-faixa')->text();
        self::assertStringContainsString('Média por CNPJ', $faixa);
        self::assertStringNotContainsString('Média por CPF', $faixa);
        self::assertSame('R$ 50.000,00', trim($crawler->filter('#financeiro-media-cpf')->text()));
    }

    #[TestDox('Pasta sem honorários contratuais mostra travessão no lugar do número')]
    public function testSemValorMostraTravessao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertSame('—', trim($crawler->filter('#financeiro-valor-causa')->text()));
    }

    #[TestDox('Os honorários contratuais são editáveis na própria tela, com token CSRF e rota de gravação')]
    public function testValorCausaTemGanchoDeEdicao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, '12860.00');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $bloco   = $crawler->filter('.ps-fin-faixa > #financeiro-valor-causa-bloco');

        self::assertSame(1, $bloco->count());
        self::assertNotEmpty($bloco->attr('data-csrf'));
        self::assertStringContainsString(
            "/pasta/{$pasta->getId()}/valor-causa",
            (string) $bloco->attr('data-url')
        );
    }
}
