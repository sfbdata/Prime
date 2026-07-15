<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\AcordoController;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Ajuste 9 (INV-I/INV-L) pelo CAMINHO HTTP REAL: "acordo sobre acordo" é recusado na criação, no
 * rompimento e no cancelamento, e a recusa é sempre um ERRO AMIGÁVEL — nunca um 500.
 *
 * Em TODOS os três, quem aplica a regra é o guard do UseCase, e o caminho HTTP o alcança de verdade. No
 * `criar` isso depende da assimetria deliberada do `AcordoController::criar` (POST valida contra as
 * EXIGÍVEIS; só o render filtra as substituíveis — ver spec §5.1.1): igualar as duas listas faria o
 * ChoiceType barrar antes, deixando guard e `catch` inalcançáveis e ESTES TESTES passariam sem provar
 * nada. Foi exatamente esse o bloqueante da revisão, e é por isso que
 * `testCriarAcordoSobreParcelaDeAcordoVigenteEhRecusado` exige a MENSAGEM do guard no flash: um form
 * inválido também devolve 302 para a mesma URL sem criar acordo.
 *
 * Por que functional e não só unit: uma exception ausente do `catch` do controller vira 500, e o unit do
 * UseCase não enxerga o `catch` (regressão já cometida no item 7, fatia 4).
 */
