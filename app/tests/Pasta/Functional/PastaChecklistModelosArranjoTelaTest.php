<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

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
use Zenstruck\Foundry\Test\Factories;

/**
 * ONDE os modelos de checklist ficam na tela — o que a suíte de comportamento não vê.
 *
 * Vale por dois defeitos que este projeto já pagou nesta mesma página: painel solto fora
 * do cartão a que pertence, e modal preso abaixo do backdrop por causa de um ancestral
 * animado. O segundo é a razão de o painel ser INLINE; se alguém o transformar em
 * `.modal` do Bootstrap, o teste abaixo cai e explica o porquê.
 *
 * O combinador de FILHO DIRETO é o que separa "está no lugar certo" de "existe em algum
 * lugar da página". Estilo (cor, borda, tamanho) segue invisível aqui e é do smoke.
 */
#[CoversClass(PastaController::class)]
final class PastaChecklistModelosArranjoTelaTest extends JusPrimeWebTestCase
{
    use Factories;

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Arranjo Modelos ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_arr_mod_' . uniqid() . '@test.com');
        $user->setFullName('Admin Arranjo Modelos');
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
        $pasta->setNup('TEST-ARRMOD-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    #[TestDox('O botão Modelos fica na barra de ações do checklist, ao lado de editar e adicionar')]
    public function testBotaoNaBarraDeAcoesDoChecklist(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertSame(
            1,
            $crawler->filter('.fm-checklist-progresso > #btnChecklistModelos')->count(),
            'o botão de modelos saiu da barra de ações do checklist',
        );

        self::assertSame(
            3,
            $crawler->filter('.fm-checklist-progresso > .fm-checklist-acao')->count(),
            'a barra tem três ações: editar, adicionar e modelos',
        );
    }

    #[TestDox('O painel de modelos é filho do corpo do cartão de checklist, e nasce fechado')]
    public function testPainelDentroDoCartaoDoChecklist(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $painel = $crawler->filter('#fmChecklist .fm-checklist-corpo > #checklistModelosPainel');
        self::assertSame(1, $painel->count(), 'o painel de modelos saiu de dentro do cartão do checklist');

        self::assertStringContainsString(
            'd-none',
            (string) $painel->attr('class'),
            'o painel nasce fechado: quem abre é o botão',
        );

        // O painel NÃO pode virar modal do Bootstrap: o gerenciador de arquivos é animado, e
        // ancestral com `transform` vira bloco de contenção — o modal ficaria abaixo do backdrop.
        self::assertStringNotContainsString('modal', (string) $painel->attr('class'));
        self::assertNull($painel->attr('data-bs-toggle'));
    }

    #[TestDox('O painel carrega as três URLs e o token que o JS precisa')]
    public function testPainelCarregaOsDadosDoJs(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        $painel  = $crawler->filter('#checklistModelosPainel');

        self::assertSame("/pasta/{$pasta->getId()}/checklist/modelos", $painel->attr('data-url-listar'));
        self::assertSame("/pasta/{$pasta->getId()}/checklist/modelos", $painel->attr('data-url-salvar'));
        self::assertNotEmpty($painel->attr('data-csrf-pasta'));

        // Campo de salvar e lista de modelos são as duas metades do painel: sem uma delas a
        // feature abre pela metade e o JS quebra ao procurar o elemento.
        self::assertSame(1, $crawler->filter('#checklistModelosPainel #checklistModelosLista')->count());
        self::assertSame(1, $crawler->filter('#checklistModelosPainel #checklistModeloNome')->count());
        self::assertSame(1, $crawler->filter('#checklistModelosPainel #btnChecklistModeloSalvar')->count());
    }
}
