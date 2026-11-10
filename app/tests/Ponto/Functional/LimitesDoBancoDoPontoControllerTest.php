<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\ConfiguracoesDoPontoController;
use App\Ponto\Controller\LimitesDoBancoDoPontoController;
use App\Ponto\DTO\AlterarLimitesDoBancoInput;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Service\GestaoDoPonto;
use App\Ponto\Service\LimitesDoBancoDeHoras;
use App\Ponto\UseCase\AlterarLimitesDoBancoDoEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Limite do banco (horas)" e "Prazo de compensação (meses)" na aba Configurações (spec ponto-desenho-0811 §5 E17, P73;
 * desenho 16 L245, L250 e L251): a grade do desenho logo depois das funções, com o valor do escritório (ou o padrão), e
 * a rota POST /ponto/configuracoes/banco-de-horas, no molde da caixa do trabalho externo (E12). O arranjo é conferido
 * por filho direto (`>`), como em CarteiraArranjoTelaTest. O CSRF é previsível (`TOKEN_<id>`).
 */
#[CoversClass(LimitesDoBancoDoPontoController::class)]
#[CoversClass(AlterarLimitesDoBancoDoEscritorioUseCase::class)]
#[CoversClass(ConfiguracoesDoPontoController::class)]
#[Group('ponto')]
final class LimitesDoBancoDoPontoControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const ROTA = '/ponto/configuracoes/banco-de-horas';
    private const GRADE = '.pe-config > .pe-config-funcoes[data-config-funcoes] + div.pe-config-grade[data-config-banco]';
    private const LIMITE = self::GRADE . ' > label.pe-config-rotulo:first-child > input.pe-sf-campo[data-config-banco-campo="limiteHoras"]';
    private const PRAZO = self::GRADE . ' > label.pe-config-rotulo:first-child + label.pe-config-rotulo:last-child > input.pe-sf-campo[data-config-banco-campo="prazoMeses"]';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $primario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Config Banco');
        $this->primario = $this->pessoa('primario');
        $this->vincularPrimario($this->primario, $this->escritorio);
    }

    #[TestDox('A aba mostra os dois campos na grade do desenho, filha direta do painel logo depois das funções, com o padrão 120 e 6')]
    public function testCamposNaGradeComOPadrao(): void
    {
        $painel = $this->painel($this->primario);

        $grade = $painel->filter(self::GRADE);
        self::assertCount(1, $grade, 'a grade é filha direta do painel, logo depois das funções');
        self::assertSame(self::ROTA, $grade->attr('data-url'), 'a rota ponto_configuracao_banco_de_horas');
        self::assertCount(2, $grade->filter('div.pe-config-grade > label.pe-config-rotulo'), 'só os dois campos do banco: os da facial chegam com a E18');
        self::assertSame(['Limite do banco (horas)', 'Prazo de compensação (meses)'], $grade->filter('div.pe-config-grade > label.pe-config-rotulo')->each(
            static fn (Crawler $rotulo): string => trim((string) $rotulo->getNode(0)?->firstChild?->textContent),
        ));

        $limite = $painel->filter(self::LIMITE);
        self::assertCount(1, $limite);
        self::assertSame(['number', '0', '10', null, '120', '120'], [$limite->attr('type'), $limite->attr('min'), $limite->attr('step'), $limite->attr('max'), $limite->attr('value'), $limite->attr('data-gravado')], 'min="0" step="10" como o desenho L250');
        $prazo = $painel->filter(self::PRAZO);
        self::assertCount(1, $prazo);
        self::assertSame(['number', '1', '24', '6', '6'], [$prazo->attr('type'), $prazo->attr('min'), $prazo->attr('max'), $prazo->attr('value'), $prazo->attr('data-gravado')], 'min="1" max="24" como o desenho L251');

        foreach ([self::LIMITE, self::PRAZO] as $campo) {
            self::assertCount(1, $painel->filter($campo . ' + span.pe-config-banco-erro[role="alert"][data-config-banco-erro][hidden]:last-child'), 'a frase de erro embaixo do campo, escondida');
        }
        self::assertCount(0, $grade->filter('.form-control, .form-label, .form-group, .row, [class*="col-"], .btn, .input-group'), 'os componentes do desenho, nada do Bootstrap');
        self::assertCount(1, $painel->filter(self::GRADE . ' + div.pe-config-externo[data-config-externo]'), 'depois da grade vem a caixa do trabalho externo (o cabeçalho das sedes no desenho)');
    }

    #[TestDox('A aba mostra o valor gravado do escritório')]
    public function testCamposComOValorGravado(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->postar('{"limiteHoras":200,"prazoMeses":12}');
        self::assertResponseIsSuccessful();

        $painel = $this->painel($this->primario);

        self::assertSame(['200', '200'], [$painel->filter(self::LIMITE)->attr('value'), $painel->filter(self::LIMITE)->attr('data-gravado')]);
        self::assertSame(['12', '12'], [$painel->filter(self::PRAZO)->attr('value'), $painel->filter(self::PRAZO)->attr('data-gravado')]);
    }

    #[TestDox('Quem só cuida dos feriados vê a aba sem a grade, como sem as chaves das funções e sem a caixa do trabalho externo')]
    public function testSemAGradeParaQuemNaoAdministra(): void
    {
        $feriados = $this->pessoa('feriados');
        $this->vincular($feriados, $this->escritorio, ['modules.ponto.view', GestaoDoPonto::FERIADOS], false);

        $painel = $this->painel($feriados);

        self::assertCount(0, $painel->filter('[data-config-banco]'));
        self::assertCount(0, $painel->filter('[data-config-funcoes], [data-config-externo]'));
    }

    #[TestDox('Quem não administra o ponto recebe 403 `{erro}` antes do CSRF, e nada grava')]
    public function testSemPermissao(): void
    {
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $this->escritorio);
        $this->logarComTenant($this->client, $colaborador, $this->escritorio);

        $this->postar('{"limiteHoras":150}', 'token-errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Só quem administra o ponto do escritório pode mudar esta configuração.'], $this->json());
        self::assertNull($this->configuracao($this->escritorio));
    }

    #[TestDox('CSRF inválido para quem pode: 403; corpo sem número inteiro: 422 "Informe um número inteiro."; nada grava')]
    public function testRecusasDoPedido(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->postar('{"limiteHoras":150}', 'token-errado');
        self::assertResponseStatusCodeSame(403);

        foreach (['{}', '{"limiteHoras":null}', '{"limiteHoras":"150"}', '{"limiteHoras":12.5}', '{"prazoMeses":"6"}', 'não é json'] as $corpo) {
            $this->postar($corpo);
            self::assertResponseStatusCodeSame(422, $corpo);
            self::assertSame(['erro' => 'Informe um número inteiro.'], $this->json(), $corpo);
        }
        self::assertNull($this->configuracao($this->escritorio));
    }

    #[TestDox('Fora da faixa: 422 com a frase simples (limite 0, negativo ou acima de 9999; prazo 0 ou 25); nada grava')]
    public function testForaDaFaixa(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $casos = [
            '{"limiteHoras":0}'     => 'O limite do banco precisa ser de pelo menos 1 hora.',
            '{"limiteHoras":-10}'   => 'O limite do banco precisa ser de pelo menos 1 hora.',
            '{"limiteHoras":10000}' => 'O limite do banco pode ser de até 9999 horas.',
            '{"prazoMeses":0}'      => 'O prazo de compensação precisa ser de 1 a 24 meses.',
            '{"prazoMeses":25}'     => 'O prazo de compensação precisa ser de 1 a 24 meses.',
        ];
        foreach ($casos as $corpo => $frase) {
            $this->postar($corpo);
            self::assertResponseStatusCodeSame(422, $corpo);
            self::assertSame(['erro' => $frase], $this->json(), $corpo);
        }
        self::assertNull($this->configuracao($this->escritorio));
    }

    #[TestDox('O primário grava o limite e depois o prazo: a linha nasce com quem e quando, a mudança vai para o audit_log e não para a trilha encadeada (D5)')]
    public function testGravaLimiteEPrazo(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->postar('{"limiteHoras":150}');
        self::assertResponseIsSuccessful();
        self::assertSame(['limiteHoras' => 150, 'prazoMeses' => 6], $this->json());
        $linha = $this->configuracao($this->escritorio);
        self::assertNotNull($linha);
        self::assertSame([150, 6, $this->primario->getId(), '[]', true], [$linha['banco_limite_horas'], $linha['banco_prazo_meses'], $linha['atualizado_por_id'], $linha['chaves'], $linha['permite_trabalho_externo']]);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->postar('{"prazoMeses":24}');
        self::assertResponseIsSuccessful();
        self::assertSame(['limiteHoras' => 150, 'prazoMeses' => 24], $this->json());
        $linha = $this->configuracao($this->escritorio);
        self::assertSame([150, 24], [$linha['banco_limite_horas'] ?? null, $linha['banco_prazo_meses'] ?? null], 'o limite gravado antes fica');

        self::assertCount(2, $this->emSemFiltro()->getRepository(AuditLog::class)->findBy(['entityClass' => ConfiguracaoDoPonto::class, 'tenantId' => $this->escritorio->getId()]));
        self::assertSame(0, (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_auditoria WHERE tenant_id = ?', [$this->escritorio->getId()]));
    }

    #[TestDox('O escritório é sempre o da sessão: o id no corpo não escolhe; no outro escritório, onde é colaborador, 403')]
    public function testEscritorioDaSessao(): void
    {
        $outro = $this->escritorio('Escritório Vizinho Banco');
        $this->vincularColaborador($this->primario, $outro);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->postar('{"limiteHoras":300,"tenant":' . $outro->getId() . ',"tenantId":' . $outro->getId() . '}');
        self::assertResponseIsSuccessful();
        self::assertSame(300, $this->configuracao($this->escritorio)['banco_limite_horas'] ?? null);
        self::assertNull($this->configuracao($outro));

        $this->logarComTenant($this->client, $this->primario, $outro);
        $this->postar('{"limiteHoras":300}');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->configuracao($outro));
    }

    #[TestDox('Depois de gravar pela rota, a leitura do banco (LimitesDoBancoDeHorasDoEscritorio) devolve o valor novo')]
    public function testLeituraDepoisDaRota(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->postar('{"limiteHoras":40,"prazoMeses":3}');
        self::assertResponseIsSuccessful();

        $limites = static::getContainer()->get(LimitesDoBancoDeHoras::class);
        $escritorio = $this->emSemFiltro()->find(Tenant::class, $this->escritorio->getId());
        self::assertNotNull($escritorio);

        self::assertSame(40 * 60, $limites->limiteEmMinutos($escritorio));
        self::assertSame(3, $limites->prazoEmMeses($escritorio));
    }

    #[TestDox('No mesmo pedido: quem leu o padrão antes (sem linha) lê o valor novo depois de gravar')]
    public function testLeituraNoMesmoPedido(): void
    {
        $limites = static::getContainer()->get(LimitesDoBancoDeHoras::class);
        self::assertSame(6, $limites->prazoEmMeses($this->escritorio), 'sem linha, o padrão');
        self::assertSame(120 * 60, $limites->limiteEmMinutos($this->escritorio));

        $alterar = static::getContainer()->get(AlterarLimitesDoBancoDoEscritorioUseCase::class);
        $alterar->executar(new AlterarLimitesDoBancoInput($this->primario, $this->escritorio, 80, 9));

        self::assertSame(9, $limites->prazoEmMeses($this->escritorio));
        self::assertSame(80 * 60, $limites->limiteEmMinutos($this->escritorio));
    }

    private function painel(User $quem): Crawler
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $painel = $this->client->request('GET', '/ponto/configuracoes');
        self::assertResponseIsSuccessful();

        return $painel;
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
