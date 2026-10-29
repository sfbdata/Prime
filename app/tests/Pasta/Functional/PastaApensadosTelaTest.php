<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Entity\TipoDeApenso;
use App\Pasta\UseCase\DesvincularProcessoUseCase;
use App\Pasta\UseCase\VincularApensadoUseCase;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Processos apensados (P4f, spec docs/specs/processo-apensados.md): vincular e desvincular apensado pelas
 * rotas de sempre, o cartão da aba Processo, a pílula da aba Dados, os cartões do sistema na timeline, os
 * chips do Push e o registro de fase ao marcar/desmarcar "Administrativo sem processo".
 */
#[CoversClass(PastaController::class)]
#[CoversClass(VincularApensadoUseCase::class)]
#[CoversClass(DesvincularProcessoUseCase::class)]
#[Group('pasta')]
final class PastaApensadosTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const PRINCIPAL         = '07011345720258070007';
    private const PRINCIPAL_MASCARA = '0701134-57.2025.8.07.0007';
    private const EMBARGOS          = '07345120820248070001';
    private const EMBARGOS_MASCARA  = '0734512-08.2024.8.07.0001';
    private const AGRAVO            = '07222223320258070000';
    private const XHR               = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    // ── Vincular ─────────────────────────────────────────────────────────────

    #[TestDox('Vincular apensado: entra como não principal com o tipo, devolve o cartão com a etiqueta e escreve o cartão do sistema')]
    public function testVincularApensado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $embargos        = $this->criarProcesso($tenant, self::EMBARGOS);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pasta, ['processo_id' => $embargos->getId(), 'tipo_apenso' => 'embargos']);

        self::assertResponseIsSuccessful();
        $html = (string) json_decode((string) $this->client->getResponse()->getContent(), true)['html'];
        self::assertStringContainsString('Apensado · Embargos', $html);
        self::assertStringContainsString('Desvincular este apensado', $html);

        $vinculo = $this->vinculo($pasta, $embargos);
        self::assertFalse($vinculo->isPrincipal());
        self::assertSame(TipoDeApenso::Embargos, $vinculo->getTipoApenso());
        self::assertSame(
            '<p><strong>Processo:</strong> apensado ' . self::EMBARGOS_MASCARA . ' (Embargos) vinculado ao processo principal ' . self::PRINCIPAL_MASCARA . '.</p>',
            $this->ultimoCartao($pasta),
        );
    }

    #[TestDox('Vincular apensado pelo CNJ (número novo) cria o processo e o vincula como apensado')]
    public function testVincularApensadoPeloCnj(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pasta, ['numeroProcesso' => self::EMBARGOS_MASCARA, 'tipo_apenso' => 'agravo_de_instrumento']);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $pasta = $this->em()->find(Pasta::class, $pasta->getId());
        self::assertCount(1, $pasta->getApensados());
        self::assertSame(TipoDeApenso::AgravoDeInstrumento, $pasta->getApensados()[0]->getTipoApenso());
    }

    #[TestDox('Vincular apensado sem principal é permitido e a pasta continua sem principal')]
    public function testVincularApensadoSemPrincipal(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $embargos        = $this->criarProcesso($tenant, self::EMBARGOS);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pasta, ['processo_id' => $embargos->getId(), 'tipo_apenso' => 'embargos']);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->vinculo($pasta, $embargos)->isPrincipal());
        self::assertStringContainsString('vinculado ao processo principal.', $this->ultimoCartao($pasta));
    }

    #[TestDox('Sem CSRF válido: 400 e nada vinculado')]
    public function testCsrfInvalido(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $embargos        = $this->criarProcesso($tenant, self::EMBARGOS);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/processo/vincular', [
            '_token' => 'errado', 'modo' => 'apenso', 'processo_id' => $embargos->getId(), 'tipo_apenso' => 'embargos',
        ], [], self::XHR);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->vinculoOuNulo($pasta, $embargos));
    }

    #[TestDox('Quem VÊ a pasta mas não edita: a página abre, e o POST volta o redirect de pedir acesso (302) sem vincular nada')]
    public function testSemEditNaoVincula(): void
    {
        $tenant   = $this->criarTenant();
        $leitor   = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.view']);
        $pasta    = $this->pastaComPrincipal($tenant);
        $embargos = $this->criarProcesso($tenant, self::EMBARGOS);
        $this->logarComTenant($this->client, $leitor, $tenant);
        $this->abrir($pasta); // vê a pasta

        $this->vincularApensado($pasta, ['processo_id' => $embargos->getId(), 'tipo_apenso' => 'embargos']);

        // O guard do controller legado (ResourceAccessTrait) responde com o redirect para pedir acesso.
        self::assertResponseStatusCodeSame(302);
        $destino = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('requestAccess=1', $destino);
        self::assertStringContainsString('action=edit', $destino);
        self::assertNull($this->vinculoOuNulo($pasta, $embargos));
    }

    #[TestDox('Processo de outro escritório: 422 e nada vinculado')]
    public function testProcessoDeOutroEscritorio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $alheio          = $this->criarProcesso($this->criarTenant(), self::EMBARGOS);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pasta, ['processo_id' => $alheio->getId(), 'tipo_apenso' => 'embargos']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->vinculoOuNulo($pasta, $alheio));
    }

    #[TestDox('Pasta de outro escritório: recusada e nada vinculado')]
    public function testPastaDeOutroEscritorio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarTenant();
        $pastaAlheia     = $this->criarPasta($outro);
        $processoAlheio  = $this->criarProcesso($outro, self::EMBARGOS);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pastaAlheia, ['processo_id' => $processoAlheio->getId(), 'tipo_apenso' => 'embargos']);

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertNull($this->vinculoOuNulo($pastaAlheia, $processoAlheio));
    }

    #[TestDox('O principal como apensado: 422 "Este é o processo principal da pasta."')]
    public function testPrincipalRepetido(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pasta, ['numeroProcesso' => self::PRINCIPAL_MASCARA, 'tipo_apenso' => 'embargos']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Este é o processo principal da pasta.', $this->resposta()['erro']);
        $this->em()->clear();
        $fresca = $this->em()->find(Pasta::class, $pasta->getId());
        self::assertCount(1, $fresca->getPastaProcessos());
        self::assertTrue($fresca->getVinculoPrincipal()?->isPrincipal());
        self::assertSame(self::PRINCIPAL, $fresca->getProcessoPrincipal()?->getNumeroProcesso());
    }

    #[TestDox('O mesmo apensado de novo: 422 "Este apensado já está vinculado."')]
    public function testApensadoRepetido(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $embargos        = $this->criarProcesso($tenant, self::EMBARGOS);
        $this->apensar($pasta, $embargos, TipoDeApenso::Embargos);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pasta, ['processo_id' => $embargos->getId(), 'tipo_apenso' => 'recurso']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Este apensado já está vinculado.', $this->resposta()['erro']);
        self::assertSame(TipoDeApenso::Embargos, $this->vinculo($pasta, $embargos)->getTipoApenso());
    }

    #[TestDox('Tipo de apenso fora da lista: 422 e nada vinculado')]
    public function testTipoInvalido(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $embargos        = $this->criarProcesso($tenant, self::EMBARGOS);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pasta, ['processo_id' => $embargos->getId(), 'tipo_apenso' => 'inventado']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->vinculoOuNulo($pasta, $embargos));
    }

    // ── Desvincular ──────────────────────────────────────────────────────────

    #[TestDox('Desvincular o apensado: o principal fica como estava e o cartão do sistema diz "apensado X desvinculado."')]
    public function testDesvincularApensado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $embargos        = $this->criarProcesso($tenant, self::EMBARGOS);
        $this->apensar($pasta, $embargos, TipoDeApenso::Embargos);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/processo/desvincular', [
            '_token' => 'TOKEN_pasta_desvincular_processo_' . $pasta->getId(), 'processo_id' => $embargos->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $fresca = $this->em()->find(Pasta::class, $pasta->getId());
        self::assertCount(1, $fresca->getPastaProcessos());
        self::assertSame(self::PRINCIPAL, $fresca->getProcessoPrincipal()?->getNumeroProcesso());
        self::assertTrue($fresca->getVinculoPrincipal()?->isPrincipal());
        self::assertSame('<p><strong>Processo:</strong> apensado ' . self::EMBARGOS_MASCARA . ' desvinculado.</p>', $this->ultimoCartao($pasta));
    }

    #[TestDox('Desvincular o principal com apensados: a pasta fica SEM principal e os apensados intactos (spec §2, relido do banco)')]
    public function testDesvincularPrincipalComApensadosNaoPromove(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $principalId     = $pasta->getProcessoPrincipal()?->getId();
        $this->apensar($pasta, $this->criarProcesso($tenant, self::EMBARGOS), TipoDeApenso::Embargos);
        $this->apensar($pasta, $this->criarProcesso($tenant, self::AGRAVO), TipoDeApenso::AgravoDeInstrumento);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/processo/desvincular', [
            '_token' => 'TOKEN_pasta_desvincular_processo_' . $pasta->getId(), 'processo_id' => $principalId,
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT pr.numero_processo, pp.principal, pp.tipo_apenso FROM pasta_processo pp JOIN processo pr ON pr.id = pp.processo_id WHERE pp.pasta_id = ? ORDER BY pp.id',
            [$pasta->getId()],
        );
        self::assertSame([
            ['numero_processo' => self::EMBARGOS, 'principal' => false, 'tipo_apenso' => 'embargos'],
            ['numero_processo' => self::AGRAVO, 'principal' => false, 'tipo_apenso' => 'agravo_de_instrumento'],
        ], $linhas);
        $this->em()->clear();
        $fresca = $this->em()->find(Pasta::class, $pasta->getId());
        self::assertNull($fresca->getVinculoPrincipal());
        self::assertCount(2, $fresca->getApensados());
    }

    #[TestDox('Pasta só com apensado (sem principal): marcar "Administrativo sem processo" pede confirmação citando o primeiro processo vinculado')]
    public function testAdministrativaSemPrincipalPedeConfirmacao(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->apensar($pasta, $this->criarProcesso($tenant, self::EMBARGOS), TipoDeApenso::Embargos);
        $this->logarComTenant($this->client, $user, $tenant);

        $form = $this->abrir($pasta)->filter('#processoTabContent > .ps-processos > .ps-card-cab > form.js-pasta-administrativa');

        self::assertCount(1, $form);
        self::assertSame(
            'A Pasta ' . $pasta->getNup() . ' tem o processo ' . self::EMBARGOS_MASCARA . " vinculado.\nMarcar mesmo assim como \"Administrativo sem processo\"? O andamento passa para a via administrativa.",
            $form->attr('data-confirmar'),
        );
    }

    #[TestDox('Desvincular o principal não escreve cartão de apensado: escreve o do P5d, "processo N desvinculado da pasta."')]
    public function testDesvincularPrincipalSemCartaoDeApensado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/processo/desvincular', [
            '_token' => 'TOKEN_pasta_desvincular_processo_' . $pasta->getId(), 'processo_id' => $pasta->getProcessoPrincipal()?->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $cartao = $this->ultimoCartao($pasta);
        self::assertStringNotContainsString('apensado', $cartao);
        self::assertStringContainsString('desvinculado da pasta.', $cartao);
    }

    // ── Tela ─────────────────────────────────────────────────────────────────

    #[TestDox('Aba Processo: botão "Vincular apensado" depois do "Vincular processo", contador com principal + apensados, etiqueta e X no cartão do apensado')]
    public function testCartaoDoApensado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->apensar($pasta, $this->criarProcesso($tenant, self::EMBARGOS), TipoDeApenso::Embargos);
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->abrir($pasta);
        $cab     = $crawler->filter('#processoTabContent > .ps-processos > .ps-card-cab');

        $botoes = $cab->filter('button[data-vp-abrir][aria-controls="modalVincularProcesso"]');
        self::assertCount(2, $botoes);
        self::assertSame('apenso', $botoes->eq(1)->attr('data-vincular-modo'));
        self::assertStringContainsString('Vincular apensado', $botoes->eq(1)->text());
        self::assertSame('2', trim($cab->filter('.ps-contagem')->text()));

        $cartoes = $crawler->filter('.ps-processos-lista > article.ps-processo');
        self::assertCount(1, $cartoes->eq(0)->filter('.ps-processo-topo > .ps-processo-principal'));
        self::assertCount(0, $cartoes->eq(0)->filter('.ps-processo-apenso'));
        $topoApenso = $cartoes->eq(1)->filter('.ps-processo-topo');
        self::assertSame('Apensado · Embargos', trim($topoApenso->filter('.ps-processo-apenso')->text()));
        self::assertCount(1, $topoApenso->filter('form.js-desvincular-apensado > button.ps-processo-apenso-x[title="Desvincular este apensado"]'));
        self::assertSame(
            'Desvincular o apensado ' . self::EMBARGOS_MASCARA . ' da pasta? O registro fica no histórico.',
            $topoApenso->filter('form.js-desvincular-apensado')->attr('data-confirmar-texto'),
        );
        // O ⋮ do apensado desvincula o APENSADO, com a mesma confirmação.
        self::assertCount(1, $cartoes->eq(1)->filter('.ps-processo-menu form.js-desvincular-apensado'));
        self::assertCount(1, $cartoes->eq(0)->filter('.ps-processo-menu form.js-ajax-desvincular-processo'));
    }

    #[TestDox('Modal de vínculo: campo modo e o select "Tipo de apenso" com os 8 tipos, Embargos primeiro, escondido e desabilitado até o modo apenso')]
    public function testModalComTipoDeApenso(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $form = $this->abrir($pasta)->filter('#modalVincularProcesso form#formVincularProcesso');

        self::assertSame('principal', $form->filter('input#mp-modo[name="modo"]')->attr('value'));
        $bloco = $form->filter('.vp-corpo > label#mp-apenso-tipo');
        self::assertNotNull($bloco->attr('hidden'));
        $select = $bloco->filter('select#mp-tipoApenso[name="tipo_apenso"]');
        self::assertNotNull($select->attr('disabled'));
        self::assertSame(
            ['Embargos', 'Agravo de instrumento', 'Cumprimento de sentença', 'Execução', 'Impugnação', 'Recurso', 'Cautelar', 'Outro'],
            $select->filter('option')->each(static fn (Crawler $o): string => trim($o->text()))
        );
        self::assertSame('embargos', $select->filter('option[selected]')->attr('value'));
    }

    #[TestDox('Janela do desenho de 18/10 (N:289-354), não o modal do Bootstrap: cabeçalho com título e X, abas .mp-abas, aviso fora do "Cadastrar novo", rodapé e CSS com escopo na janela')]
    public function testModalNoDesenho(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->abrir($pasta);
        // A janela do desenho, não o modal do Bootstrap: sobreposição > caixa > cabeçalho, form (corpo e rodapé).
        $modal   = $crawler->filter('#modalVincularProcesso.vp-sobre[hidden] > .vp-caixa[role="dialog"]');
        self::assertCount(1, $modal);
        self::assertCount(0, $crawler->filter('#modalVincularProcesso.modal, #modalVincularProcesso .modal-dialog'));

        self::assertSame('Vincular processo', trim($modal->filter('.vp-cab > b.vp-titulo > #mp-titulo-texto')->text()));
        self::assertCount(1, $modal->filter('.vp-cab > i.bi-briefcase + b.vp-titulo + button#mp-ampliar + button.mp-fechar[data-vp-fechar] > i.bi-x-lg'));
        $abas = $modal->filter('.vp-form > .vp-corpo > .mp-abas');
        self::assertCount(1, $abas);
        self::assertSame(['Buscar processo existente', 'Cadastrar novo (CNJ)'], $abas->filter('.mp-abas > button.mp-aba')->each(static fn (Crawler $l): string => trim($l->text())));
        self::assertCount(0, $modal->filter('.btn'), 'sem botões do Bootstrap');
        self::assertCount(1, $modal->filter('.vp-corpo > #mp-feedbackAlerta'), 'o aviso aparece também na aba "Buscar processo existente"');
        self::assertCount(0, $modal->filter('#modal-modo-novo #mp-feedbackAlerta'));
        self::assertSame('Cancelar', trim($modal->filter('.vp-rodape > button.vp-btn--cancelar[data-vp-fechar]')->text()));
        self::assertSame('Vincular processo', trim($modal->filter('.vp-rodape > button[type="submit"].vp-btn--ok #mp-salvar-texto')->text()));
        self::assertCount(1, $crawler->filter('link[href*="css/pasta-apensados.css"]'));

        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/pasta-apensados.css');
        foreach ([
            '#modalVincularProcesso .vp-cab {',
            'background: #0a6fa0; color: #fff;',
            '#modalVincularProcesso .mp-aba.is-ativa { background: #0a6fa0; color: #fff; }',
            '#modalVincularProcesso .vp-btn--cancelar { padding: 0 16px; background: #eef2f5; color: #34505f; }',
            '#modalVincularProcesso .mp-aviso { padding: 10px 12px; border-radius: 4px; font-size: 13px; background: #fff7e6; color: #7a5200;',
        ] as $regra) {
            self::assertStringContainsString($regra, $css);
        }
    }

    #[TestDox('Aba Dados: pílula "2 apensados" com a dica e, aberta, cada apensado com "Apensado · Tipo" e o número que abre a aba Processo')]
    public function testPilulaNaAbaDados(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->apensar($pasta, $this->criarProcesso($tenant, self::EMBARGOS), TipoDeApenso::Embargos);
        $this->apensar($pasta, $this->criarProcesso($tenant, self::AGRAVO), TipoDeApenso::AgravoDeInstrumento);
        $this->logarComTenant($this->client, $user, $tenant);

        $celula = $this->abrir($pasta)->filter('[data-trilho="processo"] > [data-campo="processo"]');
        $chip   = $celula->filter('.ps-cab-proc-acoes > button.ps-chip-vinc--apensados');
        self::assertCount(1, $chip);
        self::assertStringContainsString('2 apensados', $chip->text());
        self::assertSame('Ver processos apensados', $chip->attr('title'));
        self::assertSame('Recolher processos apensados', $chip->attr('data-dica-aberto'));
        self::assertCount(1, $chip->filter('i.bi-paperclip'));

        $itens = $celula->filter('#psProcessosVinculados > .ps-cab-apensado');
        self::assertCount(2, $itens);
        self::assertSame('Apensado · Embargos', trim($itens->eq(0)->filter('.ps-rotulo--mini')->text()));
        self::assertSame('processo-tab', $itens->eq(0)->filter('a')->attr('data-ps-ir-aba'));
        self::assertSame(self::EMBARGOS_MASCARA, trim($itens->eq(0)->filter('a')->text()));
        self::assertSame('Apensado · Agravo de instrumento', trim($itens->eq(1)->filter('.ps-rotulo--mini')->text()));
    }

    #[TestDox('Timeline de Dados: os dois cartões do sistema (vinculado e desvinculado) aparecem com o rótulo "Processo"')]
    public function testTimelineComOsDoisRegistros(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $embargos        = $this->criarProcesso($tenant, self::EMBARGOS);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->vincularApensado($pasta, ['processo_id' => $embargos->getId(), 'tipo_apenso' => 'embargos']);
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/processo/desvincular', [
            '_token' => 'TOKEN_pasta_desvincular_processo_' . $pasta->getId(), 'processo_id' => $embargos->getId(),
        ], [], self::XHR);

        $textos = $this->abrir($pasta)->filter('article.ps-anotacao--sistema .ps-anotacao-texto')->each(static fn (Crawler $n): string => trim($n->text()));
        self::assertContains('Processo: apensado ' . self::EMBARGOS_MASCARA . ' (Embargos) vinculado ao processo principal ' . self::PRINCIPAL_MASCARA . '.', $textos);
        self::assertContains('Processo: apensado ' . self::EMBARGOS_MASCARA . ' desvinculado.', $textos);
    }

    #[TestDox('Push: chips Todos/Principal/tipo com contagem antes de Todas/Novas/Geram prazo, chave do processo na linha e sufixo na fonte do apensado')]
    public function testChipsDoPush(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->apensar($pasta, $this->criarProcesso($tenant, self::EMBARGOS), TipoDeApenso::Embargos);
        $this->publicacao($tenant, 'apx-1', self::PRINCIPAL, self::PRINCIPAL_MASCARA, '2026-08-20');
        $this->publicacao($tenant, 'apx-2', self::PRINCIPAL, self::PRINCIPAL_MASCARA, '2026-08-21');
        $this->publicacao($tenant, 'apx-3', self::EMBARGOS, self::EMBARGOS_MASCARA, '2026-08-22');
        $this->logarComTenant($this->client, $user, $tenant);

        $cab   = $this->abrir($pasta)->filter('.ps-push > .ps-card-cab--faixa');
        $chips = $cab->filter('.ps-push-procs > button.ps-push-proc');
        self::assertSame(['Todos os processos · 3', 'Principal · 2', 'Embargos · 1'], $chips->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame('true', $chips->eq(0)->attr('aria-pressed'));
        self::assertSame('Movimentações de todos os processos da pasta', $chips->eq(0)->attr('title'));
        self::assertSame('Somente ' . self::EMBARGOS_MASCARA, $chips->eq(2)->attr('title'));
        self::assertSame('3', trim($cab->filter('.ps-contagem')->text()), 'o total do cabeçalho não é filtrado');
        // Os chips vêm antes de Todas/Novas/Geram prazo.
        self::assertCount(1, $cab->filter('.ps-push-procs + .ps-push-filtros'));

        $linhas = $this->abrir($pasta)->filter('.ps-push-lista > li.ps-push-item');
        $doApenso = $linhas->filter('li[data-push-proc="' . self::EMBARGOS . '"]');
        self::assertCount(1, $doApenso);
        self::assertStringEndsWith('· DJEN · Apensado · Embargos ' . self::EMBARGOS_MASCARA, trim($doApenso->filter('.ps-push-meta')->text()));
        $doPrincipal = $linhas->filter('li[data-push-proc="' . self::PRINCIPAL . '"]');
        self::assertCount(2, $doPrincipal);
        self::assertStringNotContainsString('Apensado', $doPrincipal->eq(0)->filter('.ps-push-meta')->text());
    }

    #[TestDox('Push sem apensado: nenhum chip de processo')]
    public function testPushSemApensadoSemChips(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->pastaComPrincipal($tenant);
        $this->publicacao($tenant, 'apx-4', self::PRINCIPAL, self::PRINCIPAL_MASCARA, '2026-08-20');
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertCount(0, $this->abrir($pasta)->filter('.ps-push-procs'));
    }

    // ── Administrativa ───────────────────────────────────────────────────────

    #[TestDox('Marcar e desmarcar "Administrativo sem processo" grava os dois registros de fase do desenho (spMarcar, N:4761)')]
    public function testRegistroDeFaseAdministrativa(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        foreach (['1', '0'] as $valor) {
            $this->client->request('POST', '/pasta/' . $pasta->getId() . '/administrativa', [
                '_token' => 'TOKEN_pasta_administrativa_' . $pasta->getId(), 'administrativa' => $valor,
            ]);
        }

        $cartoes = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = ? ORDER BY id',
            [$pasta->getId(), 'fase'],
        );
        self::assertCount(2, $cartoes);
        self::assertStringContainsString('pasta marcada como administrativa: o andamento passa para a via administrativa (Requerimento, Em análise, Exigência, Parecer). O andamento judicial fica guardado.', html_entity_decode($cartoes[0]));
        self::assertStringContainsString('pasta voltou para a via judicial', html_entity_decode($cartoes[1]));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function abrir(Pasta $pasta): Crawler
    {
        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @param array<string, mixed> $dados */
    private function vincularApensado(Pasta $pasta, array $dados): void
    {
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/processo/vincular', $dados + [
            '_token' => 'TOKEN_vincular_processo_' . $pasta->getId(),
            'modo'   => 'apenso',
            // Obrigatórios do cadastro novo (desenho 02, "Preencha: ..."); processo existente não os usa.
            'orgaoJulgador' => '1ª Vara Cível',
            'classeProcessual' => 'Embargos à Execução',
            'assuntoProcessual' => 'Efeito Suspensivo',
        ], [], self::XHR);
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function pastaComPrincipal(Tenant $tenant): Pasta
    {
        $pasta = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::PRINCIPAL));

        return $pasta;
    }

    private function apensar(Pasta $pasta, Processo $processo, TipoDeApenso $tipo): void
    {
        $pasta->vincularApensado($processo, $tipo);
        $this->em()->flush();
    }

    private function vinculo(Pasta $pasta, Processo $processo): PastaProcesso
    {
        $vinculo = $this->vinculoOuNulo($pasta, $processo);
        self::assertNotNull($vinculo);

        return $vinculo;
    }

    private function vinculoOuNulo(Pasta $pasta, Processo $processo): ?PastaProcesso
    {
        $this->em()->clear();

        return $this->em()->getRepository(PastaProcesso::class)->findOneBy(['pasta' => $pasta->getId(), 'processo' => $processo->getId()]);
    }

    private function ultimoCartao(Pasta $pasta): string
    {
        return (string) $this->em()->getConnection()->fetchOne(
            'SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = ? ORDER BY id DESC LIMIT 1',
            [$pasta->getId(), 'proc'],
        );
    }

    private function publicacao(Tenant $tenant, string $djenId, string $numero, string $mascara, string $data): void
    {
        $pub = new PublicacaoDjen();
        $pub->setTenant($tenant);
        $pub->setDjenId((string) random_int(100000000, 999999999999));
        $pub->setNumeroProcesso($numero);
        $pub->setNumeroProcessoComMascara($mascara);
        $pub->setSiglaTribunal('TJDFT');
        $pub->setTipoComunicacao('Intimação');
        $pub->setNomeOrgao('1ª Vara Cível de Brasília');
        $pub->setDataDisponibilizacao(new \DateTimeImmutable($data));
        $pub->setTexto('Teor da publicação ' . $djenId);
        $this->em()->persist($pub);
        $this->em()->flush();
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
