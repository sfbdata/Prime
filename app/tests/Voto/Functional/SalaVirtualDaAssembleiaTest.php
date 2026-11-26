<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\AssembleiaController;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/** Sala virtual do debate: campo no formulário da assembleia, link na gestão e a faixa "Acompanhar" no portal do eleitor. */
#[CoversClass(AssembleiaController::class)]
#[Group('voto')]
final class SalaVirtualDaAssembleiaTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio da Sala ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
    }

    #[TestDox('O formulário mostra o campo "Sala virtual (link do Meet, Zoom, Teams...)" e grava o link; link inválido avisa e não grava')]
    public function testFormularioGravaOLink(): void
    {
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Sala'])->create()->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/editar';

        $tela = $this->client->request('GET', $url);
        self::assertStringContainsString('Sala virtual (link do Meet, Zoom, Teams...)', $tela->filter('form[data-voto-assistente-form]')->text());

        $form = $tela->selectButton('Salvar e sair')->form(['link_sala_virtual' => 'javascript:alert(1)']);
        $tela = $this->client->submit($form);
        self::assertStringContainsString('O link da sala virtual deve começar com http:// ou https://.', $tela->filter('.voto-erro')->text());
        self::assertNull($this->linkNoBanco($assembleia->getId()));

        $form = $tela->selectButton('Salvar e sair')->form(['link_sala_virtual' => 'https://meet.google.com/abc-defg-hij']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame('https://meet.google.com/abc-defg-hij', $this->linkNoBanco($assembleia->getId()));
    }

    #[TestDox('Gestão: o cartão da assembleia mostra o link da sala; sem link não mostra a linha')]
    public function testGestaoMostraOLink(): void
    {
        $com = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Com Sala'])->publicada()->create()->_real();
        $sem = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Sem Sala'])->publicada()->create()->_real();
        $com->definirLinkDaSalaVirtual('https://zoom.us/j/123');
        $this->em->flush();

        $tela = $this->client->request('GET', '/voto?aba=ges');

        self::assertResponseIsSuccessful();
        $linha = $tela->filter('#assembleia-' . $com->getId() . ' [data-voto-sala-gestao] a');
        self::assertCount(1, $linha);
        self::assertSame('https://zoom.us/j/123', $linha->attr('href'));
        self::assertSame('noopener noreferrer', $linha->attr('rel'));
        self::assertCount(0, $tela->filter('#assembleia-' . $sem->getId() . ' [data-voto-sala-gestao]'));
    }

    #[TestDox('Portal do eleitor: com link, "Abrir sala" abre em nova aba com rel noopener e o chip mostra o estado')]
    public function testPortalComLink(): void
    {
        [$assembleia, $unidade] = $this->publicadaComUnidade();
        $assembleia->definirLinkDaSalaVirtual('https://meet.google.com/abc-defg-hij');
        $this->em->flush();

        $tela = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade));

        self::assertResponseIsSuccessful();
        $faixa = $tela->filter('[data-vc-sala]');
        self::assertCount(1, $faixa);
        $abrir = $faixa->filter('a[data-vc-sala-abrir]');
        self::assertSame('https://meet.google.com/abc-defg-hij', $abrir->attr('href'));
        self::assertSame('_blank', $abrir->attr('target'));
        self::assertStringContainsString('noopener', (string) $abrir->attr('rel'));
        self::assertSame('Votação aberta', trim($faixa->filter('[data-vc-sala-estado]')->text()));
        self::assertCount(0, $faixa->filter('[data-vc-sala-sem-link]'));
    }

    #[TestDox('Portal do eleitor sem link: "Abrir sala" vira botão e o aviso "A sala virtual ainda não foi informada pela organização." fica na página')]
    public function testPortalSemLink(): void
    {
        [, $unidade] = $this->publicadaComUnidade();

        $tela = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade));

        $faixa = $tela->filter('[data-vc-sala]');
        self::assertCount(1, $faixa);
        self::assertCount(0, $faixa->filter('a[data-vc-sala-abrir]'));
        self::assertCount(1, $faixa->filter('button[data-vc-sala-sem-link]'));
        self::assertSame('A sala virtual ainda não foi informada pela organização.', trim($faixa->filter('[data-vc-sala-aviso]')->text()));
        self::assertNotNull($faixa->filter('[data-vc-sala-aviso]')->attr('hidden'));
    }

    /** @return array{0: \App\Voto\Entity\Assembleia, 1: \App\Voto\Entity\UnidadeDaAssembleia} */
    private function publicadaComUnidade(): array
    {
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Portal'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->aberta()->create();
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia])->_real();

        return [$assembleia, $unidade];
    }

    private function linkNoBanco(?int $id): ?string
    {
        $valor = $this->em->getConnection()->fetchOne('SELECT link_sala_virtual FROM voto.assembleia WHERE id = ?', [$id]);

        return false === $valor ? null : $valor;
    }

    private function usuario(): User
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }
}
