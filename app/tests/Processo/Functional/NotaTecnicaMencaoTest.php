<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\CotaDeNotificacoesDoRegistro;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Processo\Controller\NotaTecnicaController;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\UseCase\CriarNotaTecnicaUseCase;
use App\Processo\UseCase\EditarNotaTecnicaUseCase;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * PMC-019: @menção nas notas técnicas — o mesmo formato, a mesma conferência de ids e a mesma
 * cota do Registro da pasta (`ResolucaoDeMencoesDoRegistro`, `CotaDeNotificacoesDoRegistro`),
 * com aviso próprio (`TIPO_NOTA_TECNICA_MENCAO`) para quem VÊ a pasta de onde a nota foi escrita,
 * e link para o cartão da nota (`#nota-tecnica-<id>`).
 *
 * Os "membros" são ROLE_SUPER_ADMIN: veem toda pasta do escritório. O "sem acesso" é um papel
 * comum sem permissão nenhuma — é ele quem prova o filtro de acesso.
 */
#[CoversClass(NotaTecnicaController::class)]
#[CoversClass(CriarNotaTecnicaUseCase::class)]
#[CoversClass(EditarNotaTecnicaUseCase::class)]
#[CoversClass(ResolucaoDeMencoesDoRegistro::class)]
#[CoversClass(CotaDeNotificacoesDoRegistro::class)]
#[Group('processo')]
final class NotaTecnicaMencaoTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';

    private function membro(Tenant $tenant, string $nome): User
    {
        $user = new User();
        $user->setEmail('nt_menc_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** @return array{Pasta, Processo} pasta do escritório vinculando um processo dele */
    private function pastaComProcesso(Tenant $tenant): array
    {
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);

        return [$pasta, $processo];
    }

    /** @return array<string, mixed> o JSON da criação */
    private function criar(KernelBrowser $client, Processo $processo, Pasta $pasta, string $conteudo): array
    {
        $client->request('POST', '/processos/' . $processo->getId() . '/nota-tecnica', [
            '_token'   => 'TOKEN_processo_nota_tecnica_' . $processo->getId(),
            'pasta_id' => $pasta->getId(),
            'conteudo' => $conteudo,
        ]);

        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> o JSON da edição */
    private function editar(KernelBrowser $client, Processo $processo, Pasta $pasta, int $notaId, string $conteudo): array
    {
        $client->request('POST', '/processos/' . $processo->getId() . '/nota-tecnica/' . $notaId . '/editar', [
            '_token'   => 'TOKEN_processo_nota_tecnica_editar_' . $notaId,
            'pasta_id' => $pasta->getId(),
            'conteudo' => $conteudo,
        ]);

        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    /**
     * O TenantFilter segue ligado com o escritório da sessão depois do pedido: sem desligá-lo, a
     * contagem de quem é de OUTRO escritório daria zero por FILTRO, e o teste provaria outra coisa.
     */
    private function desligarFiltroDeTenant(): void
    {
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
    }

    /** @return Notificacao[] */
    private function mencoesRecebidas(User $usuario): array
    {
        $this->desligarFiltroDeTenant();

        return $this->em()->createQuery(
            'SELECT n FROM ' . Notificacao::class . ' n WHERE n.usuario = :u AND n.tipo = :t ORDER BY n.id ASC'
        )
            ->setParameter('u', $usuario)
            ->setParameter('t', Notificacao::TIPO_NOTA_TECNICA_MENCAO)
            ->getResult();
    }

    /** Toda notificação de menção (nota ou Registro) que alguém recebeu — para provar o "nada". */
    private function qualquerMencao(User $usuario): int
    {
        $this->desligarFiltroDeTenant();

        return (int) $this->em()->createQuery(
            'SELECT COUNT(n.id) FROM ' . Notificacao::class . ' n WHERE n.usuario = :u AND n.tipo IN (:t)'
        )
            ->setParameter('u', $usuario)
            ->setParameter('t', [Notificacao::TIPO_NOTA_TECNICA_MENCAO, Notificacao::TIPO_PASTA_MENCAO_REGISTRO])
            ->getSingleScalarResult();
    }

    // ── Criação ──────────────────────────────────────────────────────────────

    #[TestDox('menção na nota avisa o colega que VÊ a pasta (link #nota-tecnica-<id>); sem acesso, o autor e outro escritório não')]
    public function testMencaoNotificaQuemVeAPasta(): void
    {
        $client  = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenantA   = $this->criarTenant();
        $tenantB   = $this->criarTenant();
        $eu        = $this->membro($tenantA, 'Ana Paula Souza');
        $bruno     = $this->membro($tenantA, 'Bruno Lima');
        $semAcesso = $this->criarUsuarioSemNenhumaPermissao($tenantA);
        $deFora    = $this->membro($tenantB, 'Carla de Fora');
        [$pasta, $processo] = $this->pastaComProcesso($tenantA);

        $this->logarComTenant($client, $eu, $tenantA);
        $dados = $this->criar($client, $processo, $pasta, sprintf(
            '<p>Veja @[Bru](user:%d), @[S](user:%d), @[Eu](user:%d) e @[Carla](user:%d)</p>',
            $bruno->getId(),
            $semAcesso->getId(),
            $eu->getId(),
            $deFora->getId(),
        ));

        self::assertResponseStatusCodeSame(201);
        $notaId = (int) $dados['id'];

        $doBruno = $this->mencoesRecebidas($bruno);
        self::assertCount(1, $doBruno);
        self::assertSame('Ana mencionou você na Pasta ' . $pasta->getNup() . ' · Processo', $doBruno[0]->getTitulo());
        self::assertSame('/pasta/' . $pasta->getId() . '#nota-tecnica-' . $notaId, $doBruno[0]->getUrl());
        self::assertSame($tenantA->getId(), $doBruno[0]->getTenant()?->getId(), 'o aviso é do escritório da nota');
        self::assertSame('bi-at text-primary', $doBruno[0]->getIcone());
        self::assertStringContainsString('@Bruno Lima', (string) $doBruno[0]->getMensagem());

        self::assertSame(0, $this->qualquerMencao($semAcesso), 'colega sem acesso à pasta não recebe');
        self::assertSame(0, $this->qualquerMencao($eu), 'quem escreveu não recebe');
        self::assertSame(0, $this->qualquerMencao($deFora), 'outro escritório nunca recebe');

        // Gravado: Bruno com o nome do banco; o id de outro escritório virou texto, sem o nome de lá.
        $nota = $this->em()->find(NotaTecnica::class, $notaId);
        self::assertNotNull($nota);
        self::assertStringContainsString('@[Bruno Lima](user:' . $bruno->getId() . ')', $nota->getConteudo());
        self::assertStringNotContainsString('(user:' . $deFora->getId() . ')', $nota->getConteudo());
        self::assertStringNotContainsString('Carla de Fora', $nota->getConteudo());

        // A resposta (e o cartão pronto) já trazem o destaque.
        $destaque = '<span class="ps-mencao" data-user-id="' . $bruno->getId() . '">@Bruno Lima</span>';
        self::assertStringContainsString($destaque, (string) $dados['conteudoHtml']);
        self::assertStringContainsString($destaque, (string) $dados['html']);

        // O link abre a pasta para o mencionado: o cartão da âncora está na aba Processo, com o destaque.
        $this->logarComTenant($client, $bruno, $tenantA);
        $crawler = $client->request('GET', (string) strtok((string) $doBruno[0]->getUrl(), '#'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#processo #nota-tecnica-' . $notaId), 'a âncora do aviso existe na aba Processo');
        self::assertSame('@Bruno Lima', trim($crawler->filter('#nota-tecnica-' . $notaId . ' > .ps-nota-texto .ps-mencao')->text()));
    }

    #[TestDox('o bloco de notas aponta o "@" para a lista de mencionáveis DESTA pasta e o placeholder do desenho cita a menção')]
    public function testBlocoApontaAListaDaPasta(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $eu      = $this->membro($tenant, 'Ana');
        [$pasta, $processo] = $this->pastaComProcesso($tenant);

        $this->logarComTenant($client, $eu, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $bloco = $crawler->filter('#notas-processo-' . $processo->getId());
        self::assertSame('/pasta/' . $pasta->getId() . '/mencionaveis', $bloco->attr('data-url-mencionaveis'));
        self::assertSame(
            'Explique o processo ou o despacho: o que significa, o que precisa ser feito, prazo… Use @ para mencionar alguém.',
            $bloco->filter('.ps-nota-editor textarea')->attr('placeholder'),
        );
        // Não reaproveita o `[data-mencionaveis-url]`, que o pasta-mencoes.js liga ao Registro.
        self::assertNull($bloco->attr('data-mencionaveis-url'));
    }

    // ── Isolamento ───────────────────────────────────────────────────────────

    #[TestDox('processo de OUTRO escritório pelo id → 404, nada gravado e ninguém avisado (nem lá, nem cá)')]
    public function testProcessoDeOutroEscritorioRetorna404SemAviso(): void
    {
        $client  = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->membro($tenantA, 'Ana');
        $bruno   = $this->membro($tenantA, 'Bruno Lima');
        $deLa    = $this->membro($tenantB, 'Diego de Lá');
        [$pastaB, $processoB] = $this->pastaComProcesso($tenantB);

        $this->logarComTenant($client, $eu, $tenantA);
        $this->criar($client, $processoB, $pastaB, sprintf('<p>@[B](user:%d) @[D](user:%d)</p>', $bruno->getId(), $deLa->getId()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->qualquerMencao($bruno));
        self::assertSame(0, $this->qualquerMencao($deLa));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM nota_tecnica WHERE processo_id = :p',
            ['p' => $processoB->getId()],
        ), 'nada gravado no processo alheio');
    }

    #[TestDox('pasta de OUTRO escritório como contexto de um processo próprio → 404 e ninguém avisado')]
    public function testPastaDeOutroEscritorioComoContextoRetorna404SemAviso(): void
    {
        $client  = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->membro($tenantA, 'Ana');
        $bruno   = $this->membro($tenantA, 'Bruno Lima');
        [, $processo] = $this->pastaComProcesso($tenantA);
        $pastaB  = $this->criarPasta($tenantB);

        $this->logarComTenant($client, $eu, $tenantA);
        $this->criar($client, $processo, $pastaB, sprintf('<p>@[B](user:%d)</p>', $bruno->getId()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->qualquerMencao($bruno));
    }

    // ── Edição ───────────────────────────────────────────────────────────────

    #[TestDox('editar com token forjado ou id de OUTRO escritório grava texto comum, sem destaque e sem avisar')]
    public function testEdicaoComTokenForjadoViraTexto(): void
    {
        $client  = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->membro($tenantA, 'Ana');
        $deFora  = $this->membro($tenantB, 'Carla de Fora');
        [$pasta, $processo] = $this->pastaComProcesso($tenantA);

        $this->logarComTenant($client, $eu, $tenantA);
        $notaId = (int) $this->criar($client, $processo, $pasta, '<p>Original</p>')['id'];
        self::assertResponseStatusCodeSame(201);

        $dados = $this->editar($client, $processo, $pasta, $notaId, '<p>Falar com @[Diretor Geral](user:9999999999) e @[Carla](user:' . $deFora->getId() . ')</p>');

        self::assertResponseIsSuccessful();
        self::assertSame('<p>Falar com @Diretor Geral e @Carla</p>', $dados['conteudo']);
        self::assertStringNotContainsString('ps-mencao', (string) $dados['conteudoHtml']);
        self::assertSame(0, $this->qualquerMencao($deFora));
    }

    #[TestDox('editar avisa quem PASSOU a ser mencionado; quem já foi avisado por esta nota não recebe de novo')]
    public function testEdicaoNotificaSoMencaoNova(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $eu     = $this->membro($tenant, 'Ana');
        $bruno  = $this->membro($tenant, 'Bruno Lima');
        $carla  = $this->membro($tenant, 'Carla Dias');
        [$pasta, $processo] = $this->pastaComProcesso($tenant);

        $this->logarComTenant($client, $eu, $tenant);
        $notaId = (int) $this->criar($client, $processo, $pasta, 'Oi @[Bruno](user:' . $bruno->getId() . ')')['id'];
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->mencoesRecebidas($bruno));

        $this->editar($client, $processo, $pasta, $notaId, 'Oi @[Bruno](user:' . $bruno->getId() . ') e @[Carla](user:' . $carla->getId() . ')');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $this->mencoesRecebidas($bruno), 'já avisado por esta nota: não recebe de novo');
        $daCarla = $this->mencoesRecebidas($carla);
        self::assertCount(1, $daCarla);
        self::assertSame('/pasta/' . $pasta->getId() . '#nota-tecnica-' . $notaId, $daCarla[0]->getUrl());
        self::assertSame(0, $this->qualquerMencao($eu));
    }

    // ── Cota de avisos do autor (SEC-008 / CotaDeNotificacoesDoRegistro) ─────
    //
    // Como no PastaMencaoTest: o pool do limiter é zerado a cada pedido em teste, então o dublê no
    // container é o que torna observável a recusa (e a chave consumida).

    #[TestDox('cota estourada — a nota é gravada (201) com a menção, e o mencionado NÃO recebe aviso')]
    public function testCotaEstouradaGravaNotaSemAviso(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $eu     = $this->membro($tenant, 'Ana Paula Souza');
        $bruno  = $this->membro($tenant, 'Bruno Lima');
        [$pasta, $processo] = $this->pastaComProcesso($tenant);

        $this->logarComTenant($client, $eu, $tenant);
        static::getContainer()->set('limiter.pasta_registro_notificacao', new RateLimiterFactoryEspiao(aceita: false));
        $dados = $this->criar($client, $processo, $pasta, sprintf('<p>Veja @[Bru](user:%d)</p>', $bruno->getId()));

        self::assertResponseStatusCodeSame(201);
        $nota = $this->em()->find(NotaTecnica::class, (int) $dados['id']);
        self::assertNotNull($nota, 'a nota não falha por causa do teto');
        self::assertStringContainsString('@[Bruno Lima](user:' . $bruno->getId() . ')', $nota->getConteudo());
        self::assertCount(0, $this->mencoesRecebidas($bruno), 'aviso suprimido');
    }

    #[TestDox('cota com folga — o mencionado recebe, e a cota consumida é a do AUTOR (a mesma do Registro)')]
    public function testCotaComFolgaNotificaComAChaveDoAutor(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $eu     = $this->membro($tenant, 'Ana Paula Souza');
        $bruno  = $this->membro($tenant, 'Bruno Lima');
        [$pasta, $processo] = $this->pastaComProcesso($tenant);

        $this->logarComTenant($client, $eu, $tenant);
        $espiao = new RateLimiterFactoryEspiao(aceita: true);
        static::getContainer()->set('limiter.pasta_registro_notificacao', $espiao);
        $this->criar($client, $processo, $pasta, sprintf('<p>Veja @[Bru](user:%d)</p>', $bruno->getId()));

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->mencoesRecebidas($bruno));
        self::assertSame([(string) $eu->getId()], $espiao->chavesConsumidas);
    }
}
