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
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\AssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\UseCase\ListarVotacoesParaVotarUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Aba Votar de /voto (B15, desenho 18, aba 'ele'): um cartão por votação publicada, com chip, frase, menu e "Votar".
 * Rascunho, assembleia arquivada, pauta retirada e outro escritório ficam de fora. O arranjo é conferido por filho
 * direto.
 */
#[CoversClass(AssembleiaController::class)]
#[CoversClass(ListarVotacoesParaVotarUseCase::class)]
#[Group('voto')]
final class VotarNaAbaTest extends JusPrimeWebTestCase
{
    use Factories;

    private const CARTAO = '.voto-tela > .voto-painel[data-aba="ele"] > .vC.voto-votar-cartao';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Assembleia $assembleia;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio Votar ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        $this->assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2026'])->publicada()->create()->_real();
    }

    #[TestDox('Sem votação publicada: só o aviso de vazio do desenho, sem cartão')]
    public function testVazio(): void
    {
        $tela = $this->votar();

        self::assertSame('Nenhuma votação publicada ainda. Crie e publique em Gestão eleitoral.', trim($tela->filter('.voto-tela > .voto-painel[data-aba="ele"] > .vAviso')->text()));
        self::assertCount(0, $tela->filter(self::CARTAO));
    }

    #[TestDox('Cartão da votação aberta: nome, descrição, chip, dados, frase e Votar ligado ao portal do evento')]
    public function testCartaoDaVotacaoAberta(): void
    {
        $pauta = $this->pauta(1, 'Aprovação das contas', true, 'Contas de 2025');
        $tela = $this->votar();

        self::assertSame(
            'Todas as votações publicadas da organização. Clique em Votar para abrir a tela do eleitor; quem recebe o link ou o QR Code vê só aquelas em que consta.',
            trim($tela->filter('.voto-tela > .voto-painel[data-aba="ele"] > .vAviso')->text()),
        );
        $cartao = $tela->filter(self::CARTAO . '[data-pauta="' . $pauta->getId() . '"]');
        self::assertCount(1, $cartao);
        $topo = $cartao->filter('.voto-votar-corpo > .voto-votar-topo');
        self::assertCount(1, $cartao->filter('.voto-votar-corpo > .voto-votar-topo > .vChip'));
        self::assertCount(1, $cartao->filter('.voto-votar-corpo > .voto-votar-topo > .vKw > .vKm'));
        self::assertSame('Aprovação das contas', trim($topo->filter('.voto-votar-titulo > b')->text()));
        self::assertSame('Contas de 2025', trim($topo->filter('.voto-votar-titulo > .voto-votar-desc')->text()));
        self::assertSame('Votação aberta', trim($topo->filter('.vChip')->text()));
        $dados = $cartao->filter('.voto-votar-corpo > .voto-votar-meta > span');
        self::assertCount(3, $dados);
        self::assertMatchesRegularExpression('/^\d\d\/\d\d\/\d{4} \d\d:\d\d até|^Aberta em \d\d\/\d\d\/\d{4} \d\d:\d\d$/', trim($dados->eq(0)->text()));
        self::assertSame('Pautas de assembleia', trim($dados->eq(1)->text()));
        self::assertMatchesRegularExpression('/^Código de conferência [A-F0-9]{4}-[A-F0-9]{4}$/', trim($dados->eq(2)->text()));
        self::assertSame('Votação aberta. Clique em Votar para abrir a tela do eleitor, a mesma do link e do QR Code.', trim($cartao->filter('.voto-votar-corpo > .voto-votar-aviso')->text()));
        $votar = $cartao->filter('.voto-votar-corpo > .voto-votar-acoes > a.vB.vP[data-voto-votar]');
        self::assertCount(1, $votar);
        self::assertSame('Votar', trim($votar->text()));
        self::assertStringEndsWith('/voto/v/' . $this->assembleia->getCodigoPublico(), (string) $votar->attr('href'));
        self::assertStringNotContainsString('/votar/', (string) $votar->attr('href'), 'nunca o token de uma unidade');
        self::assertCount(0, $tela->filter('.voto-tela .card, .voto-tela .btn, .voto-tela .modal'));
    }

    #[TestDox('Votação que ainda não abriu: "Votar" desligado e a frase da mesa')]
    public function testVotarDesligadoSemAbrir(): void
    {
        $pauta = $this->pauta(1, 'Aguardando');
        $cartao = $this->votar()->filter(self::CARTAO . '[data-pauta="' . $pauta->getId() . '"]');

        self::assertSame('Aguardando abertura pela mesa.', trim($cartao->filter('.voto-votar-aviso')->text()));
        self::assertCount(1, $cartao->filter('.voto-votar-acoes > button.vB.vP[disabled]'));
        self::assertCount(0, $cartao->filter('.voto-votar-acoes > a'));
        self::assertSame('Publicada', trim($cartao->filter('.voto-votar-topo > .vChip')->text()));
    }

    #[TestDox('Suspensa e cancelada: chip do ciclo e frase em âmbar; encerrada: aponta para Resultados')]
    public function testCicloEEncerrada(): void
    {
        $suspensa = $this->pauta(1, 'Suspensa', true);
        $suspensa->suspender('Queda de energia', 'Mesa');
        $cancelada = $this->pauta(2, 'Cancelada', true);
        $cancelada->cancelar('Erro na pauta', 'Mesa');
        $encerrada = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 3, 'titulo' => 'Encerrada'])->comOpcoesPadrao()->encerrada()->create()->_real();
        $this->em->flush();
        $this->em->refresh($this->assembleia);

        $tela = $this->votar();
        $de = static fn (Crawler $t, PautaDaAssembleia $p): Crawler => $t->filter(self::CARTAO . '[data-pauta="' . $p->getId() . '"]');

        self::assertSame('Suspensa', trim($de($tela, $suspensa)->filter('.voto-votar-topo > .vChip')->text()));
        self::assertSame('Votação suspensa pela mesa. Aguarde a retomada.', trim($de($tela, $suspensa)->filter('.voto-votar-aviso')->text()));
        self::assertStringContainsString('#8a6200', (string) $de($tela, $suspensa)->filter('.voto-votar-aviso')->attr('style'));
        self::assertSame('Cancelada', trim($de($tela, $cancelada)->filter('.voto-votar-topo > .vChip')->text()));
        self::assertSame('Votação cancelada pela comissão.', trim($de($tela, $cancelada)->filter('.voto-votar-aviso')->text()));
        self::assertSame('Votação apurada. O resultado está em Resultados.', trim($de($tela, $encerrada)->filter('.voto-votar-aviso')->text()));
        self::assertCount(1, $de($tela, $encerrada)->filter('.voto-votar-acoes > button[disabled]'));
    }

    #[TestDox('Menu de três pontos: itens do ciclo por situação e Gerenciar no Painel sempre')]
    public function testMenu(): void
    {
        $aguardando = $this->pauta(1, 'Aguardando');
        $aberta = $this->pauta(2, 'Aberta', true);
        $tela = $this->votar();
        $itens = static fn (PautaDaAssembleia $p): array => $tela->filter(self::CARTAO . '[data-pauta="' . $p->getId() . '"] .voto-votar-topo > .vKw > .vKm')->filter('button, a')->each(static fn (Crawler $c): string => trim($c->text()));

        self::assertSame(['Retirar de pauta ou cancelar', 'Gerenciar no Painel'], $itens($aguardando));
        self::assertSame(['Suspender votação', 'Cancelar votação', 'Gerenciar no Painel'], $itens($aberta));
        self::assertCount(1, $tela->filter('#janela-ciclo-' . $aguardando->getId() . '-ret.vModCiclo'));
        self::assertCount(1, $tela->filter('#janela-ciclo-' . $aberta->getId() . '-can.vModCiclo'));
        self::assertStringContainsString('aba=ges', (string) $tela->filter(self::CARTAO . ' .vKm a')->first()->attr('href'));
    }

    #[TestDox('Ficam de fora: rascunho, assembleia arquivada, pauta retirada e votação de outro escritório')]
    public function testQuemNaoAparece(): void
    {
        $visivel = $this->pauta(1, 'Visível');
        $retirada = $this->pauta(2, 'Retirada');
        $retirada->retirarDePauta('Matéria adiada', 'Mesa');
        $rascunho = PautaDaAssembleiaFactory::new(['assembleia' => AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho']), 'titulo' => 'Do rascunho'])->comOpcoesPadrao()->create()->_real();
        $arquivada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Arquivada'])->publicada()->create()->_real();
        $daArquivada = PautaDaAssembleiaFactory::new(['assembleia' => $arquivada, 'titulo' => 'Da arquivada'])->comOpcoesPadrao()->create()->_real();
        $arquivada->arquivar('Assembleia antiga', 'Síndica');
        $outro = TenantFactory::createOne(['name' => 'Outro Condomínio ' . uniqid()])->_real();
        $alheia = PautaDaAssembleiaFactory::new(['assembleia' => AssembleiaFactory::new(['tenant' => $outro, 'titulo' => 'Alheia'])->publicada(), 'titulo' => 'Da outra'])->comOpcoesPadrao()->create()->_real();
        $this->em->flush();
        $this->em->refresh($this->assembleia);

        $tela = $this->votar();

        $titulos = $tela->filter(self::CARTAO . ' > .voto-votar-corpo > .voto-votar-topo > .voto-votar-titulo > b')->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertSame(['Visível'], $titulos);
        $ids = array_map(static fn (object $v): int => $v->pauta->id, static::getContainer()->get(ListarVotacoesParaVotarUseCase::class)->executar($this->escritorio));
        self::assertSame([(int) $visivel->getId()], $ids);
        foreach ([$retirada, $rascunho, $daArquivada, $alheia] as $fora) {
            self::assertNotContains((int) $fora->getId(), $ids);
        }
    }

    #[TestDox('Dois eventos: mais recente primeiro, e cada Votar leva ao código do próprio evento')]
    public function testOrdemELinkPorEvento(): void
    {
        $antiga = $this->pauta(1, 'Da antiga');
        $nova = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGE 2026', 'criadaEm' => new \DateTimeImmutable('+1 day')])->publicada()->create()->_real();
        $daNova = PautaDaAssembleiaFactory::new(['assembleia' => $nova, 'titulo' => 'Da nova'])->comOpcoesPadrao()->aberta()->create()->_real();
        $this->em->flush();
        $this->em->refresh($this->assembleia);
        $this->em->refresh($nova);

        $tela = $this->votar();

        self::assertSame(['Da nova', 'Da antiga'], $tela->filter(self::CARTAO . ' .voto-votar-titulo > b')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringEndsWith($nova->getCodigoPublico(), (string) $tela->filter(self::CARTAO . '[data-pauta="' . $daNova->getId() . '"] a[data-voto-votar]')->attr('href'));
        self::assertCount(0, $tela->filter(self::CARTAO . '[data-pauta="' . $antiga->getId() . '"] a[data-voto-votar]'));
    }

    private function pauta(int $ordem, string $titulo, bool $aberta = false, ?string $descricao = null): PautaDaAssembleia
    {
        $fabrica = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => $ordem, 'titulo' => $titulo, 'descricao' => $descricao])->comOpcoesPadrao();
        $pauta = ($aberta ? $fabrica->aberta() : $fabrica)->create()->_real();
        $this->em->refresh($this->assembleia);

        return $pauta;
    }

    private function votar(): Crawler
    {
        $tela = $this->client->request('GET', '/voto?aba=ele');
        self::assertResponseIsSuccessful();

        return $tela;
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