#[CoversClass(AcordoController::class)]
final class AcordoSobreAcordoBloqueadoControllerTest extends CobrancaWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Criar acordo substituindo parcela de acordo vigente: recusado sem 500 e sem criar acordo')]
    public function testCriarAcordoSobreParcelaDeAcordoVigenteEhRecusado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        // Acordo A VIGENTE e sua parcela: é ela que o gestor tentará renegociar num acordo B.
        $acordoVigente = AcordoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'status' => StatusAcordo::Ativo,
        ])->_real();
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => 'Parcela 1/3 do acordo vigente',
            'valorOriginal' => 100000,
            'encargosReconhecidos' => 0,
        ])->_real();
        $parcela->setAcordoOrigem($acordoVigente);
        $this->em()->flush();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'acordo_criar');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => [
                'dataAcordo' => '2026-08-01',
                'obrigacoesSubstituidasIds' => [(string) $parcela->getId()],
                'parcelas' => [
                    ['descricao' => 'Parcela 1/1', 'valor' => '500,00', 'vencimento' => '2026-09-01'],
                ],
                '_token' => $token,
            ],
        ]);

        // INV-L: recusa é erro tratado, não estouro.
        self::assertResponseRedirects(
            '/cobrancas/objetos/' . $caso->getObjeto()->getId(),
            null,
            'A recusa de acordo sobre acordo deve redirecionar com flash, nunca dar 500.',
        );

        // DISCRIMINANTE (achado da revisão): sem asserir a MENSAGEM, este teste passaria pelo motivo
        // errado — um form inválido também redireciona 302 para a mesma URL sem criar acordo. Exigir o
        // texto do guard prova que a requisição chegou ao `CriarAcordoUseCase` E que a exception foi
        // capturada em `AcordoController` (INV-L). Se o catch sumir, isto vira 500 e o teste quebra.
        $flashes = $client->getRequest()->getSession()->getFlashBag()->peekAll()['danger'] ?? [];
        self::assertNotEmpty($flashes, 'A recusa precisa avisar o gestor, não falhar em silêncio.');
        self::assertStringContainsString(
            'é parcela de um acordo',
            implode(' ', $flashes),
            'O gestor deve ver a mensagem de domínio do guard, não um "valor inválido" do ChoiceType.',
        );

        // O acordo B NÃO existe: só o acordo A vigente do cenário permanece.
        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        $acordos = static::getContainer()->get(AcordoRepository::class)->doCaso($casoFresh);
        self::assertCount(1, $acordos, 'Nenhum acordo novo pode ser criado sobre parcela de acordo vigente.');
        self::assertSame(StatusAcordo::Ativo, $acordos[0]->getStatus());
    }

    #[TestDox('INV-L: romper acordo com parcelas renegociadas é recusado com flash, não com 500')]
    public function testRomperAcordoComParcelasRenegociadasEhRecusadoSem500(): void
    {
        // Estado LEGADO montado à mão (a criação hoje o bloqueia — INV-I): B vigente renegociou parcela de
        // A. Romper A aqui duplicaria a dívida no saldo; o guard barra, e a barragem tem de ser amigável.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acordoA = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoB = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => 'Parcela de A renegociada por B',
            'valorOriginal' => 50000,
            'encargosReconhecidos' => 0,
        ])->_real();
        $parcela->setAcordoOrigem($acordoA)->setAcordoSubstituto($acordoB);
        $this->em()->flush();
        $acordoAId = (int) $acordoA->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'romper_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoAId . '/romper', [
            'romper_acordo' => [
                'motivo' => 'Devedor descumpriu',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects(
            '/cobrancas/objetos/' . $caso->getObjeto()->getId(),
            null,
            'A recusa do rompimento deve redirecionar com flash, nunca dar 500.',
        );

        // O acordo A continua ATIVO: a recusa acontece antes de qualquer efeito.
        $this->em()->clear();
        $acordoFresh = $this->em()->find(Acordo::class, $acordoAId);
        self::assertSame(
            StatusAcordo::Ativo,
            $acordoFresh->getStatus(),
            'O acordo não pode ser rompido enquanto outro acordo vigente guardar parcelas dele.',
        );
    }

    #[TestDox('INV-L: cancelar acordo com parcelas renegociadas é recusado com flash, não com 500')]
    public function testCancelarAcordoComParcelasRenegociadasEhRecusadoSem500(): void
    {
        // Cancelar passa pelo mesmo helper `mutarAcordoComMotivo` do romper, mas "compartilha o catch"
        // é presunção — este teste prova o caminho HTTP do cancelar por conta própria.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $acordoA = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoB = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => 'Parcela de A renegociada por B',
            'valorOriginal' => 50000,
            'encargosReconhecidos' => 0,
        ])->_real();
        $parcela->setAcordoOrigem($acordoA)->setAcordoSubstituto($acordoB);
        $this->em()->flush();
        $acordoAId = (int) $acordoA->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'cancelar_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoAId . '/cancelar', [
            'cancelar_acordo' => [
                'motivo' => 'Firmado por engano',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects(
            '/cobrancas/objetos/' . $caso->getObjeto()->getId(),
            null,
            'A recusa do cancelamento deve redirecionar com flash, nunca dar 500.',
        );

        $this->em()->clear();
        $acordoFresh = $this->em()->find(Acordo::class, $acordoAId);
        self::assertSame(
            StatusAcordo::Ativo,
            $acordoFresh->getStatus(),
            'O acordo não pode ser cancelado enquanto outro acordo vigente guardar parcelas dele.',
        );
    }

    #[TestDox('INV-K: bloquear acordo sobre acordo NÃO impede PAGAR parcela de acordo vigente')]
    public function testPagarParcelaDeAcordoVigenteContinuaFuncionando(): void
    {
        // Não-regressão do vizinho de risco: `AcordoCriarType::opcoesObrigacoes` é um helper COMPARTILHADO
        // entre o modal de acordo e os de pagamento. Filtrar no helper (em vez de na lista passada ao form
        // de acordo) quebraria o fluxo mais usado do módulo — quitar a parcela de um acordo em curso.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $acordoVigente = AcordoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'status' => StatusAcordo::Ativo,
        ])->_real();
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => 'Parcela 1/3 do acordo vigente',
            'valorOriginal' => 10000,
            'encargosReconhecidos' => 0,
        ])->_real();
        $parcela->setAcordoOrigem($acordoVigente);
        $this->em()->flush();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_pagamento');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pagamentos', [
            'registrar_pagamento' => [
                'data' => '2026-05-10',
                'valorPago' => '100,00',
                'alocarManualmente' => '1',
                'alocacoes' => [['obrigacaoId' => (string) $parcela->getId(), 'valor' => '100,00']],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        $pagamentos = static::getContainer()->get(PagamentoRepository::class)->doCaso($casoFresh);
        self::assertCount(1, $pagamentos, 'Pagar parcela de acordo vigente deve continuar funcionando.');
        self::assertSame(10000, $pagamentos[0]->getValorDivida());
    }

    #[TestDox('A parcela de acordo vigente nem é oferecida como substituível na tela')]
    public function testParcelaDeAcordoVigenteNaoEhOferecidaNaTela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Dívida original: DEVE aparecer como opção de acordo.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => 'Divida original acordavel',
            'valorOriginal' => 100000,
            'encargosReconhecidos' => 0,
        ])->_real();

        // Parcela de acordo vigente: NÃO pode aparecer como opção de acordo.
        $acordoVigente = AcordoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'status' => StatusAcordo::Ativo,
        ])->_real();
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => 'Parcela do acordo vigente',
            'valorOriginal' => 50000,
            'encargosReconhecidos' => 0,
        ])->_real();
        $parcela->setAcordoOrigem($acordoVigente);
        $this->em()->flush();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        // As checkboxes de substituíveis do form de acordo — a parcela não pode estar entre elas.
        $idsOferecidos = $crawler
            ->filter('input[name="acordo_criar[obrigacoesSubstituidasIds][]"]')
            ->each(static fn ($node): string => (string) $node->attr('value'));

        self::assertNotContains(
            (string) $parcela->getId(),
            $idsOferecidos,
            'Parcela de acordo vigente não pode ser oferecida como substituível (acordo sobre acordo).',
        );
        self::assertNotEmpty($idsOferecidos, 'A dívida original deve seguir sendo oferecida.');
    }

    #[TestDox('D3: caso 100% dentro de um acordo mostra o aviso, não uma lista vazia sem explicação')]
    public function testSemDividaAcordavelMostraAvisoExplicativo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Tudo que o caso tem é parcela de acordo vigente: não sobra nada de acordável.
        $acordoVigente = AcordoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'status' => StatusAcordo::Ativo,
        ])->_real();
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => 'Unica parcela do acordo vigente',
            'valorOriginal' => 50000,
            'encargosReconhecidos' => 0,
        ])->_real();
        $parcela->setAcordoOrigem($acordoVigente);
        $this->em()->flush();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $crawler->filter('input[name="acordo_criar[obrigacoesSubstituidasIds][]"]'),
            'Nenhuma obrigação deve ser oferecida quando tudo está dentro de um acordo vigente.',
        );
        self::assertStringContainsString(
            'rompa-o primeiro',
            $crawler->filter('#corpoCriarAcordo')->text(),
            'O gestor precisa ver o caminho (romper o acordo), não um espaço em branco.',
        );
    }
}
