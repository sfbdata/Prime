<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\RegimeJuros;
use App\Tests\Factory\Cliente\ClientePFFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de cadastro da Carteira (Onda 8B-E): criar carteira, editar configuração e criar objeto.
 * Cobre gate módulo + capacidade, CSRF, anti-IDOR (404 cross-tenant) e o happy path com estado no DB.
 */
#[CoversClass(CarteiraController::class)]
final class CadastroCarteiraControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Criar carteira: happy path persiste a nova carteira do tenant')]
    public function testCriarCarteiraHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas');
        $token = $this->tokenDoFormulario($crawler, 'criar_carteira');

        $client->request('POST', '/cobrancas/carteiras/nova', [
            'criar_carteira' => self::payloadCriarCarteira([
                'nome' => 'Carteira Nova Teste',
                'clienteId' => (string) $cliente->getId(),
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Nova Teste']));
    }

    /**
     * Payload completo do form de CRIAR carteira — inclui os 9 campos de encargo, que passaram a estar
     * na tela de criação (o caso snapshota a config ao nascer; carteira criada sem taxa gera casos
     * pinados em 0% para sempre). Igual ao payloadConfiguracao: postar o form inteiro é o que o
     * navegador faz, e é o único cenário que a produção precisa aceitar.
     *
     * @param array<string, string> $campos
     *
     * @return array<string, string>
     */
    private static function payloadCriarCarteira(array $campos): array
    {
        return array_merge([
            'modo' => 'unico',
            'formaHonorarios' => 'sem_percentual',
            'toleranciaAtrasoDias' => '0',
            'taxaJurosMensalBp' => '0,00',
            'regimeJuros' => 'simples',
            'taxaMultaBp' => '0,00',
            'baseMulta' => 'principal',
            'taxaCorrecaoBp' => '0,00',
            'baseCorrecao' => 'principal',
            'baseHonorarios' => 'composta',
            'carenciaHonorariosDias' => '',
            'toleranciaJurosMultaDias' => '0',
        ], $campos);
    }

    #[TestDox('Criar carteira: os 9 campos de encargo já são gravados na criação')]
    public function testCriarCarteiraGravaEncargos(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas');
        $token = $this->tokenDoFormulario($crawler, 'criar_carteira');

        $client->request('POST', '/cobrancas/carteiras/nova', [
            'criar_carteira' => self::payloadCriarCarteira([
                'nome' => 'Carteira Com Encargos',
                'clienteId' => (string) $cliente->getId(),
                'taxaJurosMensalBp' => '1,00',
                'taxaMultaBp' => '2,00',
                'baseMulta' => 'composta',
                'carenciaHonorariosDias' => '30',
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $carteiras = $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Com Encargos']);
        self::assertCount(1, $carteiras);
        self::assertSame(100, $carteiras[0]->getTaxaJurosMensalBp());
        self::assertSame(200, $carteiras[0]->getTaxaMultaBp());
        self::assertSame(BaseEncargo::Composta, $carteiras[0]->getBaseMulta());
        self::assertSame(30, $carteiras[0]->getCarenciaHonorariosDias());
    }

    #[TestDox('Modal de criar carteira renderiza os campos de encargo (sem guarda no partial)')]
    public function testFormCriarCarteiraRenderizaCamposDeEncargo(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $client->request('GET', '/cobrancas');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('criar_carteira_taxaJurosMensalBp', $html);
        self::assertStringContainsString('criar_carteira_regimeJuros', $html);
        self::assertStringContainsString('criar_carteira_baseMulta', $html);
        self::assertStringContainsString('criar_carteira_carenciaHonorariosDias', $html);
        self::assertStringContainsString('Encargos por atraso', $html);
    }

    #[TestDox('Form da carteira renderiza popover de ajuda, input-group de % e o JS do form')]
    public function testFormCarteiraRenderizaAjudaEInputGroup(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $client->request('GET', '/cobrancas');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        // Popover de ajuda (modo/forma de honorários) presente no modal de criar.
        self::assertStringContainsString('data-bs-toggle="popover"', $html);
        self::assertStringContainsString('Modo de operação', $html);
        self::assertStringContainsString('Forma de honorários', $html);
        // Campo de percentual com input-group + sufixo "%".
        self::assertStringContainsString('input-group', $html);
        self::assertStringContainsString('data-percentual-wrapper', $html);
        // JS do form da carteira (popover init + toggle do percentual).
        self::assertStringContainsString('cobranca-carteira-form.js', $html);
    }

    #[TestDox('Criar carteira: percentual em pt-BR (vírgula) é gravado no formato decimal')]
    public function testCriarCarteiraPercentualComVirgula(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas');
        $token = $this->tokenDoFormulario($crawler, 'criar_carteira');

        $client->request('POST', '/cobrancas/carteiras/nova', [
            'criar_carteira' => self::payloadCriarCarteira([
                'nome' => 'Carteira Percentual Virgula',
                'clienteId' => (string) $cliente->getId(),
                'formaHonorarios' => 'acrescido_divida',
                'percentualHonorarios' => '10,50',
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $carteiras = $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Percentual Virgula']);
        self::assertCount(1, $carteiras);
        self::assertSame('10.50', $carteiras[0]->getPercentualHonorarios());
    }

    #[TestDox('Criar carteira sem a capacidade: negado, nada é criado')]
    public function testCriarCarteiraSemCapacidade(): void
    {
        $client = static::createClient();
        $this->criarOperadorSemCapacidade($client);

        $client->request('POST', '/cobrancas/carteiras/nova', [
            'criar_carteira' => ['nome' => 'Carteira Bloqueada', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Bloqueada']));
    }

    #[TestDox('Criar carteira com CSRF inválido: não persiste')]
    public function testCriarCarteiraCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        $client->request('POST', '/cobrancas/carteiras/nova', [
            // Payload completo: o CSRF inválido é o ÚNICO motivo de falha que este teste isola. Um
            // payload incompleto faria o form mapear null num enum tipado e derrubaria o teste por 500,
            // escondendo o que ele existe para provar.
            'criar_carteira' => self::payloadCriarCarteira([
                'nome' => 'Carteira Token Falso',
                'clienteId' => (string) $cliente->getId(),
                '_token' => 'token-falso',
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(Carteira::class)->findBy(['nome' => 'Carteira Token Falso']));
    }

    #[TestDox('Editar configuração: happy path muda modo e tolerância')]
    public function testConfigurarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_carteira');

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/configuracao', [
            'editar_configuracao_carteira' => self::payloadConfiguracao([
                'modo' => 'multiplo',
                'formaHonorarios' => 'sem_percentual',
                'toleranciaAtrasoDias' => '5',
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Carteira::class, $carteiraId);
        self::assertSame(ModoCarteira::Multiplo, $recarregada->getModo());
        self::assertSame(5, $recarregada->getToleranciaAtrasoDias());
    }

    /**
     * O form de configuração é postado INTEIRO (os 9 campos de encargo inclusive) porque é assim que o
     * navegador o envia: o modal renderiza todos os campos. Os selects de encargo NÃO têm `empty_data`
     * de propósito — `empty_data` faria um POST parcial reescrever o default por cima da configuração
     * salva, zerando em silêncio a regra de dinheiro da carteira. Um teste que posta só um pedaço do
     * form testaria um cenário que a aplicação não produz e pressionaria a produção a aceitar o reset.
     *
     * @param array<string, string> $campos
     *
     * @return array<string, string>
     */
    private static function payloadConfiguracao(array $campos): array
    {
        return array_merge([
            'modo' => 'unico',
            'formaHonorarios' => 'sem_percentual',
            'toleranciaAtrasoDias' => '0',
            'taxaJurosMensalBp' => '0,00',
            'regimeJuros' => 'simples',
            'taxaMultaBp' => '0,00',
            'baseMulta' => 'principal',
            'taxaCorrecaoBp' => '0,00',
            'baseCorrecao' => 'principal',
            'baseHonorarios' => 'composta',
            'carenciaHonorariosDias' => '',
            'toleranciaJurosMultaDias' => '0',
        ], $campos);
    }

    #[TestDox('Editar configuração: os 9 campos de encargo são gravados na carteira')]
    public function testConfigurarGravaEncargos(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_carteira');

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/configuracao', [
            'editar_configuracao_carteira' => self::payloadConfiguracao([
                'taxaJurosMensalBp' => '1,00',
                'regimeJuros' => 'composto',
                'taxaMultaBp' => '2,00',
                'baseMulta' => 'composta',
                'taxaCorrecaoBp' => '0,50',
                'baseCorrecao' => 'composta',
                'baseHonorarios' => 'principal',
                'carenciaHonorariosDias' => '30',
                'toleranciaJurosMultaDias' => '3',
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Carteira::class, $carteiraId);
        self::assertSame(100, $recarregada->getTaxaJurosMensalBp());
        self::assertSame(RegimeJuros::Composto, $recarregada->getRegimeJuros());
        self::assertSame(200, $recarregada->getTaxaMultaBp());
        self::assertSame(BaseEncargo::Composta, $recarregada->getBaseMulta());
        self::assertSame(50, $recarregada->getTaxaCorrecaoBp());
        self::assertSame(BaseEncargo::Composta, $recarregada->getBaseCorrecao());
        self::assertSame(BaseEncargo::Principal, $recarregada->getBaseHonorarios());
        self::assertSame(30, $recarregada->getCarenciaHonorariosDias());
        self::assertSame(3, $recarregada->getToleranciaJurosMultaDias());
    }

    /**
     * Guarda de regressão do ciclo salvar → reabrir → salvar de novo: a base escolhida tem de
     * sobreviver a uma segunda edição que mexe em outro campo.
     *
     * HONESTIDADE SOBRE O ALCANCE: este teste NÃO discrimina a remoção do `empty_data` — ele passaria
     * com ou sem ele, porque posta o form inteiro. Não existe entrada que discrimine sem cair em erro:
     * a única forma de acionar o `empty_data` é omitir o campo (ou mandá-lo vazio), e aí, sem
     * `empty_data`, o mapeamento escreve null num enum tipado e estoura — o cenário some do alcance de
     * um teste de comportamento. O que trava a decisão é o comentário no Form; isto aqui cobre a
     * classe do bug ("config de carteira volta ao default sozinha") pelo lado do fluxo normal.
     */
    #[TestDox('Ciclo salvar → reabrir → salvar preserva a base de encargo escolhida')]
    public function testConfigurarPreservaBaseEmEdicaoSeguinte(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        // 1º POST: gestor escolhe base COMPOSTA para a multa.
        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_carteira');
        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/configuracao', [
            'editar_configuracao_carteira' => self::payloadConfiguracao([
                'taxaMultaBp' => '2,00',
                'baseMulta' => 'composta',
                '_token' => $token,
            ]),
        ]);

        // 2º POST: mexe só no rótulo; o form volta INTEIRO (é o que o navegador envia), então a base
        // escolhida tem de sobreviver. Este é o cenário que o `empty_data` corrompia em silêncio.
        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_carteira');
        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/configuracao', [
            'editar_configuracao_carteira' => self::payloadConfiguracao([
                'rotuloObjeto' => 'Unidade',
                'taxaMultaBp' => '2,00',
                'baseMulta' => 'composta',
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Carteira::class, $carteiraId);
        self::assertSame(BaseEncargo::Composta, $recarregada->getBaseMulta(), 'a base da multa não pode voltar ao default sozinha');
        self::assertSame(200, $recarregada->getTaxaMultaBp());
    }

    #[TestDox('Editar configuração: percentual em pt-BR (vírgula) é gravado no formato decimal')]
    public function testConfigurarPercentualComVirgula(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_carteira');

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/configuracao', [
            'editar_configuracao_carteira' => self::payloadConfiguracao([
                'modo' => 'unico',
                'formaHonorarios' => 'retido_recuperado',
                'percentualHonorarios' => '12,75',
                'toleranciaAtrasoDias' => '0',
                '_token' => $token,
            ]),
        ]);

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteiraId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Carteira::class, $carteiraId);
        self::assertSame('12.75', $recarregada->getPercentualHonorarios());
    }

    #[TestDox('IDOR: configurar carteira de OUTRO tenant devolve 404')]
    public function testConfigurarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [$carteiraAlheia] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraAlheia->getId() . '/configuracao', [
            'editar_configuracao_carteira' => ['_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Criar objeto: happy path persiste o objeto na carteira')]
    public function testCriarObjetoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteiraId);
        $token = $this->tokenDoFormulario($crawler, 'criar_objeto');

        $client->request('POST', '/cobrancas/carteiras/' . $carteiraId . '/objetos', [
            'criar_objeto' => ['identificacao' => 'Apto 101 Teste', 'nomeCobrado' => 'Devedor Teste', '_token' => $token],
        ]);

        // Ajuste 2: criar o objeto já inicia a cobrança e cai na página do objeto.
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#/cobrancas/objetos/\d+#', (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $objetos = $em->getRepository(ObjetoCobranca::class)->findBy(['identificacao' => 'Apto 101 Teste']);
        self::assertCount(1, $objetos);
        // A cobrança já nasce: caso âncora com a pessoa cobrada informada.
        $caso = $em->getRepository(CasoCobranca::class)->findOneBy(['objeto' => $objetos[0]]);
        self::assertNotNull($caso);
        self::assertSame('Devedor Teste', $caso->getPessoaCobradaAtual()?->getNome());
    }

    #[TestDox('Criar objeto sem a capacidade: negado, nada é criado')]
    public function testCriarObjetoSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [$carteira] = $this->semearGrafo($tenant);

        $client->request('POST', '/cobrancas/carteiras/' . $carteira->getId() . '/objetos', [
            'criar_objeto' => ['identificacao' => 'Objeto Bloqueado', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(ObjetoCobranca::class)->findBy(['identificacao' => 'Objeto Bloqueado']));
    }
}
