<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * #9-T3 — "Editar configuração de encargos" no OBJETO (nível 2 da cascata Carteira → Objeto →
 * Obrigação, spec "cascata de encargos ao vivo sem snapshot" §4) + aposentadoria do editor de
 * honorários do CASO na tela.
 *
 * DESVIO da spec §4 (documentado no relatório da T3): só % (basis points) via `TaxaBpType` — SEM R$/
 * `ConversorTaxaEncargo` (o objeto agrega várias obrigações, sem principal/vencimento únicos).
 *
 * Cobre: os 10 overrides persistem e voltam a herdar quando vazios; a tela reflete o override no
 * cálculo AO VIVO do exigível (integra com `ResolvedorConfigEncargos::resolverDoObjeto`, não é mock);
 * a guarda "carteira sem honorário percentual" desabilita o campo; o modal do CASO sumiu da tela;
 * gate de capacidade, CSRF e cross-tenant (404).
 */
#[CoversClass(ObjetoController::class)]
final class ObjetoConfigEncargosControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Configurar: os 10 overrides são gravados no objeto (POST completo) — DOBRA como controle do I-1: carteira COM percentual grava o override de honorários')]
    public function testConfigurarGravaOsDezOverrides(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Carteira COM forma percentual (não SemPercentual, default da factory): o override de
        // honorários do objeto é gravado normalmente — controle do I-1 (guarda de servidor só anula
        // quando a carteira não cobra percentual).
        [, $caso] = $this->semearGrafo($tenant, [], [
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
        ]);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_objeto');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/configuracao-encargos', [
            'editar_configuracao_objeto' => self::payload([
                'taxaJurosMensalBp' => '1,50',
                'regimeJuros' => 'composto',
                'taxaMultaBp' => '3,00',
                'baseMulta' => 'composta',
                'taxaCorrecaoBp' => '0,80',
                'baseCorrecao' => 'composta',
                'taxaHonorariosBp' => '25,00',
                'baseHonorarios' => 'principal',
                'carenciaHonorariosDias' => '15',
                'toleranciaJurosMultaDias' => '5',
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregado = $em->find(ObjetoCobranca::class, $objetoId);
        self::assertSame(150, $recarregado->getTaxaJurosMensalBp());
        self::assertSame(RegimeJuros::Composto, $recarregado->getRegimeJuros());
        self::assertSame(300, $recarregado->getTaxaMultaBp());
        self::assertSame(BaseEncargo::Composta, $recarregado->getBaseMulta());
        self::assertSame(80, $recarregado->getTaxaCorrecaoBp());
        self::assertSame(BaseEncargo::Composta, $recarregado->getBaseCorrecao());
        self::assertSame(2500, $recarregado->getTaxaHonorariosBp());
        self::assertSame(BaseEncargo::Principal, $recarregado->getBaseHonorarios());
        self::assertSame(15, $recarregado->getCarenciaHonorariosDias());
        self::assertSame(5, $recarregado->getToleranciaJurosMultaDias());
    }

    #[TestDox('I-1: POST forjado com override de honorários é anulado no SERVIDOR quando a carteira é SemPercentual (disabled do HTML não é a rede real)')]
    public function testGuardaServidorAnulaHonorarioDoObjetoQuandoCarteiraSemPercentual(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Carteira SemPercentual (default da factory) — no HTML o campo de honorários vem `disabled`,
        // mas o teste monta o POST manualmente (não via form da crawler), simulando um POST forjado
        // que ignora o `disabled` do cliente.
        [, $caso] = $this->semearGrafo($tenant, [], [
            'formaHonorarios' => FormaHonorarios::SemPercentual,
        ]);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_objeto');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/configuracao-encargos', [
            'editar_configuracao_objeto' => self::payload([
                // Override de honorários FORJADO — a carteira não cobra percentual, o servidor deve anular.
                'taxaHonorariosBp' => '25,00',
                'baseHonorarios' => 'principal',
                // Outro override (juros), no MESMO POST — prova que a guarda é cirúrgica: só o
                // honorário é anulado, o resto do POST grava normalmente.
                'taxaJurosMensalBp' => '1,50',
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregado = $em->find(ObjetoCobranca::class, $objetoId);
        self::assertNull($recarregado->getTaxaHonorariosBp(), 'servidor anula o override forjado de honorários');
        self::assertNull($recarregado->getBaseHonorarios(), 'servidor anula a base do honorário junto');
        self::assertSame(150, $recarregado->getTaxaJurosMensalBp(), 'os OUTROS overrides do mesmo POST gravam normalmente');
    }

    #[TestDox('Configurar: campos vazios voltam a herdar a carteira (null), mesmo com override anterior')]
    public function testCamposVaziosVoltamAHerdar(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();
        $objetoId = (int) $objeto->getId();

        // O objeto JÁ tem um override de uma edição anterior.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $objeto->setTaxaJurosMensalBp(999)->setBaseMulta(BaseEncargo::Composta);
        $em->flush();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_objeto');

        // Payload TODO vazio (o gestor limpou os campos para voltar a herdar) + o token.
        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/configuracao-encargos', [
            'editar_configuracao_objeto' => self::payload(['_token' => $token]),
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em->clear();
        $recarregado = $em->find(ObjetoCobranca::class, $objetoId);
        self::assertNull($recarregado->getTaxaJurosMensalBp(), 'campo vazio volta a herdar (null)');
        self::assertNull($recarregado->getBaseMulta(), 'campo vazio volta a herdar (null)');
    }

    #[TestDox('Integra com o resolvedor: o override de juros do OBJETO muda o encargo AO VIVO na tela (não é mock)')]
    public function testOverrideDoObjetoAlteraOJurosExibidoNaLinhaDaDivida(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Config TOPLIFE na CARTEIRA (mesmo cenário provado de `ObjetoShowControllerTest`): juros 1%
        // a.m., multa 2% sobre o PRINCIPAL (baseMulta default), honorários 20% AcrescidoDivida.
        // Principal R$ 170,00 com 240 dias de atraso reproduz, sem override, juros 13,60 · multa 3,40.
        [, $caso] = $this->semearGrafo($tenant, [], [
            'taxaJurosMensalBp' => 100,
            'taxaMultaBp' => 200,
            'carenciaHonorariosDias' => 30,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
        ]);
        $objetoId = (int) $caso->getObjeto()->getId();
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Boleto TOPLIFE', 'valorOriginal' => 17000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => (new \DateTimeImmutable('today'))->modify('-240 days'),
        ]);

        // Confirma a LINHA DE BASE (sem override) antes de configurar — prova que o teste discrimina.
        $antes = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        self::assertStringContainsString('13,60', $antes->filter('#secao-divida .jp-obr .col-juros')->text());

        $token = $this->tokenDoFormulario($antes, 'editar_configuracao_objeto');
        // Override do OBJETO: DOBRA o juros da carteira (1% → 2% a.m.). Multa/base não têm override —
        // permanecem herdando a carteira (controle de que só o campo tocado muda).
        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/configuracao-encargos', [
            'editar_configuracao_objeto' => self::payload([
                'taxaJurosMensalBp' => '2,00',
                '_token' => $token,
            ]),
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $depois = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        self::assertResponseIsSuccessful();
        $linha = $depois->filter('#secao-divida .jp-obr');
        // Juros DOBROU (13,60 → 27,20): o exigível ao vivo usa o override do OBJETO, integrado de ponta
        // a ponta (POST → persistência → `ResolvedorConfigEncargos::resolverDoObjeto` → `EncargosVivos`).
        self::assertStringContainsString('27,20', $linha->filter('.col-juros')->text());
        // Multa INTACTA (herda a carteira, sem override — prova que só o campo tocado mudou).
        self::assertStringContainsString('3,40', $linha->filter('.col-multa')->text());
    }

    #[TestDox('Guarda "Menor da revisão T2": carteira sem forma percentual desabilita o override de honorários no modal')]
    public function testCarteiraSemPercentualDesabilitaOOverrideDeHonorarios(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, [], [
            'formaHonorarios' => FormaHonorarios::SemPercentual,
        ]);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        self::assertResponseIsSuccessful();
        $campoHonorarios = $crawler->filter('#editar_configuracao_objeto_taxaHonorariosBp');
        self::assertCount(1, $campoHonorarios);
        self::assertNotNull($campoHonorarios->attr('disabled'), 'o campo de honorários fica desabilitado quando a carteira não cobra');
        self::assertStringContainsString('A carteira não cobra honorários', $crawler->filter('#modalConfigEncargosObjeto')->text());
    }

    #[TestDox('O modal/botão de config do CASO sumiu da tela — só o do OBJETO aparece')]
    public function testModalDoCasoSumiuDaTela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#modalEditarConfigCaso');
        self::assertSelectorNotExists('[data-bs-target="#modalEditarConfigCaso"]');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Editar honorários do caso', $html);
        // O novo modal/botão do OBJETO está presente no lugar.
        self::assertSelectorExists('#modalConfigEncargosObjeto');
        self::assertStringContainsString('Editar configuração de encargos', $html);
    }

    #[TestDox('CSRF inválido: config não muda')]
    public function testCsrfInvalidoNaoMudaConfig(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();
        $objetoId = (int) $objeto->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/configuracao-encargos', [
            'editar_configuracao_objeto' => self::payload([
                'taxaJurosMensalBp' => '9,00',
                '_token' => 'token-falso',
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(ObjetoCobranca::class, $objetoId)->getTaxaJurosMensalBp(), 'CSRF inválido não pode gravar');
    }

    #[TestDox('Sem a capacidade: negado, config intacta')]
    public function testSemCapacidadeNegado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/configuracao-encargos', [
            'editar_configuracao_objeto' => self::payload([
                'taxaJurosMensalBp' => '9,00',
                '_token' => 'irrelevante',
            ]),
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/objetos/' . $objetoId, (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(ObjetoCobranca::class, $objetoId)->getTaxaJurosMensalBp(), 'sem capacidade nada muda');
    }

    #[TestDox('IDOR: configurar objeto de OUTRO tenant devolve 404')]
    public function testCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/objetos/' . $casoAlheio->getObjeto()->getId() . '/configuracao-encargos', [
            'editar_configuracao_objeto' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Payload PADRÃO do form (todos os 10 campos VAZIOS = herda a carteira), com overrides mesclados —
     * espelha `CadastroCarteiraControllerTest::payloadConfiguracao`. Diferente da carteira, aqui vazio é
     * o valor NEUTRO e correto (nível 2 da cascata, todos os campos nullable).
     *
     * @param array<string, string> $campos
     *
     * @return array<string, string>
     */
    private static function payload(array $campos): array
    {
        return array_merge([
            'taxaJurosMensalBp' => '',
            'regimeJuros' => '',
            'taxaMultaBp' => '',
            'baseMulta' => '',
            'taxaCorrecaoBp' => '',
            'baseCorrecao' => '',
            'taxaHonorariosBp' => '',
            'baseHonorarios' => '',
            'carenciaHonorariosDias' => '',
            'toleranciaJurosMultaDias' => '',
        ], $campos);
    }
}
