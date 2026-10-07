<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * FPN-006: botão direito no registro do Relatório financeiro (desenho 1.2.3, dc L.1795 e o menu
 * de dc L.5958). O menu é HTML do servidor que o script do `_financeiro.html.twig` preenche com o
 * registro clicado; "Editar" e "Excluir" aparecem só quando o cartão traz os botões da janela de
 * 15 min e clicam ESSES botões. Aqui se prova a marcação dos dois lados do contrato: o menu e os
 * gatilhos que cada cartão expõe (ou não) conforme o autor e o prazo.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaFinanceiroRegistroMenuTelaTest extends JusPrimeWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarUsuario(Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('fin_reg_menu_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    /** @return array{User, Tenant, Pasta} */
    private function cenario(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Registro Fin ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        $user = $this->criarUsuario($tenant, 'Autora do Registro');

        $pasta = new Pasta();
        $pasta->setNup('FIN-REG-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return [$user, $tenant, $pasta];
    }

    private function criarRegistro(Pasta $pasta, User $autor, Tenant $tenant, string $conteudo, ?\DateTimeImmutable $criadaEm = null): PastaObservacaoFinanceira
    {
        $obs = (new PastaObservacaoFinanceira())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo($conteudo);
        if ($criadaEm !== null) {
            (new \ReflectionProperty(PastaObservacaoFinanceira::class, 'criadaEm'))->setValue($obs, $criadaEm);
        }
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    #[TestDox('Arranjo: um menu só, filho direto do Relatório financeiro, escondido, com Copiar texto, Editar e Excluir')]
    public function testMenuDoRegistro(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $menu = $crawler->filter('#financeiro #financeiroRelatorio > #financeiroObsMenu.ps-fin-menu[role="menu"][hidden]');
        self::assertCount(1, $menu);
        self::assertCount(1, $menu->filter('#financeiroObsMenu > .ps-fin-menu-titulo'));

        $itens = $menu->filter('#financeiroObsMenu > button.ps-fin-menu-item[role="menuitem"]');
        self::assertSame(['Copiar texto', 'Editar', 'Excluir comentário'], $itens->each(static fn ($b): string => trim($b->text())));
        self::assertSame(
            ['bi bi-clipboard', 'bi bi-pencil', 'bi bi-trash3'],
            $itens->each(static fn ($b): ?string => $b->filter('i')->attr('class')),
        );
        self::assertStringContainsString('js-fin-obs-copiar', (string) $itens->eq(0)->attr('class'));
        self::assertStringContainsString('js-fin-obs-editar js-fin-obs-gerenciar', (string) $itens->eq(1)->attr('class'));
        self::assertStringContainsString('ps-fin-menu-item--perigo js-fin-obs-excluir js-fin-obs-gerenciar', (string) $itens->eq(2)->attr('class'));
        // Desenho (dc L.6454-6466, `menuObs`/`extrasObs`): "Imprimir" mora só no ⋮ da observação
        // de Detalhes; o menu do Relatório financeiro não tem o item.
        self::assertCount(0, $menu->filter('#financeiroObsMenu .js-fin-obs-imprimir, #financeiroObsMenu .js-obs-imprimir, #financeiroObsMenu .bi-printer'));
        self::assertStringNotContainsString('Imprimir', $menu->text());
        // FPN-006b: Editar e Excluir ficam sempre no menu (bloqueados quando não dá), então nada
        // nasce escondido.
        self::assertCount(1, $menu->filter('#financeiroObsMenu > .ps-fin-menu-sep'));
        self::assertCount(0, $menu->filter('#financeiroObsMenu > [hidden]'));

        // Sem lastro no Financeiro: não aparecem (Responder depende do FPN-005).
        foreach (['Responder', 'Encaminhar', 'Destacar'] as $fora) {
            self::assertStringNotContainsString($fora, $menu->text());
        }
    }

    #[TestDox('O registro da autora, dentro da janela, expõe os gatilhos do menu: texto, autor, hora e os botões de editar e excluir')]
    public function testRegistroDaAutoraExpoeOsGatilhos(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $obs = $this->criarRegistro($pasta, $user, $tenant, 'Honorário combinado em 30%.');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cartao = $crawler->filter('#financeiroObsLista > article.ps-anotacao#financeiro-obs-' . $obs->getId());
        self::assertCount(1, $cartao);
        self::assertSame('Honorário combinado em 30%.', trim($cartao->filter('.obs-conteudo .ql-editor')->text()), '"Copiar texto" lê daqui');
        self::assertSame('Autora do Registro', trim($cartao->filter('.ps-anotacao-topo > .ps-anotacao-autor')->text()));
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', (string) $cartao->filter('.ps-anotacao-topo > .ps-anotacao-data')->attr('title'));
        self::assertCount(1, $cartao->filter('.ps-anotacao-acoes > .btn-editar-obs'));
        self::assertCount(1, $cartao->filter('.ps-anotacao-acoes > .btn-excluir-obs'));
        self::assertMatchesRegularExpression('/\d+ min/', $cartao->filter('.ps-anotacao-acoes > .ps-anotacao-janela')->text());
    }

    #[TestDox('Registro de OUTRO autor não traz os botões: o menu fica só com Copiar texto')]
    public function testRegistroDeOutroAutorSemGatilhos(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $outro = $this->criarUsuario($tenant, 'Outro Autor');
        $obs   = $this->criarRegistro($pasta, $outro, $tenant, 'Registro de outra pessoa.');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cartao = $crawler->filter('#financeiroObsLista > #financeiro-obs-' . $obs->getId());
        self::assertCount(1, $cartao);
        self::assertCount(0, $cartao->filter('.btn-editar-obs, .btn-excluir-obs'));
        self::assertCount(1, $cartao->filter('.obs-conteudo .ql-editor'), 'copiar continua possível');
        self::assertSame('0', $cartao->attr('data-obs-do-autor'), 'motivo do bloqueio: não é o autor');
    }

    #[TestDox('Registro da própria autora FORA da janela de 15 min também não traz os botões')]
    public function testRegistroForaDaJanelaSemGatilhos(): void
    {
        $client = static::createClient();
        [$user, $tenant, $pasta] = $this->cenario();
        $obs = $this->criarRegistro($pasta, $user, $tenant, 'Registro antigo.', new \DateTimeImmutable('-20 minutes'));
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $cartao = $crawler->filter('#financeiroObsLista > #financeiro-obs-' . $obs->getId());
        self::assertCount(1, $cartao);
        self::assertCount(0, $cartao->filter('.btn-editar-obs, .btn-excluir-obs'));
        self::assertSame('1', $cartao->attr('data-obs-do-autor'), 'motivo do bloqueio: o prazo passou');
    }
}
