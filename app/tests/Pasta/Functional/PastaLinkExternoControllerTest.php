<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Pasta\Controller\PastaLinkExternoController;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Rotas internas do link externo do Push (spec push-link-externo §3): contrato JSON e guarda
 * (outro escritório = 404, sem VER = 403, link de outra pasta = 404, CSRF = 403).
 */
#[CoversClass(PastaLinkExternoController::class)]
#[Group('pasta')]
final class PastaLinkExternoControllerTest extends JusPrimeWebTestCase
{
    use CriaLinkExternoDoPushTrait;
    use CriaUsuarioComPermissoesTrait;

    /** @return array<string, mixed> */
    private function corpo(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    #[TestDox('Gerar: 201 com o link (URL /p/, código de 6 dígitos, texto para copiar) e grava no banco')]
    public function testGerar(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPush($tenant);

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/links", [
            '_token' => 'TOKEN_pasta_push_link_' . $pasta->getId(),
            'exigir_codigo' => '1',
            'mostrar_cliente' => '0',
            'incluir_analises' => '1',
        ]);

        self::assertResponseStatusCodeSame(201);
        $link = $this->corpo($client)['link'];
        self::assertMatchesRegularExpression('#^http://localhost/p/bluejus-processo-0701134-[a-z2-9]{8}$#', $link['url']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $link['codigo']);
        self::assertSame($link['url'] . "\nCódigo de acesso: " . $link['codigo'], $link['textoParaCopiar']);
        self::assertSame('ativo', $link['estado']);
        self::assertFalse($link['incluirAnalises'], 'sem análise compartilhável, vira false');
        self::assertSame(
            ['id', 'estado', 'estadoRotulo', 'endereco', 'url', 'codigo', 'comCodigo', 'mostrarCliente', 'incluirAnalises', 'criadoPor', 'criadoEm', 'expiraEm', 'revogadoEm', 'renovacao', 'acessos', 'podeRevogar', 'podeRenovar', 'podeRecusar', 'textoParaCopiar'],
            array_keys($link),
        );
        self::assertNotNull($this->em()->getRepository(LinkExternoDoPush::class)->find($link['id']));
    }

    #[TestDox('Sem o módulo de IA: a lista conta 0 análises e incluir_analises=1 é ignorado')]
    public function testSemModuloDeIaNaoPublicaAnalise(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPush($tenant);
        $analise = new AnaliseDeInteligencia($tenant, null, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, (int) $pasta->getId(), 'v1', str_repeat('a', 64), []);
        $analise->iniciarProcessamento();
        $analise->concluir('Resumo', [], null, '{}', 'falso', 'falso', null, null, null);
        $analise->alternarInterna();
        $this->em()->persist($analise);
        $this->em()->flush();
        $soPasta = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $soPasta, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/links");
        self::assertSame(0, $this->corpo($client)['analisesCompartilhaveis']);

        $client->request('POST', "/pasta/{$pasta->getId()}/push/links", [
            '_token' => 'TOKEN_pasta_push_link_' . $pasta->getId(),
            'incluir_analises' => '1',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->corpo($client)['link']['incluirAnalises']);
    }

    #[TestDox('Gerar sem movimentação: 422 com o texto do desenho')]
    public function testGerarBloqueado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_DO_LINK));

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/links", ['_token' => 'TOKEN_pasta_push_link_' . $pasta->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Ainda não há movimentações para compartilhar.'], $this->corpo($client));
        self::assertArrayNotHasKey('confirmacao', $this->corpo($client), 'outro motivo de 422 não leva o reenvio da confirmação');
    }

    /**
     * Desde a spec `cadastro-exige-confirmacao.md`, a conta a confirmar não fica logada: a sessão termina antes do
     * controller. A recusa do caso de uso fica como defesa, provada no `GerarLinkExternoDoPushUseCaseTest`.
     */
    #[TestDox('Gerar com o e-mail da conta a confirmar: a sessão termina antes, e nada é gravado')]
    public function testGerarComEmailAConfirmar(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPush($tenant);
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/links", ['_token' => 'TOKEN_pasta_push_link_' . $pasta->getId()]);

        self::assertResponseRedirects('/login');
        self::assertNull($client->getContainer()->get('security.token_storage')->getToken());
        self::assertSame(0, $this->em()->getRepository(LinkExternoDoPush::class)->count(['pasta' => $pasta->getId()]));
    }

    #[TestDox('Listar: links mais recentes primeiro, acessos agregados, resumo e "pode gerar"')]
    public function testListar(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPush($tenant);
        $antigo = $this->criarLink($tenant, $pasta, $processo, $user, null, '-40 days');
        $novo = $this->criarLink($tenant, $pasta, $processo, $user, '123456', '-1 day');
        $this->registrarAcesso($novo, '-2 hours');
        $this->registrarAcesso($novo, '-1 hour');

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/links");

        self::assertResponseIsSuccessful();
        $corpo = $this->corpo($client);
        self::assertSame([$novo->getId(), $antigo->getId()], array_column($corpo['links'], 'id'));
        self::assertSame(['ativo', 'expirado'], array_column($corpo['links'], 'estado'));
        self::assertSame('123456', $corpo['links'][0]['codigo']);
        self::assertSame(2, $corpo['links'][0]['acessos']['total']);
        self::assertCount(2, $corpo['links'][0]['acessos']['ultimos']);
        self::assertSame(2, $corpo['totalLinks']);
        self::assertSame(1, $corpo['totalAtivos']);
        self::assertSame(2, $corpo['totalAcessos']);
        self::assertStringStartsWith('2 links · 1 ativo · último ', $corpo['resumo']);
        self::assertTrue($corpo['podeGerar']);
        self::assertNull($corpo['motivoBloqueio']);
        self::assertSame(0, $corpo['analisesCompartilhaveis']);
    }

    #[TestDox('Pasta de outro escritório: 404 (nunca 403)')]
    public function testOutroEscritorio(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [, $outroTenant] = $this->criarAdmin();
        $pastaAlheia = $this->criarPasta($outroTenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pastaAlheia->getId()}/push/links");

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem permissão de VER a pasta: 403')]
    public function testSemPermissao(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $semNada = $this->criarUsuarioSemNenhumaPermissao($tenant);
        [$pasta] = $this->criarPastaComPush($tenant);

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/push/links");

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Link de OUTRA pasta do mesmo escritório: 404 pela pasta errada, 200 pela certa')]
    public function testLinkDeOutraPasta(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPush($tenant);
        [$irma, $processoIrma] = $this->criarPastaComPush($tenant, 'Teor da irmã', '07099999920258070007');
        $linkDaIrma = $this->criarLink($tenant, $irma, $processoIrma, $user);

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/links/{$linkDaIrma->getId()}/revogar", ['_token' => 'TOKEN_pasta_push_link_' . $pasta->getId()]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$irma->getId()}/push/links/{$linkDaIrma->getId()}/revogar", ['_token' => 'TOKEN_pasta_push_link_' . $irma->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame('revogado', $this->corpo($client)['link']['estado']);
    }

    #[TestDox('CSRF inválido: 403 e o link continua ativo')]
    public function testCsrfInvalido(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPush($tenant);
        $link = $this->criarLink($tenant, $pasta, $processo, $user);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/push/links/{$link->getId()}/revogar", ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->em()->find(LinkExternoDoPush::class, $link->getId())?->estaRevogado());
    }

    #[TestDox('Renovar expirado com pedido e recusar: estados e mensagens; renovar ativo é 422')]
    public function testRenovarERecusar(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPush($tenant);
        $paraRenovar = $this->criarLink($tenant, $pasta, $processo, $user, null, '-40 days');
        $paraRecusar = $this->criarLink($tenant, $pasta, $processo, $user, null, '-40 days');
        $ativo = $this->criarLink($tenant, $pasta, $processo, $user);
        $paraRecusar->pedirRenovacao('Carlos', null, new \DateTimeImmutable());
        $this->em()->flush();
        $token = ['_token' => 'TOKEN_pasta_push_link_' . $pasta->getId()];
        $base = "/pasta/{$pasta->getId()}/push/links";

        $this->instalarCsrfPrevisivel();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "{$base}/{$paraRenovar->getId()}/renovar", $token);
        self::assertResponseIsSuccessful();
        self::assertSame('ativo', $this->corpo($client)['link']['estado']);

        $client->request('POST', "{$base}/{$paraRecusar->getId()}/recusar", $token);
        self::assertResponseIsSuccessful();
        self::assertSame('recusado', $this->corpo($client)['link']['renovacao']['resposta']);

        $client->request('POST', "{$base}/{$ativo->getId()}/renovar", $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Só um link expirado pode ser renovado.'], $this->corpo($client));
    }
}
