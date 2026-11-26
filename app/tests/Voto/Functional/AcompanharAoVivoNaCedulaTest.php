<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\ParticipacaoNaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\CedulaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\UseCase\ObterParticipacaoDaPautaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/** Caixa "Acompanhar" na cédula e o endpoint de participação que a atualiza: só contagens, mesmo acesso da cédula. */
#[CoversClass(CedulaController::class)]
#[CoversClass(ObterParticipacaoDaPautaUseCase::class)]
#[Group('voto')]
final class AcompanharAoVivoNaCedulaTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Assembleia $assembleia;
    private PautaDaAssembleia $pauta;
    private UnidadeDaAssembleia $unidade;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        $estado?->reativar(null, new \DateTimeImmutable());
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio Acompanhar ' . uniqid()])->_real();
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->assembleia = AssembleiaFactory::new()->publicada()->create(['tenant' => $this->escritorio])->_real();
        $this->pauta = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta()->create(['assembleia' => $this->assembleia])->_real();
        $this->unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'identificacao' => 'A-1'])->_real();
    }

    #[TestDox('A participação conta quem votou e as aptas, sem nada por opção')]
    public function testContagemCerta(): void
    {
        $outra = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'identificacao' => 'A-2'])->_real();
        UnidadeDaAssembleiaFactory::new()->inapta()->create(['assembleia' => $this->assembleia, 'identificacao' => 'A-3']);
        ParticipacaoNaPautaFactory::createOne(['unidade' => $outra, 'pauta' => $this->pauta]);

        $dados = $this->consultar($this->unidade, $this->pauta);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['aptas', 'estado', 'percentual', 'terminou', 'votaram'], $this->chaves($dados));
        self::assertSame(['estado' => 'aberta', 'votaram' => 1, 'aptas' => 2, 'percentual' => 50, 'terminou' => false], $dados);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    #[TestDox('Pauta encerrada devolve terminou verdadeiro para o JavaScript parar de perguntar')]
    public function testEncerradaTermina(): void
    {
        $encerrada = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->encerrada()->create(['assembleia' => $this->assembleia])->_real();

        $dados = $this->consultar($this->unidade, $encerrada);

        self::assertSame('encerrada', $dados['estado']);
        self::assertTrue($dados['terminou']);
    }

    #[TestDox('Pauta de outra assembleia, token inexistente ou pauta que não existe dão 404 em JSON')]
    public function testSemAcessoDa404(): void
    {
        $outroEscritorio = TenantFactory::createOne(['name' => 'Outro ' . uniqid()])->_real();
        $liberacao = new LiberacaoDoVoto($outroEscritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $alheia = AssembleiaFactory::new()->publicada()->create(['tenant' => $outroEscritorio])->_real();
        $pautaAlheia = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta()->create(['assembleia' => $alheia])->_real();

        $this->consultar($this->unidade, $pautaAlheia);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['ok' => false], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/votar/' . str_repeat('a', 40) . '/pauta/' . $this->pauta->getId() . '/participacao');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($this->unidade) . '/pauta/999999999/participacao');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('A cédula mostra a caixa Acompanhar abaixo do cartão da pauta, com "N de M já votaram" e a barra')]
    public function testCaixaNaCedula(): void
    {
        ParticipacaoNaPautaFactory::createOne(['unidade' => $this->unidade, 'pauta' => $this->pauta]);

        $tela = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($this->unidade));

        $caixa = $tela->filter('.vcMain > .vC.vcPauta + .vC.vcAcomp[data-vc-acomp="' . $this->pauta->getId() . '"]');
        self::assertCount(1, $caixa);
        self::assertSame('1 de 1 já votaram', trim($caixa->filter('[data-vc-acomp-texto]')->text()));
        self::assertSame('100', $caixa->filter('[role=progressbar]')->attr('aria-valuenow'));
        self::assertStringContainsString('/participacao', (string) $caixa->attr('data-vc-acomp-url'));
        self::assertSame('Votação aberta', trim($caixa->filter('[data-vc-acomp-chip]')->text()));
        self::assertCount(1, $tela->filter('script[src="/js/voto-acompanhar.js"]'));
    }

    #[TestDox('O JavaScript faz polling de 10 s, para com a aba oculta e ao terminar, e a barra respeita prefers-reduced-motion')]
    public function testJavaScriptECss(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/voto-acompanhar.js');
        self::assertStringContainsString('INTERVALO = 10000', $js);
        self::assertStringContainsString('document.hidden', $js);
        self::assertStringContainsString('d.terminou', $js);
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/voto-cedula.css');
        self::assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{[^}]*vcAcompBarra[^}]*transition:none/s', $css);
    }

    /** @return array<string, mixed> */
    private function consultar(UnidadeDaAssembleia $unidade, PautaDaAssembleia $pauta): array
    {
        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade) . '/pauta/' . $pauta->getId() . '/participacao');

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @param array<string, mixed> $dados @return list<string> */
    private function chaves(array $dados): array
    {
        $chaves = array_keys($dados);
        sort($chaves);

        return $chaves;
    }
}
