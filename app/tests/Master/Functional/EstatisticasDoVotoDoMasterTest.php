<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Controller\VotoDoMasterController;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\ParticipacaoNaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Service\CsvDasEstatisticasDoVoto;
use App\Voto\Service\EstatisticasDasVotacoes;
use App\Voto\Service\LeituraDaAssembleia;
use App\Voto\UseCase\ObterEstatisticasDoVotoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Estatísticas de todas as votações" da aba Voto do Master (W2-7, bloco C; spec voto-equipe-e-master-1611, seção 3):
 * só o Master, entre escritórios, sem rascunho nem simulação, números iguais aos da apuração, CSV e filtro por
 * categoria. A categoria "Instituição de ensino" isola os dados de cada teste do que já existir no banco.
 */
#[CoversClass(VotoDoMasterController::class)]
#[CoversClass(ObterEstatisticasDoVotoUseCase::class)]
#[CoversClass(EstatisticasDasVotacoes::class)]
#[CoversClass(CsvDasEstatisticasDoVoto::class)]
#[Group('voto')]
final class EstatisticasDoVotoDoMasterTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Master vê os números: encerradas com quórum (atingido, não atingido, não exigido), aberta só com a participação, e os KPIs batem')]
    public function testNumerosEKpis(): void
    {
        $this->entrarComoMasterNoProprioEscritorio();
        $a = $this->assembleiaComPautas(TenantFactory::createOne(['name' => 'Escritório Alfa ' . uniqid()])->_real(), 'Assembleia Alfa ' . uniqid(), CenarioDaAssembleia::Ensino);

        $d = $this->dados('ensino');

        $linhas = $this->linhasDa($d, 'Assembleia Alfa');
        self::assertCount(4, $linhas, 'três encerradas e uma aberta; cancelada e retirada ficam fora');
        self::assertSame('3 de 4 votaram (75%) · Quórum: Atingido', $this->numeros($linhas[0]));
        self::assertSame('1 de 4 votaram (25%) · Quórum: Não atingido', $this->numeros($linhas[1]));
        self::assertSame('4 de 4 votaram (100%) · Quórum: Não exigido', $this->numeros($linhas[2]));
        self::assertSame('2 de 4 votaram (50%)', $this->numeros($linhas[3]), 'aberta: só a participação, nenhum quórum');
        self::assertSame(['Encerrada', 'Encerrada', 'Encerrada', 'Votação aberta'], array_column($linhas, 'situacao'));
        self::assertSame(['votacoes' => 4, 'realizadas' => 3, 'emAndamento' => 1, 'aptos' => 12, 'votantes' => 8, 'participacao' => '66,7%', 'quorumAtingido' => 1, 'quorumExigido' => 2], $d['kpis']);
        self::assertSame($a->getTenant()->getName(), $linhas[0]['escritorio']);
        self::assertSame('Cond. do Teste', $linhas[0]['entidade']);
        self::assertSame('Instituição de ensino', $linhas[0]['categoria']);
    }

    #[TestDox('os números são os da apuração (LeituraDaAssembleia, a mesma de Resultados), não uma conta paralela')]
    public function testBatemComAApuracao(): void
    {
        $this->entrarComoMasterNoProprioEscritorio();
        $a = $this->assembleiaComPautas(TenantFactory::createOne(['name' => 'Escritório Beta ' . uniqid()])->_real(), 'Assembleia Beta ' . uniqid(), CenarioDaAssembleia::Ensino);
        $painel = static::getContainer()->get(LeituraDaAssembleia::class)->montar($a);

        $linhas = $this->linhasDa($this->dados('ensino'), 'Assembleia Beta');

        foreach (array_values(array_filter($painel->pautas, static fn ($p): bool => null === $p->canceladaEm && null === $p->retiradaEm)) as $i => $pauta) {
            self::assertSame($pauta->votantes, $linhas[$i]['votantes'], $pauta->titulo);
            self::assertSame($pauta->unidadesAptas, $linhas[$i]['aptos'], $pauta->titulo);
            if (null !== $pauta->resultado) {
                $esperado = bccomp($pauta->resultado->quorumExigido, '0', 2) <= 0 ? 'Não exigido' : ($pauta->resultado->quorumOk ? 'Atingido' : 'Não atingido');
                self::assertSame($esperado, $linhas[$i]['quorum'], $pauta->titulo);
            }
        }
    }

    #[TestDox('rascunho e simulação ficam fora; assembleia arquivada continua contando; outro escritório entra (visão de plataforma)')]
    public function testRascunhoSimulacaoEArquivada(): void
    {
        $this->entrarComoMasterNoProprioEscritorio();
        $outro = TenantFactory::createOne(['name' => 'Escritório Gama ' . uniqid()])->_real();
        $this->assembleiaSimples($outro, 'Votação real Gama', true, false);
        $this->assembleiaSimples($outro, 'Votação em rascunho Gama', false, false);
        $this->assembleiaSimples($outro, 'Votação de simulação Gama', true, true);
        $arquivada = $this->assembleiaSimples($outro, 'Votação arquivada Gama', true, false);
        $arquivada->arquivar('Teste', 'Fulano');
        $this->em->flush();

        $d = $this->dados('ensino');

        $nomes = array_column($d['linhas'], 'assembleia');
        self::assertContains('Votação real Gama', $nomes);
        self::assertContains('Votação arquivada Gama', $nomes);
        self::assertNotContains('Votação em rascunho Gama', $nomes);
        self::assertNotContains('Votação de simulação Gama', $nomes);
        self::assertSame($outro->getName(), $this->linhasDa($d, 'Votação real Gama')[0]['escritorio']);
    }

    #[TestDox('filtro por categoria: só a escolhida; vazio traz todas; categoria que não existe é 422')]
    public function testFiltroPorCategoria(): void
    {
        $this->entrarComoMasterNoProprioEscritorio();
        $t = TenantFactory::createOne(['name' => 'Escritório Delta ' . uniqid()])->_real();
        $this->assembleiaSimples($t, 'Delta condomínio', true, false, CenarioDaAssembleia::Condominio);
        $this->assembleiaSimples($t, 'Delta sindicato', true, false, CenarioDaAssembleia::Sindicato);

        $soSindicato = array_column($this->dados('sindicato')['linhas'], 'assembleia');
        $todas = array_column($this->dados('')['linhas'], 'assembleia');

        self::assertContains('Delta sindicato', $soSindicato);
        self::assertNotContains('Delta condomínio', $soSindicato);
        self::assertContains('Delta sindicato', $todas);
        self::assertContains('Delta condomínio', $todas);
        self::assertCount(count(CenarioDaAssembleia::cases()), $this->dados('sindicato')['categorias']);

        $this->client->request('GET', '/master/voto/estatisticas?categoria=inexistente');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha uma categoria da lista.', json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);
    }

    #[TestDox('CSV: BOM, cabeçalho, separador ";", valores entre aspas, fórmula neutralizada e nenhum dado de pessoa')]
    public function testCsv(): void
    {
        $this->entrarComoMasterNoProprioEscritorio();
        $t = TenantFactory::createOne(['name' => 'Escritório Épsilon ' . uniqid()])->_real();
        $a = $this->assembleiaComPautas($t, '=Assembleia Épsilon ' . uniqid(), CenarioDaAssembleia::Ensino);

        $this->client->request('GET', '/master/voto/estatisticas.csv?categoria=ensino');

        self::assertResponseIsSuccessful();
        $r = $this->client->getResponse();
        self::assertStringContainsString('text/csv', (string) $r->headers->get('Content-Type'));
        self::assertStringContainsString('estatisticas-votacoes.csv', (string) $r->headers->get('Content-Disposition'));
        $corpo = (string) $r->getContent();
        self::assertStringStartsWith("\u{FEFF}escritorio;entidade;assembleia;pauta;categoria;situacao;encerramento;aptos;votantes;participacao;quorum\n", $corpo);
        $linhas = array_values(array_filter(explode("\n", $corpo), static fn (string $l): bool => str_contains($l, 'Épsilon')));
        self::assertCount(4, $linhas);
        self::assertStringContainsString('"' . $t->getName() . '";"Cond. do Teste";"\'=Assembleia Épsilon', $linhas[0], 'fórmula ganha apóstrofo');
        self::assertStringContainsString('"Encerrada"', $linhas[0]);
        self::assertStringEndsWith('"4";"3";"75%";"Atingido"', $linhas[0]);
        self::assertCount(11, str_getcsv($linhas[0], ';'));
        $this->semDadosDePessoa($corpo, $a);
        $this->semDadosDePessoa((string) json_encode($this->dados('ensino')), $a);
    }

    #[TestDox('só o Master: usuário comum de escritório recebe 403 no JSON e no CSV')]
    public function testSoOMaster(): void
    {
        $tenant = TenantFactory::createOne(['name' => 'Escritório comum ' . uniqid()])->_real();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $this->em->persist(new UserTenant($user, $tenant));
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        foreach (['/master/voto/estatisticas', '/master/voto/estatisticas.csv'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    #[TestDox('a segunda camada: o UseCase recusa quem não é Master mesmo chamado direto')]
    public function testUseCaseExigeMaster(): void
    {
        $comum = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();

        $this->expectException(AccessDeniedException::class);

        static::getContainer()->get(ObterEstatisticasDoVotoUseCase::class)->executar($comum);
    }

    #[TestDox('tela: o parcial traz o título, o filtro Categoria, o botão Exportar CSV e a nota do desenho, sem travessão')]
    public function testTela(): void
    {
        $this->entrarComoMasterNoProprioEscritorio();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $sec = $crawler->filter('div.mp-voto-raiz > section.mp-voto-est[data-mp-voto-est]');
        self::assertCount(1, $sec);
        self::assertSame('/master/voto/estatisticas', $sec->attr('data-url-dados'));
        self::assertSame('Estatísticas de todas as votações', trim($sec->filter('.vC > .vH > span')->first()->text()));
        self::assertSame('Exportar CSV', trim($sec->filter('.vC > .vH > a.vB[data-mp-est-csv]')->text()));
        self::assertSame('/master/voto/estatisticas.csv', $sec->filter('a[data-mp-est-csv]')->attr('href'));
        self::assertStringStartsWith('Categoria', trim($sec->filter('label.vL')->first()->text()));
        self::assertCount(1, $sec->filter('label.vL > select.vF[data-mp-est-categoria]'));
        self::assertSame('Somente números agregados. O perfil não opera votações e não tem acesso ao voto de nenhuma pessoa.', trim($sec->filter('.mp-est-nota')->text()));
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $sec->text());
    }

    #[TestDox('JS e catálogo: as chaves usadas existem, sem travessão, e os rótulos dos KPIs são os do desenho')]
    public function testCatalogoDosKpis(): void
    {
        $tradutor = static::getContainer()->get('translator');
        $esperado = ['est_kpi_votacoes' => 'Votações', 'est_kpi_realizadas' => 'Realizadas', 'est_kpi_andamento' => 'Em andamento', 'est_kpi_aptos' => 'Aptos nas realizadas', 'est_kpi_votantes' => 'Votantes nas realizadas', 'est_kpi_participacao' => 'Participação nas realizadas', 'est_kpi_quorum' => 'Quórum atingido'];

        foreach ($esperado as $chave => $texto) {
            self::assertSame($texto, $tradutor->trans('master.js.voto.' . $chave, [], null, 'pt_BR'));
        }
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/voto.js');
        preg_match_all("/T\\('(master\\.js\\.voto\\.[a-z_]+)'/", $js, $m);
        foreach (array_unique($m[1]) as $chave) {
            self::assertNotSame($chave, $tradutor->trans($chave, [], null, 'pt_BR'), $chave);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function textosVisiveis(): iterable
    {
        foreach (['aguardando', 'aberta', 'encerrada'] as $e) {
            yield 'situação ' . $e => ['master.voto.estatisticas.situacao.' . $e];
        }
        foreach (['atingido', 'nao_atingido', 'nao_exigido'] as $q) {
            yield 'quórum ' . $q => ['master.voto.estatisticas.quorum.' . $q];
        }
    }

    #[DataProvider('textosVisiveis')]
    #[TestDox('texto do catálogo $chave existe e não tem travessão')]
    public function testTextoSemTravessao(string $chave): void
    {
        $texto = static::getContainer()->get('translator')->trans($chave, [], null, 'pt_BR');

        self::assertNotSame($chave, $texto);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $texto);
    }

    private function semDadosDePessoa(string $texto, Assembleia $a): void
    {
        foreach ($a->getUnidades() as $u) {
            self::assertStringNotContainsString((string) $u->getResponsavel(), $texto);
            self::assertStringNotContainsString((string) $u->getEmail(), $texto);
        }
    }

    /**
     * Quatro unidades; pautas: 1 encerrada com 3 votantes (quórum 50% atingido), 2 encerrada com 1 votante (não
     * atingido), 3 encerrada na 2ª convocação (quórum 0: não exigido) com 4, 4 aberta com 2, 5 cancelada e 6 retirada da ordem do dia (as duas ficam fora).
     */
    private function assembleiaComPautas(Tenant $tenant, string $titulo, CenarioDaAssembleia $cenario): Assembleia
    {
        $a = AssembleiaFactory::new(['tenant' => $tenant, 'titulo' => $titulo, 'cenario' => $cenario, 'entidadeNome' => 'Cond. do Teste'])->publicada()->create()->_real();
        $unidades = UnidadeDaAssembleiaFactory::createMany(4, ['assembleia' => $a]);
        $this->pauta($a, 1, $unidades, 3, true, 1);
        $this->pauta($a, 2, $unidades, 1, true, 1);
        $this->pauta($a, 3, $unidades, 4, true, 2);
        $this->pauta($a, 4, $unidades, 2, false, 1);
        $this->pauta($a, 5, $unidades, 0, false, 1)->cancelar('Teste', 'Mesa');
        $retirada = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 6, 'titulo' => 'Pauta 6'])->comOpcoesPadrao()->create()->_real();
        $retirada->retirarDePauta('Teste', 'Mesa');
        $this->em->flush();
        $this->em->refresh($a);

        return $a;
    }

    /** @param list<object> $unidades */
    private function pauta(Assembleia $a, int $ordem, array $unidades, int $votantes, bool $encerrada, int $convocacao): PautaDaAssembleia
    {
        $fabrica = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => $ordem, 'titulo' => 'Pauta ' . $ordem])->comOpcoesPadrao();
        $pauta = ($encerrada ? $fabrica->encerrada($convocacao) : $fabrica->aberta($convocacao))->create()->_real();
        $this->em->refresh($pauta);
        for ($i = 0; $i < $votantes; ++$i) {
            ParticipacaoNaPautaFactory::createOne(['unidade' => $unidades[$i], 'pauta' => $pauta]);
        }

        return $pauta;
    }

    private function assembleiaSimples(Tenant $tenant, string $titulo, bool $publicada, bool $simulacao, CenarioDaAssembleia $cenario = CenarioDaAssembleia::Ensino): Assembleia
    {
        $f = AssembleiaFactory::new(['tenant' => $tenant, 'titulo' => $titulo, 'cenario' => $cenario, 'simulacao' => $simulacao]);
        $a = ($publicada ? $f->publicada() : $f)->create()->_real();
        UnidadeDaAssembleiaFactory::createMany(2, ['assembleia' => $a]);
        PautaDaAssembleiaFactory::new(['assembleia' => $a, 'titulo' => 'Pauta única'])->comOpcoesPadrao()->encerrada()->create();
        $this->em->refresh($a);

        return $a;
    }

    /** @return array<string, mixed> */
    private function dados(string $categoria): array
    {
        $this->client->request('GET', '/master/voto/estatisticas' . ('' === $categoria ? '' : '?categoria=' . $categoria));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return list<array<string, mixed>> as linhas da assembleia que começa com o prefixo, na ordem das pautas
     */
    private function linhasDa(array $dados, string $prefixo): array
    {
        return array_values(array_filter($dados['linhas'], static fn (array $l): bool => str_contains($l['assembleia'], $prefixo)));
    }

    /** @param array<string, mixed> $linha */
    private function numeros(array $linha): string
    {
        $n = $linha['votantes'] . ' de ' . $linha['aptos'] . ' votaram (' . $linha['participacao'] . ')';

        return null === $linha['quorum'] ? $n : $n . ' · Quórum: ' . $linha['quorum'];
    }

    private function entrarComoMasterNoProprioEscritorio(): User
    {
        $tenant = TenantFactory::createOne(['name' => 'Escritório do Master ' . uniqid()])->_real();
        $master = UserFactory::createOne(['roles' => ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']])->_real();
        $this->em->persist(new UserTenant($master, $tenant));
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $tenant);

        return $master;
    }
}
