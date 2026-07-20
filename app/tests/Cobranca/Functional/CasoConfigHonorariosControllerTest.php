<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
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
 */
#[CoversClass(CasoController::class)]
final class CasoConfigHonorariosControllerTest extends CobrancaWebTestCase
{
    #[TestDox('POST válido: redireciona ao objeto, persiste os honorários e recalcula a automática viva')]
    public function testEditarHonorariosValidoRecalcula(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$caso, $obrigacao] = $this->semearParaRecalculo($tenant, '10.00');
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_caso');

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
        self::assertGreaterThan(0, $recarregada->getHonorarios(), 'a automática viva teve o honorário recalculado na hora');
        self::assertGreaterThan(0, $recarregada->getJuros(), 'os demais encargos também foram materializados para hoje');
        self::assertFalse($recarregada->encargosCongelados(), 'recálculo automático não congela (INV-E4)');
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

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_caso');

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

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_configuracao_caso');

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
     * (2020-01-01, R$ 1.000,00), tudo no MESMO tenant. Devolve [Caso, Obrigação] reais.
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
        ]);

        return [$caso->_real(), $obrigacao->_real()];
    }
}
