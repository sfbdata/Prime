<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusAcordo;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Editar os honorários do caso âncora (Ajuste 2, Fatia A). Cobre gate módulo + capacidade, CSRF,
 * anti-IDOR (404), o recálculo imediato das automáticas vivas (D-A2-3), o INV-E4 (congelada intacta)
 * e o fluxo de erro B5 (reabre o modal sem 500).
 *
 * ⚠️ T1 (cascata de encargos ao vivo sem snapshot, #9): `ResolvedorConfigEncargos::resolverDoCaso`
 * passou a delegar ao OBJETO/CARTEIRA e não lê mais `formaHonorarios`/`percentualHonorarios` do
 * CASO. Este UseCase/endpoint continua gravando essas colunas do caso, mas elas viraram sombra/mortas
 * para fins de CÁLCULO: o percentual submetido aqui não influencia mais o honorário recalculado — a
 * alíquota efetiva é a da CARTEIRA (fixa em 10% nos seeds abaixo). Os testes foram ajustados para
 * refletir essa taxa viva (15200 em vez de 30400) em vez do percentual do formulário.
 *
 * ⚠️ #9-T3 RESOLVEU a regressão anotada acima pela T1: o modal `#modalEditarConfigCaso` (e o botão que
 * o abria) SAIU da tela (`cobranca/objeto/show.html.twig` e `cobranca/caso/_acoes_modais.html.twig`) —
 * o "meio" da cascata agora tem UI própria no OBJETO (`ObjetoConfigEncargosControllerTest`,
 * `#modalConfigEncargosObjeto`). Este endpoint (`CasoController::editarConfiguracaoHonorarios`, rota
 * `cobranca_caso_editar_config`) e o Form/UseCase/DTO seguem DORMENTES de propósito (reversível, spec
 * §5/§9) — a suíte abaixo prova que o backend continua funcionando por baixo, mesmo sem UI. Como o
 * `<input>` do form não existe mais na página, o token CSRF já não pode ser raspado do DOM
 * (`tokenDoFormulario`) — usa `tokenCsrf()` (gerado direto pelo `CsrfTokenManagerInterface` da mesma
 * intenção `editar_configuracao_caso`, ver `CobrancaWebTestCase`).
 */
#[CoversClass(CasoController::class)]
final class CasoConfigHonorariosControllerTest extends CobrancaWebTestCase
{
    #[TestDox('POST válido: recalcula o cache do honorário pela taxa VIVA da carteira; o exigível (juros/multa) fica intacto')]
    public function testEditarHonorariosValidoRecalculaSoOHonorario(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Seed: exigível materializado juros 500 · multa 20 (base composta 1.520,00), honorário 50 a 10%.
        [$caso, $obrigacao] = $this->semearParaRecalculo($tenant, '10.00');
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenCsrf($client, 'editar_configuracao_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/configuracao-honorarios', [
            'editar_configuracao_caso' => [
                'formaHonorarios' => FormaHonorarios::AcrescidoDivida->value,
                'percentualHonorarios' => '20,00',
                'baseHonorarios' => '',
                'carenciaHonorariosDias' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        self::assertSame('20.00', $em->find(CasoCobranca::class, $casoId)->getPercentualHonorarios());

        $recarregada = $em->find(Obrigacao::class, $obrigacaoId);
        // T1: a alíquota efetiva vem da CARTEIRA (10%, fixa — o "20,00" submetido só grava o
        // snapshot morto do caso). Base composta (100000 + 50000 + 2000 + 0 = 152000) × 10% = 15200.
        self::assertSame(15200, $recarregada->getHonorarios(), 'o honorário foi recalculado pela taxa viva da carteira (10%)');
        // EXIGÍVEL INTACTO (o fix do bloqueante): editar honorários NÃO move juros/multa/correção.
        self::assertSame(50000, $recarregada->getJuros(), 'juros (exigível, INV-E1) preservado — não recomputado');
        self::assertSame(2000, $recarregada->getMulta(), 'multa (exigível) preservada');
        self::assertSame(0, $recarregada->getCorrecao(), 'correção (exigível) preservada');
        self::assertFalse($recarregada->encargosCongelados(), 'recálculo do honorário não congela (INV-E4)');
    }

    #[TestDox('Bomba F2 fechada: com a taxa de juros da carteira zerada, editar honorários NÃO reduz o exigível')]
    public function testEditarHonorariosNaoReduzExigivelComTaxaDaCarteiraZerada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$caso, $obrigacao] = $this->semearParaRecalculo($tenant, '10.00');
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        // Cenário da F2: a taxa de juros/multa da carteira é BAIXADA A ZERO depois do exigível já
        // materializado (juros 500 represados). Antes do fix, editar honorários chamava calcular() e
        // recompunha juros para 0 (taxa nova), apagando o exigível em lote, sem o freio do cron.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $carteira = $caso->getObjeto()->getCarteira();
        $carteira->setTaxaJurosMensalBp(0);
        $carteira->setTaxaMultaBp(0);
        $em->flush();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenCsrf($client, 'editar_configuracao_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/configuracao-honorarios', [
            'editar_configuracao_caso' => [
                'formaHonorarios' => FormaHonorarios::AcrescidoDivida->value,
                'percentualHonorarios' => '20,00',
                'baseHonorarios' => '',
                'carenciaHonorariosDias' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em->clear();
        $recarregada = $em->find(Obrigacao::class, $obrigacaoId);
        // O exigível NÃO despencou para a taxa zerada — juros/multa continuam os R$ 500/R$ 20 reconhecidos.
        self::assertSame(50000, $recarregada->getJuros(), 'INV-E1: exigível não reduzido pela taxa zerada (bomba F2 fechada)');
        self::assertSame(2000, $recarregada->getMulta(), 'INV-E1: multa preservada');
        // O honorário foi recalculado normalmente (fora do exigível, INV-E2, pode mudar), pela taxa
        // viva da carteira (10%, intocada por este teste) — T1: mesma base composta do teste acima.
        self::assertSame(15200, $recarregada->getHonorarios(), 'só o honorário mudou, pela taxa da carteira');
    }

    #[TestDox('Recálculo NÃO toca parcela de acordo vigente nem obrigação substituída (valor pactuado)')]
    public function testRecalculoNaoTocaParcelaNemSubstituida(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$caso, $automatica] = $this->semearParaRecalculo($tenant, '10.00');
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        // Acordo VIGENTE no caso âncora. A parcela (acordoOrigem) e a substituída (acordoSubstituto) têm
        // valores PACTUADOS: o recálculo de honorários NÃO pode tocá-las (mesma exclusão do cron:
        // `aorig.id IS NULL` e `asub` não-vigente). Sem este teste, apagar essas cláusulas do query novo
        // sobrescreveria dinheiro negociado com a suíte inteira verde.
        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo,
        ])->_real();

        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1',
            'valorOriginal' => 60000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo,
        ])->_real();
        $parcela->definirEncargos(111, 222, 333, 444, new \DateTimeImmutable('2026-02-01'));

