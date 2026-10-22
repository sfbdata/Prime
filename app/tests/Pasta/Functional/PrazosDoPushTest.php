<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Djen\Service\AvisoDePublicacoesNovasInterface;
use App\Entity\Tenant\Tenant;
use App\Pasta\Command\PrazosDoPushCommand;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Service\AvisoDoPushNaCaptacao;
use App\Pasta\Service\PrazosDoPush;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Pasta\UseCase\SincronizarPrazosDoPushUseCase;
use App\Pasta\UseCase\VincularProcessoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Sincronização dos prazos do Push com o `pasta_prazo` (docs/specs/prazo-do-push.md §2, §3 e §5): cria, não duplica,
 * mantém o cumprido, remove o que deixou de valer, não toca no manual nem em outro escritório; e os três gatilhos
 * (captação, vínculo de processo e o comando).
 */
#[CoversClass(PrazosDoPush::class)]
#[CoversClass(SincronizarPrazosDoPushUseCase::class)]
#[CoversClass(PrazosDoPushCommand::class)]
#[CoversClass(AvisoDoPushNaCaptacao::class)]
#[CoversClass(VincularProcessoUseCase::class)]
#[CoversClass(DesvincularProcessoUseCase::class)]
#[Group('pasta')]
final class PrazosDoPushTest extends KernelTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';
    private const COM_PRAZO = 'Intime-se a parte autora para apresentar réplica no prazo de 15 dias.';
    private const SEM_PRAZO = 'Intimem-se as partes para ciência da decisão.';

    /** "Hoje" fixo: o corte do histórico depende dele. */
    private const HOJE = '2026-10-21 10:00:00';

    protected function setUp(): void
    {
        Clock::set(new MockClock(self::HOJE));
        self::bootKernel();
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    #[TestDox('cria uma linha origem push, push:<id>, prazo estimado, com o título do ato e do destinatário e a data da disponibilização como criação')]
    public function testCria(): void
    {
        [$tenant, $pasta] = $this->pastaComProcesso();
        $pub = $this->publicar($tenant, self::COM_PRAZO, '2026-09-25');

        self::assertSame(['criados' => 1, 'apagados' => 0], $this->sincronizar($pasta));

        $linhas = $this->linhasPush($pasta);
        self::assertCount(1, $linhas);
        self::assertSame(
            ['prazo', 'Prazo do Push: Réplica (parte autora)', '2026-10-20', 'push:' . $pub->getId(), true, null],
            [$linhas[0]['tipo'], $linhas[0]['titulo'], $linhas[0]['data'], $linhas[0]['registro_ref'], (bool) $linhas[0]['estimado'], $linhas[0]['responsavel']]
        );
        self::assertStringStartsWith('2026-09-25', (string) $linhas[0]['criado_em']);
    }

    #[TestDox('rodar de novo não duplica')]
    public function testNaoDuplica(): void
    {
        [$tenant, $pasta] = $this->pastaComProcesso();
        $this->publicar($tenant, self::COM_PRAZO, '2026-09-25');
        $this->sincronizar($pasta);

        self::assertSame(['criados' => 0, 'apagados' => 0], $this->sincronizar($pasta));
        self::assertCount(1, $this->linhasPush($pasta));
    }

    #[TestDox('a publicação que deixou de gerar prazo tem a linha removida')]
    public function testRemoveOQueDeixouDeValer(): void
    {
        [$tenant, $pasta] = $this->pastaComProcesso();
        $pub = $this->publicar($tenant, self::COM_PRAZO, '2026-09-25');
        $this->sincronizar($pasta);

        $pub->setTexto(self::SEM_PRAZO);
        $this->em()->flush();

        self::assertSame(['criados' => 0, 'apagados' => 1], $this->sincronizar($pasta));
        self::assertSame([], $this->linhasPush($pasta));
    }

    #[TestDox('a linha cumprida continua cumprida, mesmo quando a publicação deixa de gerar prazo')]
    public function testMantemOCumprido(): void
    {
        [$tenant, $pasta, $user] = $this->pastaComProcesso();
        $pub = $this->publicar($tenant, self::COM_PRAZO, '2026-09-25');
        $this->sincronizar($pasta);
        $linha = $this->em()->getRepository(PastaPrazo::class)->findOneBy(['pasta' => $pasta, 'origem' => PastaPrazo::ORIGEM_PUSH]);
        self::assertNotNull($linha);
        $linha->cumprir($user);
        $this->em()->flush();

        self::assertSame(['criados' => 0, 'apagados' => 0], $this->sincronizar($pasta), 'continua igual: não recria');
        $pub->setTexto(self::SEM_PRAZO);
        $this->em()->flush();
        self::assertSame(['criados' => 0, 'apagados' => 0], $this->sincronizar($pasta), 'deixou de valer, mas está cumprida');

        $linhas = $this->linhasPush($pasta);
        self::assertCount(1, $linhas);
        self::assertNotNull($linhas[0]['cumprido_em']);
    }

    #[TestDox('o prazo manual e o lido dos registros não são tocados')]
    public function testNaoTocaNoManual(): void
    {
        [$tenant, $pasta, $user] = $this->pastaComProcesso();
        $manual = new PastaPrazo($tenant, $pasta, $user);
        $manual->definir(PastaPrazo::TIPO_PRAZO, 'Prazo do cliente', new \DateTimeImmutable('2026-11-10'), null, null);
        $this->em()->persist($manual);
        $this->em()->persist(PastaPrazo::doRegistro($tenant, $pasta, 'expedientes:1', new \DateTimeImmutable('2026-10-01'), $user, PastaPrazo::TIPO_PRAZO, 'Lido', new \DateTimeImmutable('2026-11-11'), null, false));
        $this->em()->flush();
        $this->publicar($tenant, self::SEM_PRAZO, '2026-09-25');

        $this->sincronizar($pasta);

        self::assertSame(['manual', 'registro'], $this->em()->getConnection()->fetchFirstColumn(
            'SELECT origem FROM pasta_prazo WHERE pasta_id = ? ORDER BY origem',
            [$pasta->getId()]
        ));
    }

    #[TestDox('publicação de outro escritório com o mesmo número não gera prazo na pasta')]
    public function testOutroEscritorio(): void
    {
        [, $pasta] = $this->pastaComProcesso();
        [, $outro] = $this->criarAdmin();
        $this->publicar($outro, self::COM_PRAZO, '2026-09-25');

        self::assertSame(['criados' => 0, 'apagados' => 0], $this->sincronizar($pasta));
    }

    #[TestDox('a captação acerta as pastas do processo que recebeu publicação nova, uma vez cada, e não as de outro escritório')]
    public function testDepoisDaCaptacao(): void
    {
        [$tenant, $pasta, , $processo] = $this->pastaComProcesso();
        $outraPasta = $this->criarPasta($tenant);
        $this->vincular($outraPasta, $processo);
        [, $outro] = $this->criarAdmin();
        $pastaDeFora = $this->criarPasta($outro);
        $this->vincular($pastaDeFora, $this->criarProcesso($outro, self::NUMERO));
        $pub = $this->criarPublicacao($tenant, '880101', self::NUMERO, '2026-09-25', $processo);
        $pub->setTipoDocumento('Despacho');
        $pub->setTexto(self::COM_PRAZO);
        $this->em()->flush();

        $mudaram = static::getContainer()->get(SincronizarPrazosDoPushUseCase::class)->executar($tenant, ['880101']);

        self::assertSame(2, $mudaram);
        self::assertCount(1, $this->linhasPush($pasta));
        self::assertCount(1, $this->linhasPush($outraPasta));
        self::assertSame([], $this->linhasPush($pastaDeFora));
    }

    #[TestDox('o fim da captação (AvisoDePublicacoesNovasInterface) também grava os prazos do Push')]
    public function testFimDaCaptacaoLigado(): void
    {
        [$tenant, $pasta, , $processo] = $this->pastaComProcesso();
        $pub = $this->criarPublicacao($tenant, '880102', self::NUMERO, '2026-09-25', $processo);
        $pub->setTipoDocumento('Despacho');
        $pub->setTexto(self::COM_PRAZO);
        $this->em()->flush();

        static::getContainer()->get(AvisoDePublicacoesNovasInterface::class)->avisar($tenant, ['880102']);

        self::assertSame(['2026-10-20'], array_column($this->linhasPush($pasta), 'data'));
    }

    #[TestDox('vincular o processo à pasta calcula os prazos das publicações que ele já tinha')]
    public function testAoVincular(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->publicar($tenant, self::COM_PRAZO, '2026-09-25');

        static::getContainer()->get(VincularProcessoUseCase::class)->executar($pasta, $processo, $user);

        self::assertSame(['2026-10-20'], array_column($this->linhasPush($pasta), 'data'));
    }

    #[TestDox('o comando simula por padrão e só grava com --aplicar')]
    public function testComando(): void
    {
        [$tenant, $pasta] = $this->pastaComProcesso();
        $this->publicar($tenant, self::COM_PRAZO, '2026-09-25');
        $comando = new CommandTester((new Application(self::$kernel))->find('app:pastas:prazos-do-push'));

        $comando->execute(['--pasta' => (string) $pasta->getId()]);
        self::assertStringContainsString('simulação', $comando->getDisplay());
        self::assertStringContainsString('1 prazo(s) criado(s)', $comando->getDisplay());
        self::assertSame([], $this->linhasPush($pasta));

        $comando->execute(['--pasta' => (string) $pasta->getId(), '--aplicar' => true]);
        self::assertStringNotContainsString('simulação', $comando->getDisplay());
        self::assertCount(1, $this->linhasPush($pasta));
    }

    #[TestDox('corte do histórico: prazo vencido há 30 dias não nasce; vencido há 5 dias nasce (janela do "findou")')]
    public function testCorteDoPrazo(): void
    {
        [$tenant, $pasta] = $this->pastaComProcesso();
        // 1 dia: disponibilizado qui 17/09, publica sex 18/09, vence seg 21/09 (30 dias antes de 21/10).
        $this->publicar($tenant, 'Intime-se a parte autora para se manifestar no prazo de 1 dia.', '2026-09-17');
        // 1 dia: disponibilizado qua 14/10, publica qui 15/10, vence sex 16/10 (5 dias antes de 21/10).
        $this->publicar($tenant, 'Intime-se a parte ré para se manifestar no prazo de 1 dia.', '2026-10-14');

        self::assertSame(['criados' => 1, 'apagados' => 0], $this->sincronizar($pasta));
        self::assertSame(['2026-10-16'], array_column($this->linhasPush($pasta), 'data'));
    }

    #[TestDox('corte do histórico: audiência passada não nasce; a futura nasce')]
    public function testCorteDaAudiencia(): void
    {
        [$tenant, $pasta] = $this->pastaComProcesso();
        $this->publicar($tenant, 'Designo audiência de conciliação para o dia 20/10/2026, às 14h. Intimem-se as partes.', '2026-10-01');
        $this->publicar($tenant, 'Designo audiência de instrução para o dia 20/11/2026, às 14h. Intimem-se as partes.', '2026-10-01');

        $this->sincronizar($pasta);

        self::assertSame(['2026-11-20'], array_column($this->linhasPush($pasta), 'data'));
    }

    #[TestDox('a linha já gravada que ficou fora da janela continua: o corte não apaga')]
    public function testCorteNaoApagaOQueJaExiste(): void
    {
        [$tenant, $pasta] = $this->pastaComProcesso();
        $pub = $this->publicar($tenant, 'Intime-se a parte autora para se manifestar no prazo de 1 dia.', '2026-09-17');
        $this->em()->persist(PastaPrazo::doPush(
            $tenant,
            $pasta,
            (int) $pub->getId(),
            new \DateTimeImmutable('2026-09-17'),
            PastaPrazo::TIPO_PRAZO,
            'Prazo do Push: Manifestação (parte autora)',
            new \DateTimeImmutable('2026-09-21'),
            null,
            true
        ));
        $this->em()->flush();

        self::assertSame(['criados' => 0, 'apagados' => 0], $this->sincronizar($pasta));
        self::assertSame(['2026-09-21'], array_column($this->linhasPush($pasta), 'data'));
    }

    #[TestDox('desvincular o processo apaga os prazos do Push dele, menos o cumprido')]
    public function testAoDesvincular(): void
    {
        [$tenant, $pasta, $user, $processo] = $this->pastaComProcesso();
        $this->publicar($tenant, self::COM_PRAZO, '2026-09-25');
        $this->publicar($tenant, 'Intime-se a parte ré para se manifestar no prazo de 5 dias.', '2026-10-14');
        $this->sincronizar($pasta);
        self::assertCount(2, $this->linhasPush($pasta));
        $cumprida = $this->em()->getRepository(PastaPrazo::class)->findOneBy(['pasta' => $pasta, 'origem' => PastaPrazo::ORIGEM_PUSH, 'data' => new \DateTimeImmutable('2026-10-20')]);
        self::assertNotNull($cumprida);
        $cumprida->cumprir($user);
        $this->em()->flush();

        static::getContainer()->get(DesvincularProcessoUseCase::class)->executar($pasta, $processo, $user);

        $linhas = $this->linhasPush($pasta);
        self::assertSame(['2026-10-20'], array_column($linhas, 'data'));
        self::assertNotNull($linhas[0]['cumprido_em']);
    }

    #[TestDox('falha no cálculo não derruba o vínculo: o processo fica vinculado e o erro vai ao log')]
    public function testFalhaNoCalculoNaoDerrubaOVinculo(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->publicar($tenant, self::COM_PRAZO, '2026-10-14');
        $this->falharOCalculo();

        static::getContainer()->get(VincularProcessoUseCase::class)->executar($pasta, $processo, $user);

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_processo WHERE pasta_id = ?', [$pasta->getId()]));
        self::assertSame([], $this->linhasPush($pasta), 'sem a falha, a publicação acima geraria um prazo');
    }

    #[TestDox('falha no cálculo não derruba a captação: a publicação nova é gravada e a chamada termina')]
    public function testFalhaNoCalculoNaoDerrubaACaptacao(): void
    {
        [$tenant, $pasta] = $this->pastaComProcesso();
        $this->falharOCalculo();
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturnCallback(static fn (ConsultaComunicacoesQuery $q): RespostaComunicacoes => $q->numeroProcesso === self::NUMERO
            ? new RespostaComunicacoes(1, [['id' => 880201, 'numero_processo' => self::NUMERO, 'siglaTribunal' => 'TJDFT', 'tipoComunicacao' => 'Intimação',
                'nomeOrgao' => '1ª Vara Cível de Brasília', 'texto' => self::COM_PRAZO, 'data_disponibilizacao' => '2026-10-14']])
            : new RespostaComunicacoes(0, []));
        static::getContainer()->set(DjenClient::class, $mock);

        $resultado = static::getContainer()->get(SincronizarPublicacoesDjenUseCase::class)
            ->sincronizarNumeros($tenant, PeriodoConsulta::ultimosDias(30, new \DateTimeImmutable('2026-10-21')), [self::NUMERO]);

        self::assertSame(1, $resultado->getNovas());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM publicacao_djen WHERE djen_id = 880201 AND tenant_id = ?", [$tenant->getId()]));
        self::assertSame([], $this->linhasPush($pasta), 'sem a falha, a publicação nova geraria um prazo');
    }

    /** O repositório de verdade, com o `findBy` (a leitura das publicações da pasta no cálculo) lançando. */
    private function falharOCalculo(): void
    {
        $repositorio = $this->getMockBuilder(PublicacaoDjenRepository::class)
            ->setConstructorArgs([static::getContainer()->get('doctrine')])
            ->onlyMethods(['findBy'])
            ->getMock();
        $repositorio->method('findBy')->willThrowException(new \RuntimeException('falha simulada no cálculo'));
        static::getContainer()->set(PublicacaoDjenRepository::class, $repositorio);
    }

    /** @return array{0: Tenant, 1: Pasta, 2: \App\Entity\Auth\User, 3: \App\Processo\Entity\Processo} */
    private function pastaComProcesso(): array
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);

        return [$tenant, $pasta, $user, $processo];
    }

    private function publicar(Tenant $tenant, string $teor, string $data): PublicacaoDjen
    {
        $pub = $this->criarPublicacao($tenant, (string) random_int(900000000, 999999999), self::NUMERO, $data);
        $pub->setTipoDocumento('Despacho');
        $pub->setTexto($teor);
        $this->em()->flush();

        return $pub;
    }

    /** @return array{criados: int, apagados: int} */
    private function sincronizar(Pasta $pasta): array
    {
        $r = static::getContainer()->get(PrazosDoPush::class)->sincronizar($pasta);
        $this->em()->flush();

        return $r;
    }

    /** @return list<array<string, mixed>> */
    private function linhasPush(Pasta $pasta): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            "SELECT tipo, titulo, data, registro_ref, estimado, responsavel, criado_em, cumprido_em FROM pasta_prazo WHERE pasta_id = ? AND origem = 'push' ORDER BY data",
            [$pasta->getId()],
        );
    }
}
