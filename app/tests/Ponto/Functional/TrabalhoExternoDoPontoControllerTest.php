<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\ConfiguracoesDoPontoController;
use App\Ponto\Controller\TrabalhoExternoDoPontoController;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\UseCase\AlterarTrabalhoExternoDoEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Twig\Loader\FilesystemLoader;

/**
 * "Permitir trabalho externo, com motivo e análise" na aba Configurações (spec ponto-desenho-0811 §5 E12, P74): a rota
 * POST /ponto/configuracoes/trabalho-externo, no molde da chave "Escritório" das funções (E7), e a variável que o painel
 * passa ao template. O CSRF é previsível (`TOKEN_<id>`).
 */
#[CoversClass(TrabalhoExternoDoPontoController::class)]
#[CoversClass(AlterarTrabalhoExternoDoEscritorioUseCase::class)]
#[CoversClass(ConfiguracoesDoPontoController::class)]
#[Group('ponto')]
final class TrabalhoExternoDoPontoControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const ROTA = '/ponto/configuracoes/trabalho-externo';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $primario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Config Externo');
        $this->primario = $this->pessoa('primario');
        $this->vincularPrimario($this->primario, $this->escritorio);
    }

    #[TestDox('Quem não administra o ponto recebe 403 `{erro}` antes do CSRF, e nada grava')]
    public function testSemPermissao(): void
    {
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->logarComTenant($this->client, $colaborador, $this->escritorio);

        $this->postar('{"permitir":false}', 'token-errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Só quem administra o ponto do escritório pode mudar esta configuração.'], $this->json());
        self::assertNull($this->configuracao($this->escritorio));
    }

    #[TestDox('CSRF inválido para quem pode: 403; corpo sem `permitir` booleano: 422 com a frase; nada grava')]
    public function testRecusasDoPedido(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->postar('{"permitir":false}', 'token-errado');
        self::assertResponseStatusCodeSame(403);

        foreach (['{}', '{"permitir":"false"}', '{"permitir":0}', 'não é json'] as $corpo) {
            $this->postar($corpo);
            self::assertResponseStatusCodeSame(422, $corpo);
            self::assertSame(['erro' => 'Informe se o trabalho externo fica permitido.'], $this->json());
        }
        self::assertNull($this->configuracao($this->escritorio));
    }

    #[TestDox('O primário desliga: a linha do escritório nasce com o ajuste, quem e quando, e a mudança vai para o audit_log; religar volta a permitir')]
    public function testDesligaEReliga(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->postar('{"permitir":false}');
        self::assertResponseIsSuccessful();
        self::assertSame(['permitir' => false], $this->json());
        $linha = $this->configuracao($this->escritorio);
        self::assertSame([false, $this->primario->getId(), '[]'], [$linha['permite_trabalho_externo'], $linha['atualizado_por_id'], $linha['chaves']]);
        self::assertCount(1, $this->emSemFiltro()->getRepository(AuditLog::class)->findBy(['entityClass' => ConfiguracaoDoPonto::class, 'tenantId' => $this->escritorio->getId()]));

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->postar('{"permitir":true}');
        self::assertResponseIsSuccessful();
        self::assertSame(['permitir' => true], $this->json());
        self::assertTrue($this->configuracao($this->escritorio)['permite_trabalho_externo']);
    }

    #[TestDox('O escritório é sempre o da sessão: o id no corpo não escolhe; no outro escritório, onde é colaborador, 403')]
    public function testEscritorioDaSessao(): void
    {
        $outro = $this->escritorio('Escritório Vizinho');
        $this->vincularColaborador($this->primario, $outro);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->postar('{"permitir":false,"tenant":' . $outro->getId() . '}');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->configuracao($this->escritorio)['permite_trabalho_externo']);
        self::assertNull($this->configuracao($outro));

        $this->logarComTenant($this->client, $this->primario, $outro);
        $this->postar('{"permitir":false}');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->configuracao($outro));
    }

    #[TestDox('O painel da aba Configurações passa `permite_trabalho_externo`: o padrão (permitido) sem linha, e o gravado depois')]
    public function testVariavelDoPainel(): void
    {
        self::assertSame('[sim]', $this->variavelDoPainel());

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->postar('{"permitir":false}');
        self::assertResponseIsSuccessful();

        self::assertSame('[nao]', $this->variavelDoPainel());
    }

    /**
     * O valor de `permite_trabalho_externo` que o painel passa ao template. O template da aba é de outra frente: aqui
     * um template de teste, na frente do de verdade no carregador do Twig, só escreve a variável.
     */
    private function variavelDoPainel(): string
    {
        $pasta = sys_get_temp_dir() . '/ponto_e12_cfg_' . bin2hex(random_bytes(6));
        mkdir($pasta . '/ponto', 0o777, true);
        file_put_contents($pasta . '/ponto/_abas_configuracoes.html.twig', '[{{ permite_trabalho_externo is same as(true) ? "sim" : (permite_trabalho_externo is same as(false) ? "nao" : "outro") }}]');
        $carregador = static::getContainer()->get('twig')->getLoader();
        self::assertInstanceOf(FilesystemLoader::class, $carregador);
        $carregador->prependPath($pasta);

        try {
            $this->logarComTenant($this->client, $this->primario, $this->escritorio);
            $this->client->request('GET', '/ponto/configuracoes');
            self::assertResponseIsSuccessful();

            return (string) $this->client->getResponse()->getContent();
        } finally {
            unlink($pasta . '/ponto/_abas_configuracoes.html.twig');
            rmdir($pasta . '/ponto');
            rmdir($pasta);
        }
    }

    private function postar(string $corpo, string $token = 'TOKEN_ajax'): void
    {
        $this->client->request('POST', self::ROTA, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $corpo);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed>|null a linha crua, sem o filtro do escritório */
    private function configuracao(Tenant $tenant): ?array
    {
        $linha = $this->emSemFiltro()->getConnection()->fetchAssociative('SELECT * FROM ponto_config_escritorio WHERE tenant_id = ?', [$tenant->getId()]);

        return $linha === false ? null : $linha;
    }
}