        $substituida = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Trocada pelo acordo',
            'valorOriginal' => 70000, 'encargosReconhecidos' => 0, 'acordoSubstituto' => $acordo,
        ])->_real();
        $substituida->definirEncargos(555, 666, 777, 888, new \DateTimeImmutable('2026-02-01'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $parcelaId = (int) $parcela->getId();
        $substituidaId = (int) $substituida->getId();
        $automaticaId = (int) $automatica->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenCsrf($client, 'editar_configuracao_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/configuracao-honorarios', [
            'editar_configuracao_caso' => [
                'formaHonorarios' => FormaHonorarios::AcrescidoDivida->value,
                'percentualHonorarios' => '20,00',
                'baseHonorarios' => '',
                'carenciaHonorariosDias' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $em->clear();

        // Parcela de acordo vigente: INTACTA (valor pactuado, não recebe recálculo de honorário).
        $parcelaRec = $em->find(Obrigacao::class, $parcelaId);
        self::assertSame(444, $parcelaRec->getHonorarios(), 'parcela de acordo vigente não é tocada');
        self::assertSame(111, $parcelaRec->getJuros());
        // Substituída por acordo vigente: INTACTA (histórico fora do saldo).
        $substituidaRec = $em->find(Obrigacao::class, $substituidaId);
        self::assertSame(888, $substituidaRec->getHonorarios(), 'substituída por acordo vigente não é tocada');
        // A automática viva, sim: honorário recalculado (T1: 15200 a 10% — taxa viva da carteira — de 152000).
        $automaticaRec = $em->find(Obrigacao::class, $automaticaId);
        self::assertSame(15200, $automaticaRec->getHonorarios(), 'a automática viva teve o honorário recalculado');
    }

    #[TestDox('INV-E4: a congelada do caso não é recalculada; a automática ao lado é')]
    public function testCongeladaNaoEhRecalculada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$caso, $automatica] = $this->semearParaRecalculo($tenant, '10.00');
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        // Congelada com valores distintos que o recálculo NÃO produziria.
        $congelada = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorOriginal' => 100000,
            'vencimentoOriginal' => new \DateTimeImmutable('2020-01-01'),
            'encargosReconhecidos' => 0,
        ])->_real();
        $congelada->definirEncargos(111, 222, 333, 444, new \DateTimeImmutable('2026-02-01'));
        $congelada->congelarEncargos(new \DateTimeImmutable('2026-02-01'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $congeladaId = (int) $congelada->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenCsrf($client, 'editar_configuracao_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/configuracao-honorarios', [
            'editar_configuracao_caso' => [
                'formaHonorarios' => FormaHonorarios::AcrescidoDivida->value,
                'percentualHonorarios' => '20,00',
                'baseHonorarios' => '',
                'carenciaHonorariosDias' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em->clear();
        $congeladaRecarregada = $em->find(Obrigacao::class, $congeladaId);
        self::assertSame(111, $congeladaRecarregada->getJuros(), 'INV-E4: congelada intacta');
        self::assertSame(444, $congeladaRecarregada->getHonorarios());

        $automaticaRecarregada = $em->find(Obrigacao::class, (int) $automatica->getId());
        self::assertGreaterThan(0, $automaticaRecarregada->getHonorarios(), 'a automática viva foi recalculada');
    }

    #[TestDox('POST inválido (percentual malformado): reabre o modal (B5) sem 500 e não muda a config')]
    public function testPercentualMalformadoReabreModalSem500(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$caso] = $this->semearParaRecalculo($tenant, '10.00');
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenCsrf($client, 'editar_configuracao_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/configuracao-honorarios', [
            'editar_configuracao_caso' => [
                'formaHonorarios' => FormaHonorarios::AcrescidoDivida->value,
                'percentualHonorarios' => '20.00.5', // malformado: falha o Regex do DTO (erro de campo)
                'baseHonorarios' => '',
                'carenciaHonorariosDias' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('10.00', $em->find(CasoCobranca::class, $casoId)->getPercentualHonorarios(), 'form inválido não chega ao UseCase');

        // O redirect reabre a página sem 500, com o marcador B5 apontando o modal.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-modal-erro="modalEditarConfigCaso"]');
    }

    #[TestDox('Footgun: forma percentual com percentual EM BRANCO é rejeitada (não zera honorários)')]
    public function testFormaPercentualComPercentualEmBrancoRejeita(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$caso, $obrigacao] = $this->semearParaRecalculo($tenant, '10.00');
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        // Materializa o honorário atual (a 10%) para provar depois que NÃO foi zerado.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $obrigacaoId = (int) $obrigacao->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenCsrf($client, 'editar_configuracao_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/configuracao-honorarios', [
            'editar_configuracao_caso' => [
                'formaHonorarios' => FormaHonorarios::AcrescidoDivida->value, // exige percentual
                'percentualHonorarios' => '', // em branco → alíquota 0 → zeraria honorários. A validação barra.
                'baseHonorarios' => '',
                'carenciaHonorariosDias' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);

        $em->clear();
        // Config intacta (não chegou ao UseCase) e, principalmente, o honorário NÃO foi zerado.
        self::assertSame('10.00', $em->find(CasoCobranca::class, $casoId)->getPercentualHonorarios());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-modal-erro="modalEditarConfigCaso"]');
    }

    #[TestDox('Sem a capacidade: negado (redirect para fora do caso), config intacta')]
    public function testSemCapacidadeNegado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [$caso] = $this->semearParaRecalculo($tenant, '10.00');
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/configuracao-honorarios', [
            'editar_configuracao_caso' => [
                'formaHonorarios' => FormaHonorarios::AcrescidoDivida->value,
                'percentualHonorarios' => '20,00',
                '_token' => 'irrelevante',
            ],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('10.00', $em->find(CasoCobranca::class, $casoId)->getPercentualHonorarios(), 'sem capacidade nada muda');
    }

    #[TestDox('IDOR: editar honorários de caso de OUTRO tenant devolve 404')]
    public function testCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [$casoAlheio] = $this->semearParaRecalculo($this->tenantAvulso(), '10.00');

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/configuracao-honorarios', [
            'editar_configuracao_caso' => [
                'formaHonorarios' => FormaHonorarios::AcrescidoDivida->value,
                'percentualHonorarios' => '20,00',
                '_token' => 'irrelevante',
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Semeia Carteira (juros 1%/multa 2%, honorários sobre base composta, carência 30) → Objeto → Caso
     * (snapshot AcrescidoDivida no percentual dado) → Pessoa + uma Obrigação AUTOMÁTICA vencida há muito
     * (2020-01-01, R$ 1.000,00) com o EXIGÍVEL já MATERIALIZADO (juros/multa > 0, NÃO congelada), tudo no
     * MESMO tenant. O exigível materializado é essencial para provar que editar a config de honorários NÃO
     * o move (o bug bloqueante que a auditoria pegou: o recálculo recompunha juros/multa e podia reduzi-los).
     * Devolve [Caso, Obrigação] reais.
     *
     * @return array{0: CasoCobranca, 1: Obrigacao}
     */
    private function semearParaRecalculo(Tenant $tenant, string $percentualCaso): array
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => $cliente,
            'taxaJurosMensalBp' => 100,
            'taxaMultaBp' => 200,
            'baseHonorarios' => BaseEncargo::Composta,
            'carenciaHonorariosDias' => 30,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => $percentualCaso,
        ]);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => $pessoa,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => $percentualCaso,
        ]);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorOriginal' => 100000,
            'vencimentoOriginal' => new \DateTimeImmutable('2020-01-01'),
            'encargosReconhecidos' => 0,
        ])->_real();
        // Materializa o exigível como o registro/cron fariam: juros R$ 500 · multa R$ 20 · correção 0, e um
        // honorário coerente com o percentual do caso (não importa o valor exato — o que o teste checa é que
        // juros/multa NÃO se movem ao editar a config e que o honorário É recalculado). NÃO congela.
        $obrigacao->definirEncargos(50000, 2000, 0, 5000, new \DateTimeImmutable('today'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return [$caso->_real(), $obrigacao];
    }
}
