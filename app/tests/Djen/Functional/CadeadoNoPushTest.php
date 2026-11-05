<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\ProcessosDaOabController;
use App\Djen\Controller\PublicacaoPastaController;
use App\Djen\Controller\PublicacaoPrazoController;
use App\Djen\Entity\ProcessoAcompanhado;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\ProcessosDaOabRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cadeado da pasta no Push Processual (auditoria, lote A: 1.01, 3.06, 3.07, 3.08 e 3.09). O processo cujas pastas
 * são todas ocultas para a pessoa não abre (K3, docs/AUTORIZACAO.md §7): as publicações dele não abrem por id, o
 * prazo não muda, ele não aparece no Relatório e o monitoramento e as anotações dele não mudam pelo número.
 * Quem vê a pasta segue igual.
 */
#[CoversClass(PublicacaoPrazoController::class)]
#[CoversClass(PublicacaoPastaController::class)]
#[CoversClass(ProcessosDaOabController::class)]
#[CoversClass(ProcessosDaOabRepository::class)]
#[Group('djen')]
final class CadeadoNoPushTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;
    use CriaFixturesDjenTrait;

    private const NUMERO = '07044444420268070001';
    private const PERMISSOES = ['modules.djen.view', 'modules.processos.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private User $primario;
    private User $ana;
    private Tenant $tenant;
    private Pasta $pasta;
    private Processo $processo;

    /** Pasta com o processo, trancada para a Ana no nível pedido (`null` = sem cadeado). */
    private function cenario(?NivelDeAcesso $nivel, bool $pausado = false): void
    {
        $this->prepararCliente();
        [$this->primario, $this->tenant] = $this->criarEscritorio();
        $this->ana = $this->criarColaborador($this->tenant, 'Ana Equipe', self::PERMISSOES);
        $this->pasta = $this->criarPasta($this->tenant);

        $this->processo = new Processo();
        $this->processo->setTenant($this->tenant);
        $this->processo->setNumeroProcesso(self::NUMERO);
        $this->processo->setClasseProcessual('Procedimento Comum');
        $this->processo->setOrgaoJulgador('1ª Vara Cível');
        $parte = (new ParteProcesso())->setTipo('Autor')->setNome('AUTOR SIGILOSO')->setTenant($this->tenant);
        $this->processo->addParte($parte);
        $this->em()->persist($parte);
        if ($pausado) {
            $this->processo->pausarMonitoramento($this->primario, new \DateTimeImmutable('-1 day'));
        }
        $this->em()->persist($this->processo);
        $vinculo = new PastaProcesso($this->pasta, $this->processo);
        $vinculo->setPrincipal(true);
        $this->em()->persist($vinculo);
        $this->pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        if ($nivel !== null) {
            $this->trancar($this->pasta, $this->primario, $nivel === NivelDeAcesso::Oculto ? [] : [[$this->ana, $nivel]]);
        }
    }

    private function publicacao(): PublicacaoDjen
    {
        $pub = $this->criarPublicacao($this->tenant, (string) random_int(900000000, 999999999), self::NUMERO);
        $pub->setPayloadDjen(['destinatarios' => [['nome' => 'AUTOR SIGILOSO'], ['nome' => 'RÉU SIGILOSO']]]);
        $pub->setOabMonitorada($this->criarOab($this->tenant, (string) random_int(10000, 99999), 'DF'));
        $this->em()->flush();

        return $pub;
    }

    private function recarregar(PublicacaoDjen $pub): PublicacaoDjen
    {
        $this->em()->clear();

        return $this->em()->find(PublicacaoDjen::class, $pub->getId());
    }

    private function processoDoBanco(): Processo
    {
        $this->em()->clear();

        return $this->em()->find(Processo::class, $this->processo->getId());
    }

    // 3.07 ------------------------------------------------------------------------------------------------------

    #[Test]
    public function detalheDaPublicacaoDeProcessoOcultoResponde404ENaoMarcaLida(): void
    {
        $this->cenario(NivelDeAcesso::Oculto);
        $pub = $this->publicacao();
        $this->entrar($this->ana, $this->tenant);

        $this->client->request('GET', '/push-processual/publicacoes/' . $pub->getId() . '/detalhe');

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->recarregar($pub)->isLida());
    }

    #[Test]
    public function quemVeAPastaEOPrimarioAbremODetalhe(): void
    {
        $this->cenario(NivelDeAcesso::Visualizar);
        $pub = $this->publicacao();

        $this->entrar($this->ana, $this->tenant);
        $this->client->request('GET', '/push-processual/publicacoes/' . $pub->getId() . '/detalhe');
        self::assertResponseIsSuccessful();

        $this->entrar($this->primario, $this->tenant);
        $this->client->request('GET', '/push-processual/publicacoes/' . $pub->getId() . '/detalhe');
        self::assertResponseIsSuccessful();
    }

    // 3.06 ------------------------------------------------------------------------------------------------------

    #[Test]
    public function partesDaPublicacaoDeProcessoOcultoResponde404(): void
    {
        $this->cenario(NivelDeAcesso::Oculto);
        $pub = $this->publicacao();
        $this->entrar($this->ana, $this->tenant);

        $this->client->request('GET', '/push-processual/publicacoes/' . $pub->getId() . '/partes');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('SIGILOSO', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function quemVeAPastaRecebeAsPartes(): void
    {
        $this->cenario(NivelDeAcesso::Visualizar);
        $pub = $this->publicacao();
        $this->entrar($this->ana, $this->tenant);

        $this->client->request('GET', '/push-processual/publicacoes/' . $pub->getId() . '/partes');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SIGILOSO', (string) $this->client->getResponse()->getContent());
    }

    // 3.08 ------------------------------------------------------------------------------------------------------

    #[Test]
    public function prazoECumpridoDePublicacaoOcultaNaoMudamNada(): void
    {
        $this->cenario(NivelDeAcesso::Oculto);
        $pub = $this->publicacao();
        $this->entrar($this->ana, $this->tenant);

        $this->client->request('POST', '/push-processual/publicacoes/' . $pub->getId() . '/prazo', ['_token' => 'TOKEN_push_publicacoes', 'dias' => '1']);
        self::assertStringContainsString('Publicação não encontrada.', $this->client->followRedirect()->text());
        $this->client->request('POST', '/push-processual/publicacoes/' . $pub->getId() . '/cumprido', ['_token' => 'TOKEN_push_publicacoes', 'cumprido' => '1']);
        self::assertStringContainsString('Publicação não encontrada.', $this->client->followRedirect()->text());

        $depois = $this->recarregar($pub);
        self::assertNull($depois->getPrazoDias());
        self::assertNull($depois->getPrazoCumpridoEm());
    }

    // 1.01 ------------------------------------------------------------------------------------------------------

    #[Test]
    public function relatorioNaoMostraNemContaProcessoOculto(): void
    {
        $this->cenario(NivelDeAcesso::Oculto, pausado: true);
        $this->publicacao();
        $this->entrar($this->ana, $this->tenant);

        $crawler = $this->client->request('GET', '/push-processual/processos?sub=relatorio');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pp-linha--dado'));
        self::assertStringNotContainsString('AUTOR SIGILOSO', $crawler->text());
        self::assertStringNotContainsString('0704444-44.2026.8.07.0001', $crawler->text());
    }

    #[Test]
    public function relatorioMostraOProcessoAQuemVeAPastaEAoPrimario(): void
    {
        $this->cenario(NivelDeAcesso::Visualizar, pausado: true);
        $this->publicacao();

        $this->entrar($this->ana, $this->tenant);
        self::assertCount(1, $this->client->request('GET', '/push-processual/processos?sub=relatorio')->filter('.pp-linha--dado'));
        $this->entrar($this->primario, $this->tenant);
        self::assertCount(1, $this->client->request('GET', '/push-processual/processos?sub=relatorio')->filter('.pp-linha--dado'));
    }

    #[Test]
    public function relatorioDoRepositorioTiraOsProcessosOcultos(): void
    {
        $this->cenario(null, pausado: true);
        $this->publicacao();
        $repositorio = static::getContainer()->get(ProcessosDaOabRepository::class);

        self::assertCount(1, $repositorio->relatorio($this->tenant, []));
        self::assertCount(0, $repositorio->relatorio($this->tenant, [(int) $this->processo->getId()]));
    }

    // 3.09 ------------------------------------------------------------------------------------------------------

    #[Test]
    public function pararNaoPausaProcessoOculto(): void
    {
        $this->cenario(NivelDeAcesso::Oculto);
        $this->entrar($this->ana, $this->tenant);

        $this->client->request('POST', '/push-processual/processos/parar', ['_token' => 'TOKEN_push_processos', 'numeros' => [self::NUMERO]]);

        self::assertNull($this->processoDoBanco()->getMonitoramentoPausadoEm());
    }

    /** @return iterable<string, array{string, array<string, string|list<string>>}> */
    public static function retomadas(): iterable
    {
        yield 'incluir-cadastrado' => ['/push-processual/processos/incluir-cadastrado', ['numero' => self::NUMERO]];
        yield 'incluir' => ['/push-processual/processos/incluir', ['numero' => self::NUMERO]];
        yield 'incluir-do-relatorio' => ['/push-processual/processos/incluir-do-relatorio', ['numeros' => [self::NUMERO]]];
        yield 'cadastrar' => ['/push-processual/processos/cadastrar', ['numeroProcesso' => self::NUMERO]];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('retomadas')]
    /** @param array<string, string|list<string>> $campos */
    public function retomarNaoReativaProcessoOculto(string $rota, array $campos): void
    {
        $this->cenario(NivelDeAcesso::Oculto, pausado: true);
        $this->entrar($this->ana, $this->tenant);

        $this->client->request('POST', $rota, ['_token' => 'TOKEN_push_processos'] + $campos);

        self::assertNotNull($this->processoDoBanco()->getMonitoramentoPausadoEm());
    }

    #[Test]
    public function anotacoesNaoGravamNoProcessoOculto(): void
    {
        $this->cenario(NivelDeAcesso::Oculto);
        $this->entrar($this->ana, $this->tenant);

        $this->client->request('POST', '/push-processual/processos/anotacoes', ['_token' => 'TOKEN_push_processos', 'numero' => self::NUMERO, 'observacao' => 'Mexi', 'area' => '', 'fase' => '', 'email' => '']);

        $this->em()->clear();
        self::assertNull($this->em()->getRepository(ProcessoAcompanhado::class)->findOneBy(['numeroProcesso' => self::NUMERO]));
    }

    #[Test]
    public function quemEditaAPastaPausaERetomaNormalmente(): void
    {
        $this->cenario(NivelDeAcesso::Editar);
        $this->entrar($this->ana, $this->tenant);

        $this->client->request('POST', '/push-processual/processos/parar', ['_token' => 'TOKEN_push_processos', 'numeros' => [self::NUMERO]]);
        self::assertNotNull($this->processoDoBanco()->getMonitoramentoPausadoEm());

        $this->client->request('POST', '/push-processual/processos/incluir-cadastrado', ['_token' => 'TOKEN_push_processos', 'numero' => self::NUMERO]);
        self::assertNull($this->processoDoBanco()->getMonitoramentoPausadoEm());
    }
}
