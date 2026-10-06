<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L7 no trilho da aba Dados (desenho 1.2.3): ícone de cadastro por cliente, gancho
 * da janela "Detalhes do cliente" e o ⋮ de cada prazo (.ics e Copiar).
 *
 * Asserts com combinador de FILHO DIRETO: `.cliente-nome > button.cliente-cad` diz
 * que o ícone está AO LADO do nome, e não em algum lugar da página. Cor, tamanho e
 * a janela em si (JS) são invisíveis ao PHPUnit — smoke do dono.
 */
#[CoversClass(PastaController::class)]
final class PastaClientesCadastroTelaTest extends JusPrimeWebTestCase
{
    /** @return array{0: EntityManagerInterface, 1: User, 2: Tenant, 3: Pasta} */
    private function criarBase(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant L7 ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('l7_' . uniqid() . '@test.com');
        $user->setFullName('Admin Tela');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('NUP-L7-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $pasta->setNomeAcao('Usucapião extraordinária');
        $pasta->setResponsavel($user);
        $em->persist($pasta);

        return [$em, $user, $tenant, $pasta];
    }

    private function criarCliente(EntityManagerInterface $em, Tenant $tenant, Pasta $pasta, string $nome, string $cpf, bool $completo): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setEmail('l7' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf($cpf);
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $em->persist($cliente);

        if ($completo) {
            $cliente->setEstadoCivil('CASADO');
            $cliente->setProfissao('Professora');
            foreach ([ClienteDocumento::CATEGORIA_IDENTIFICACAO, ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA] as $categoria) {
                $doc = new ClienteDocumento();
                $doc->setTenant($tenant);
                $doc->setTitulo('Anexo ' . $categoria);
                $doc->setCategoria($categoria);
                $doc->setCaminhoArquivo('arquivo_' . uniqid() . '.pdf');
                $doc->setNomeOriginal('anexo.pdf');
                $doc->setMimeType('application/pdf');
                $doc->setTamanhoBytes(1024);
                $cliente->addDocumento($doc);
                $em->persist($doc);
            }
        }

        $pasta->addCliente($cliente);

        return $cliente;
    }

    #[TestDox('cada cliente ganha o ícone de cadastro AO LADO do nome: verde completo, âmbar com a lista do que falta')]
    public function testIconeDeCadastroPorCliente(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $completo   = $this->criarCliente($em, $tenant, $pasta, 'Ana Completa', '11122233344', true);
        $incompleto = $this->criarCliente($em, $tenant, $pasta, 'Bruno Incompleto', '55566677788', false);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $linha = fn (ClientePF $c) => $crawler->filter(
            '.ps-trilho > [data-trilho="clientes"] #clientesList .cliente-linha[data-cliente-id="' . $c->getId() . '"]'
        );

        // Completo.
        $iconeOk = $linha($completo)->filter('.cliente-identidade > .cliente-nome > button.cliente-cad.js-ps-cli-detalhes');
        self::assertCount(1, $iconeOk, 'o ícone é filho direto do bloco do nome');
        self::assertStringContainsString('cliente-cad--completo', (string) $iconeOk->attr('class'));
        self::assertSame('0', $iconeOk->attr('data-pendencias'));
        self::assertStringStartsWith('Cadastro completo', (string) $iconeOk->attr('title'));
        self::assertCount(1, $iconeOk->filter('i.bi-person-vcard'));

        // Incompleto: estado civil, profissão e os dois anexos.
        $iconePend = $linha($incompleto)->filter('.cliente-identidade > .cliente-nome > button.cliente-cad');
        self::assertCount(1, $iconePend);
        self::assertStringContainsString('cliente-cad--pendente', (string) $iconePend->attr('class'));
        self::assertSame('4', $iconePend->attr('data-pendencias'));
        $titulo = (string) $iconePend->attr('title');
        self::assertStringStartsWith('Cadastro incompleto: 4 pendências', $titulo);
        foreach ([
            'Estado civil não informado',
            'Profissão não informada',
            'Documento de identificação não anexado',
            'Comprovante de residência não anexado',
        ] as $pendencia) {
            self::assertStringContainsString($pendencia, $titulo, 'o title lista QUAL pendência: ' . $pendencia);
        }
        self::assertStringNotContainsString('RG não informado', $titulo, 'campo preenchido não vira pendência');

        // O ícone não tem texto: o nome continua sendo só o nome (contrato dos testes do trilho).
        self::assertSame('', trim($iconePend->text()));
        self::assertSame(
            'BRUNO INCOMPLETO',
            trim($linha($incompleto)->filter('.cliente-nome > .cliente-nome-texto')->text())
        );
    }

    #[TestDox('o cartão de clientes leva a URL do fragmento da janela e o id da pasta atual')]
    public function testCartaoLevaOGanchoDaJanela(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $this->criarCliente($em, $tenant, $pasta, 'Carla Gancho', '99988877766', true);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $secao = $crawler->filter('.ps-trilho > section[data-trilho="clientes"]');
        self::assertCount(1, $secao);
        self::assertSame('/clientes/0/resumo', $secao->attr('data-resumo-url'));
        self::assertSame((string) $pasta->getId(), $secao->attr('data-pasta-id'));
        self::assertCount(1, $crawler->filter('script[src$="/js/pasta-clientes.js"]'));
    }

    #[TestDox('cada prazo tem o ⋮ com Ver nas metas, Adicionar à agenda (.ics) e Copiar — com dia, ano e responsável reais')]
    public function testMenuDoPrazo(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $prazo = new \DateTimeImmutable('+20 days');
        $meta  = new Tarefa();
        $meta->setTitulo('Juntar documentos');
        $meta->setDescricao('...');
        $meta->setPrazo($prazo);
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($user);
        $meta->addResponsavel($user);
        $em->persist($meta);
        $pasta->getTarefas()->add($meta);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $linhas = $crawler->filter('[data-trilho="prazos"] > .ps-linha.ps-prazo');
        self::assertCount(1, $linhas);
        self::assertCount(1, $crawler->filter('[data-trilho="prazos"] .ps-linha'), 'o menu não cria linha nova no cartão');

        $gatilho = $linhas->filter('.ps-prazo > .ps-pop-wrap > button.ps-prazo-mais');
        self::assertCount(1, $gatilho, 'o ⋮ é o último item da linha, depois do selo');
        $menuId = (string) $gatilho->attr('data-ps-pop');
        self::assertSame('psPrazoMenu' . $meta->getId(), $menuId);

        $menu = $linhas->filter('.ps-prazo > .ps-pop-wrap > #' . $menuId . '.ps-pop');
        self::assertCount(1, $menu);
        self::assertSame(
            ['Ver nas metas', 'Adicionar à agenda (.ics)', 'Copiar'],
            $menu->filter('.ps-pop-item')->each(fn ($n) => trim($n->filter('span')->text()))
        );
        self::assertStringNotContainsString('Encaminhar', $menu->text(), 'sem back-end, sem item');
        self::assertStringNotContainsString('Alertar', $menu->text(), 'sem back-end, sem item');

        $ics = $menu->filter('.js-prazo-ics');
        self::assertSame($prazo->format('Ymd'), $ics->attr('data-ics-dia'), 'o .ics leva o dia COM o ano da meta');
        self::assertSame('Juntar documentos', $ics->attr('data-ics-titulo'));
        self::assertSame('Admin Tela', $ics->attr('data-ics-responsavel'));
        self::assertSame($pasta->getNup(), $ics->attr('data-ics-pasta'));

        self::assertSame(
            'Juntar documentos · Faltam 20 dias · Admin Tela · ' . $prazo->format('d/m') . ' · Pasta ' . $pasta->getNup(),
            $menu->filter('.js-ps-copiar')->attr('data-ps-copiar'),
            'Copiar = "título · selo · meta · Pasta N", como o desenho'
        );
        self::assertSame('Faltam 20 dias', trim($linhas->filter('.ps-selo')->text()), 'o selo segue único na linha');
    }
}
