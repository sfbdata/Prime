<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaAdministrativaController;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PAS-049 — na aba Processo da pasta, as ações que escrevem no vínculo só aparecem para quem o
 * servidor deixaria usá-las: as quatro rotas (`pasta_administrativa_definir`,
 * `pasta_vincular_processo`, `pasta_definir_processo_principal`, `pasta_desvincular_processo`)
 * pedem EDIT na pasta, e a lápide recusa todo POST. Vale também para o cartão que volta pelo XHR.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(PastaAdministrativaController::class)]
#[Group('pasta')]
final class PastaProcessoAcoesPermissaoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    /** @return array{Pasta, Processo} pasta com dois processos; o devolvido NÃO é o principal */
    private function cenario(Tenant $tenant): array
    {
        $pasta = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, '07011345720258070007'));
        $segundo = $this->criarProcesso($tenant, '07022345720258070007');
        $this->vincular($pasta, $segundo);

        return [$pasta, $segundo];
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler->filter('section.ps-processos');
    }

    /** @return array<string, int> contagem de cada ação no cartão */
    private function acoes(Crawler $secao): array
    {
        return [
            'administrativo'  => $secao->filter('.ps-card-cab > form.js-pasta-administrativa')->count(),
            'menuCabecalho'   => $secao->filter('.ps-card-cab > [aria-controls^="psProcHdMenu"]')->count()
                + $secao->filter('[id^="psProcHdMenu"]')->count(),
            'vincular'        => $secao->filter('.ps-card-cab > [data-bs-target="#modalVincularProcesso"]')->count(),
            'tornarPrincipal' => $secao->filter('.ps-processo-menu > form.js-ajax-processo-principal')->count(),
            'desvincular'     => $secao->filter('.ps-processo-menu > form.js-ajax-desvincular-processo')->count(),
        ];
    }

    private const NENHUMA = ['administrativo' => 0, 'menuCabecalho' => 0, 'vincular' => 0, 'tornarPrincipal' => 0, 'desvincular' => 0];
    private const TODAS   = ['administrativo' => 1, 'menuCabecalho' => 2, 'vincular' => 1, 'tornarPrincipal' => 1, 'desvincular' => 2];

    #[TestDox('Só leitura da pasta: nenhuma das ações de escrita; o resto do ⋮ e o Peticionar ficam')]
    public function testLeitorNaoVe(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$pasta] = $this->cenario($tenant);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.view']);

        $this->logarComTenant($client, $leitor, $tenant);
        $secao = $this->abrir($client, $pasta);

        self::assertSame(self::NENHUMA, $this->acoes($secao));
        self::assertCount(2, $secao->filter('.ps-processo-menu .js-proc-ver-movimentacoes'), 'o ⋮ do cartão continua');
        self::assertCount(1, $secao->filter('a[href$="/peticionar"]'), 'Peticionar não é escrita no vínculo');
    }

    private const SWITCH_LEITURA = '.ps-card-cab > button.ps-adm-sw.ps-adm-sw--leitura[role="switch"][type="button"][disabled][aria-disabled="true"]';

    #[TestDox('PAS-049b: só leitura com vínculos e pasta administrativa: o interruptor aparece ligado, desabilitado e fora de form')]
    public function testLeitorVeOIndicadorAdministrativo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$pasta] = $this->cenario($tenant);
        $pasta->setAdministrativa(true);
        $this->em()->flush();
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.view']);

        $this->logarComTenant($client, $leitor, $tenant);
        $secao = $this->abrir($client, $pasta);

        $switch = $secao->filter(self::SWITCH_LEITURA);
        self::assertCount(1, $switch);
        self::assertSame('true', $switch->attr('aria-checked'));
        self::assertStringContainsString('is-ligado', (string) $switch->attr('class'));
        self::assertSame('Administrativo sem processo', trim($switch->filter('.ps-adm-sw-rotulo')->text()));
        self::assertSame(self::NENHUMA, $this->acoes($secao), 'continua sem o form que escreve');
    }

    #[TestDox('PAS-049b: só leitura e pasta NÃO administrativa: o interruptor aparece desligado; o editor recebe o de verdade')]
    public function testLeitorVeDesligadoEEditorNaoRecebeOLeitura(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$pasta] = $this->cenario($tenant);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.view']);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);

        $this->logarComTenant($client, $leitor, $tenant);
        $switch = $this->abrir($client, $pasta)->filter(self::SWITCH_LEITURA);
        self::assertCount(1, $switch);
        self::assertSame('false', $switch->attr('aria-checked'));
        self::assertStringNotContainsString('is-ligado', (string) $switch->attr('class'));

        $this->logarComTenant($client, $editor, $tenant);
        $secao = $this->abrir($client, $pasta);
        self::assertCount(0, $secao->filter('.ps-adm-sw--leitura'));
        self::assertCount(0, $secao->filter('.ps-adm-sw[disabled]'));
        self::assertCount(1, $secao->filter('.ps-card-cab > form.js-pasta-administrativa > button.ps-adm-sw[type="submit"]'));
    }

    #[TestDox('PAS-049b: vazio sem processo — o editor lê "pelo botão acima"; quem só lê, um texto que não cita botão')]
    public function testVazioNaoCitaBotaoAQuemNaoTem(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.view']);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);

        $this->logarComTenant($client, $editor, $tenant);
        $nota = trim($this->abrir($client, $pasta)->filter('.ps-vazio > .ps-vazio-nota')->text());
        self::assertSame('Vincule um processo pelo botão acima.', $nota);

        $this->logarComTenant($client, $leitor, $tenant);
        $nota = trim($this->abrir($client, $pasta)->filter('.ps-vazio > .ps-vazio-nota')->text());
        self::assertSame('Quem edita a pasta pode vincular um processo a ela.', $nota);
        self::assertStringNotContainsStringIgnoringCase('botão', $nota);
    }

    #[TestDox('Com EDIT na pasta (papel comum): todas as ações aparecem')]
    public function testEditorVe(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$pasta] = $this->cenario($tenant);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);

        $this->logarComTenant($client, $editor, $tenant);

        self::assertSame(self::TODAS, $this->acoes($this->abrir($client, $pasta)));
    }

    #[TestDox('Lápide: nenhuma ação de escrita, nem para o administrador')]
    public function testLapide(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta]         = $this->cenario($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);

        $secao = $this->abrir($client, $pasta);
        self::assertSame(self::NENHUMA, $this->acoes($secao));
        self::assertCount(1, $secao->filter(self::SWITCH_LEITURA), 'PAS-049b: o indicador fica, sem clique');
    }

    #[TestDox('XHR "Tornar principal" do editor: o cartão que volta mantém as ações')]
    public function testXhrDoEditorMantemAcoes(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$pasta, $segundo] = $this->cenario($tenant);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);

        $this->logarComTenant($client, $editor, $tenant);
        $token = $this->abrir($client, $pasta)
            ->filter('form.js-ajax-processo-principal input[name="_token"]')->attr('value');

        $client->request('POST', '/pasta/' . $pasta->getId() . '/processo/principal', [
            '_token'      => $token,
            'processo_id' => $segundo->getId(),
        ], [], self::XHR);
        self::assertResponseIsSuccessful();

        $html  = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['html'];
        $secao = (new Crawler($html))->filter('section.ps-processos');

        self::assertSame(self::TODAS, $this->acoes($secao));
    }

    #[TestDox('XHR do interruptor "Administrativo": o cartão que volta mantém as ações')]
    public function testXhrDoAdministrativoMantemAcoes(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$pasta] = $this->cenario($tenant);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);

        $this->logarComTenant($client, $editor, $tenant);
        $token = $this->abrir($client, $pasta)
            ->filter('form.js-pasta-administrativa input[name="_token"]')->attr('value');

        $client->request('POST', '/pasta/' . $pasta->getId() . '/administrativa', [
            '_token'         => $token,
            'administrativa' => '1',
        ], [], self::XHR);
        self::assertResponseIsSuccessful();

        $html  = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['html'];
        $secao = (new Crawler($html))->filter('section.ps-processos');

        self::assertSame(self::TODAS, $this->acoes($secao));
    }
}
