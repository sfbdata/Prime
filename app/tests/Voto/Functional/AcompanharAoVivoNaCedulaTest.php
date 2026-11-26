<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
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

    #[TestDox('Uma requisição traz todas as pautas da unidade, contando só unidades aptas (nunca "6 de 5")')]
    public function testContagemCerta(): void
    {
        $outra = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'identificacao' => 'A-2'])->_real();
        $inapta = UnidadeDaAssembleiaFactory::new()->inapta()->create(['assembleia' => $this->assembleia, 'identificacao' => 'A-3'])->_real();
        ParticipacaoNaPautaFactory::createOne(['unidade' => $outra, 'pauta' => $this->pauta]);
        ParticipacaoNaPautaFactory::createOne(['unidade' => $inapta, 'pauta' => $this->pauta]);

        $pautas = $this->consultar($this->unidade);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['id' => $this->pauta->getId(), 'estado' => 'aberta', 'votaram' => 1, 'aptas' => 2, 'percentual' => 50, 'terminou' => false]], $pautas);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    #[TestDox('Pauta encerrada devolve terminou verdadeiro; as duas pautas vêm na mesma resposta')]
    public function testEncerradaTermina(): void
    {
        $encerrada = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->encerrada()->create(['assembleia' => $this->assembleia, 'ordem' => 2])->_real();

        $pautas = $this->consultar($this->unidade);

        self::assertCount(2, $pautas);
        self::assertSame(['encerrada', true], [$pautas[1]['estado'], $pautas[1]['terminou']]);
        self::assertSame($encerrada->getId(), $pautas[1]['id']);
    }

    #[TestDox('Com votos registrados, o JSON só tem as chaves de participação e nada por opção')]
    public function testNaoVazaVotoPorOpcao(): void
    {
        $outra = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'identificacao' => 'A-2'])->_real();
        ParticipacaoNaPautaFactory::createOne(['unidade' => $outra, 'pauta' => $this->pauta]);
        $this->em->getConnection()->executeStatement('UPDATE voto.assembleia_opcao SET total_unidades = 7, total_peso = 7 WHERE pauta_id = ?', [$this->pauta->getId()]);

        $pautas = $this->consultar($this->unidade);

        self::assertSame(['aptas', 'estado', 'id', 'percentual', 'terminou', 'votaram'], $this->chaves($pautas[0]));
        $bruto = (string) $this->client->getResponse()->getContent();
        foreach (['opcao', 'Sim', 'Não', 'Abstenção', 'total', 'peso', 'resultado'] as $proibido) {
            self::assertStringNotContainsString($proibido, $bruto);
        }
    }

    #[TestDox('404 para token inexistente, pauta retirada some da lista e pauta fora do grupo da unidade também')]
    public function testSemAcessoEPautasInvisiveis(): void
    {
        $this->client->request('GET', '/votar/' . str_repeat('a', 40) . '/participacao');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['ok' => false], json_decode((string) $this->client->getResponse()->getContent(), true));

        $retirada = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->create(['assembleia' => $this->assembleia, 'ordem' => 2])->_real();
        $retirada->retirarDePauta('Teste', 'Fulano');
        $doGrupo = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta()->create(['assembleia' => $this->assembleia, 'ordem' => 3])->_real();
        (new \ReflectionProperty(PautaDaAssembleia::class, 'grupos'))->setValue($doGrupo, ['Bloco Z']);
        $this->em->flush();

        $ids = array_column($this->consultar($this->unidade), 'id');

        self::assertSame([$this->pauta->getId()], $ids);
    }

    #[TestDox('Voto desligado para o escritório e assembleia não publicada dão 404')]
    public function testLiberacaoEPublicacao(): void
    {
        $rascunho = AssembleiaFactory::new()->create(['tenant' => $this->escritorio])->_real();
        $deRascunho = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $rascunho])->_real();
        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($deRascunho) . '/participacao');
        self::assertResponseStatusCodeSame(404);

        $this->em->getRepository(LiberacaoDoVoto::class)->findOneBy(['tenant' => $this->escritorio])?->bloquear();
        $this->em->flush();
        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($this->unidade) . '/participacao');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Passou do limite por token, responde 429 mesmo com token válido')]
    public function testLimitePorToken(): void
    {
        static::getContainer()->set('limiter.voto_cedula_participacao', new RateLimiterFactoryEspiao(aceita: false));

        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($this->unidade) . '/participacao');

        self::assertResponseStatusCodeSame(429);
    }

    #[TestDox('A cédula mostra a caixa Acompanhar abaixo do cartão da pauta, com "N de M já votaram" e a barra')]
    public function testCaixaNaCedula(): void
    {
        ParticipacaoNaPautaFactory::createOne(['unidade' => $this->unidade, 'pauta' => $this->pauta]);

        $tela = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($this->unidade));

        $caixa = $tela->filter('.vcMain > .vC.vcPauta + .vC.vcAcomp[data-vc-acomp="' . $this->pauta->getId() . '"]');
        self::assertCount(1, $caixa);
        self::assertSame('1 de 1 unidades já votaram', trim($caixa->filter('[data-vc-acomp-texto]')->text()));
        self::assertSame('100', $caixa->filter('[role=progressbar]')->attr('aria-valuenow'));
        self::assertStringEndsWith('/participacao', (string) $caixa->attr('data-vc-acomp-url'));
        self::assertSame('Votação aberta', trim($caixa->filter('[data-vc-acomp-chip]')->text()));
        self::assertCount(1, $tela->filter('script[src="/js/voto-acompanhar.js"]'));
    }

    #[TestDox('O JavaScript faz polling de 10 s, para com a aba oculta e ao terminar, e a barra respeita prefers-reduced-motion')]
    public function testJavaScriptECss(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/voto-acompanhar.js');
        self::assertStringContainsString('BASE = 10000', $js);
        self::assertStringContainsString('document.hidden', $js);
        self::assertStringContainsString('d.terminou', $js);
        self::assertStringContainsString('TETO = 60000', $js);
        self::assertStringContainsString('r.status === 404', $js);
        self::assertStringContainsString('clearTimeout(timer)', $js);
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/voto-cedula.css');
        self::assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{[^}]*vcAcompBarra[^}]*transition:none/s', $css);
    }

    /** @return list<array<string, mixed>> */
    private function consultar(UnidadeDaAssembleia $unidade): array
    {
        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade) . '/participacao');

        return json_decode((string) $this->client->getResponse()->getContent(), true)['pautas'] ?? [];
    }

    /** @param array<string, mixed> $dados @return list<string> */
    private function chaves(array $dados): array
    {
        $chaves = array_keys($dados);
        sort($chaves);

        return $chaves;
    }
}
