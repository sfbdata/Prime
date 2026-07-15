<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\AcordoController;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Edição do Acordo (Ajuste 7, Fatia 4) via HTTP: capacidade `gerenciar`, CSRF (Form), anti-IDOR (404)
 * e as guardas do domínio chegando ao usuário como flash — sem tocar o que já foi pago (INV-C).
 */
#[CoversClass(AcordoController::class)]
final class AcordoEdicaoControllerTest extends CobrancaWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Editar acordo: altera a parcela não paga e atualiza o snapshot do total')]
    public function testEditarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo,
            'valorTotalNegociado' => 60000, 'valorEntrada' => 0,
        ])->_real();
        $p1 = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'vencimentoOriginal' => new \DateTimeImmutable('2026-09-01'), 'acordoOrigem' => $acordo])->_real();
        $p2 = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 2/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'vencimentoOriginal' => new \DateTimeImmutable('2026-10-01'), 'acordoOrigem' => $acordo])->_real();
        $acordoId = (int) $acordo->getId();
        $p1Id = (int) $p1->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_acordo');

        // Total sobe para 700,00: p1 vira 400,00 e p2 fica 300,00.
        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/editar', [
            'editar_acordo' => [
                'valorTotalNegociado' => '700,00',
                'parcelas' => [
                    ['obrigacaoId' => (string) $p1Id, 'descricao' => 'Parcela 1/2', 'valor' => '400,00', 'vencimento' => '2026-09-20'],
                    ['obrigacaoId' => (string) $p2->getId(), 'descricao' => 'Parcela 2/2', 'valor' => '300,00', 'vencimento' => '2026-10-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $this->em()->clear();
        $p1Fresh = $this->em()->find(Obrigacao::class, $p1Id);
        self::assertSame(40000, $p1Fresh->getValorOriginal());
        self::assertSame('2026-09-20', $p1Fresh->getVencimentoOriginal()->format('Y-m-d'));
        self::assertSame(70000, $this->em()->find(\App\Cobranca\Entity\Acordo::class, $acordoId)->getValorTotalNegociado());
    }

    #[TestDox('Editar acordo: parcela ausente do envio é removida; nova é criada')]
    public function testEditarRemoveEAdicionaParcelas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo, 'valorTotalNegociado' => 60000])->_real();
        $p1 = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo])->_real();
        ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 2/2', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_acordo');

        // Mantém p1, omite p2 (remove) e adiciona uma nova de 300,00.
        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/editar', [
            'editar_acordo' => [
                'valorTotalNegociado' => '600,00',
                'parcelas' => [
                    ['obrigacaoId' => (string) $p1->getId(), 'descricao' => 'Parcela 1/2', 'valor' => '300,00', 'vencimento' => '2026-09-01'],
                    ['obrigacaoId' => '', 'descricao' => 'Parcela nova', 'valor' => '300,00', 'vencimento' => '2026-12-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $this->em()->clear();
        $acordoFresh = $this->em()->find(\App\Cobranca\Entity\Acordo::class, $acordoId);
        $descricoes = array_map(static fn (Obrigacao $o): string => $o->getDescricao(), $acordoFresh->getParcelas()->toArray());
        sort($descricoes);
        self::assertSame(['Parcela 1/2', 'Parcela nova'], $descricoes, 'a 2/2 saiu e a nova entrou');
    }

    #[TestDox('Editar acordo: parcela com pagamento alocado NÃO muda (INV-C), nada é gravado')]
    public function testEditarNaoAlteraParcelaPaga(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo, 'valorTotalNegociado' => 30000])->_real();
        $paga = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'vencimentoOriginal' => new \DateTimeImmutable('2026-09-01'), 'acordoOrigem' => $acordo])->_real();
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 15000, 'valorHonorarios' => 0])->_real();
        AlocacaoPagamentoFactory::createOne(['tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $paga, 'valor' => 15000]);
        $acordoId = (int) $acordo->getId();
        $pagaId = (int) $paga->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/editar', [
            'editar_acordo' => [
                'valorTotalNegociado' => '100,00',
                'parcelas' => [
                    ['obrigacaoId' => (string) $pagaId, 'descricao' => 'Parcela 1/1', 'valor' => '100,00', 'vencimento' => '2026-09-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $this->em()->clear();
        self::assertSame(30000, $this->em()->find(Obrigacao::class, $pagaId)->getValorOriginal(), 'a parcela paga é congelada');
    }

    #[TestDox('Editar acordo: total que não fecha com as parcelas é rejeitado (INV-B)')]
    public function testEditarTotalNaoFechaRejeitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo, 'valorTotalNegociado' => 30000])->_real();
        $p1 = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo])->_real();
        $acordoId = (int) $acordo->getId();
        $p1Id = (int) $p1->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/editar', [
            'editar_acordo' => [
                'valorTotalNegociado' => '999,00',
                'parcelas' => [
                    ['obrigacaoId' => (string) $p1Id, 'descricao' => 'Parcela 1/1', 'valor' => '100,00', 'vencimento' => '2026-09-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $this->em()->clear();
        self::assertSame(30000, $this->em()->find(Obrigacao::class, $p1Id)->getValorOriginal(), 'nada é gravado quando não fecha');
    }

    #[TestDox('Alterar parcela gerida por outro acordo vigente: vira aviso (não 500) e nada muda')]
    public function testEditarParcelaSubstituidaPorOutroAcordoViraFlash(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordoA = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo, 'valorTotalNegociado' => 30000])->_real();
        // Acordo B (vigente) substituiu a parcela do acordo A — quem a gere agora é B.
        $acordoB = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1',
            'valorOriginal' => 30000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-09-01'),
            'acordoOrigem' => $acordoA, 'acordoSubstituto' => $acordoB,
        ])->_real();
        $acordoId = (int) $acordoA->getId();
        $parcelaId = (int) $parcela->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/editar', [
            'editar_acordo' => [
                'valorTotalNegociado' => '100,00',
                'parcelas' => [
                    ['obrigacaoId' => (string) $parcelaId, 'descricao' => 'Parcela 1/1', 'valor' => '100,00', 'vencimento' => '2026-09-01'],
                ],
                '_token' => $token,
            ],
        ]);

        // O guard tem de chegar ao usuário como aviso — não como erro 500.
        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $this->em()->clear();
        self::assertSame(30000, $this->em()->find(Obrigacao::class, $parcelaId)->getValorOriginal());
    }

    #[TestDox('Parcela gerida por outro acordo, reenviada intacta: não trava a edição das demais')]
    public function testEditarComParcelaSubstituidaIntactaEditaAsDemais(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordoA = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo, 'valorTotalNegociado' => 60000])->_real();
        $acordoB = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $travada = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/2',
            'valorOriginal' => 30000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-09-01'),
            'acordoOrigem' => $acordoA, 'acordoSubstituto' => $acordoB,
        ])->_real();
        $livre = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 2/2',
            'valorOriginal' => 30000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-10-01'),
            'acordoOrigem' => $acordoA,
        ])->_real();
        $acordoId = (int) $acordoA->getId();
        $livreId = (int) $livre->getId();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/editar', [
            'editar_acordo' => [
                'valorTotalNegociado' => '700,00',
                'parcelas' => [
                    // Travada volta intacta (como a UI faz)…
                    ['obrigacaoId' => (string) $travada->getId(), 'descricao' => 'Parcela 1/2', 'valor' => '300,00', 'vencimento' => '2026-09-01'],
                    // …e a livre é alterada.
                    ['obrigacaoId' => (string) $livreId, 'descricao' => 'Parcela 2/2', 'valor' => '400,00', 'vencimento' => '2026-10-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $this->em()->clear();
        self::assertSame(40000, $this->em()->find(Obrigacao::class, $livreId)->getValorOriginal(), 'a edição das demais não pode ser bloqueada');
    }

    #[TestDox('Editar acordo não ativo: recusado, parcela intacta')]
    public function testEditarAcordoNaoAtivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Cancelado])->_real();
        $p1 = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'descricao' => 'Parcela 1/1', 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo])->_real();
        $acordoId = (int) $acordo->getId();
        $p1Id = (int) $p1->getId();

        // Acordo não-ativo não renderiza o form (podeAgir=false): CSRF vem de outro form do app.
        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/editar', [
            'editar_acordo' => [
                'valorTotalNegociado' => '100,00',
                'parcelas' => [['obrigacaoId' => (string) $p1Id, 'descricao' => 'X', 'valor' => '100,00', 'vencimento' => '2026-09-01']],
                '_token' => 'invalido',
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/acordos/' . $acordoId);
        $this->em()->clear();
        self::assertSame(30000, $this->em()->find(Obrigacao::class, $p1Id)->getValorOriginal());
    }

    #[TestDox('Editar acordo sem a capacidade de gerenciar: negado')]
    public function testEditarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $p1 = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 30000, 'encargosReconhecidos' => 0, 'acordoOrigem' => $acordo])->_real();
        $p1Id = (int) $p1->getId();

        $client->request('POST', '/cobrancas/acordos/' . $acordo->getId() . '/editar', [
            'editar_acordo' => [
                'valorTotalNegociado' => '100,00',
                'parcelas' => [['obrigacaoId' => (string) $p1Id, 'descricao' => 'X', 'valor' => '100,00', 'vencimento' => '2026-09-01']],
                '_token' => 'irrelevante',
            ],
        ]);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame(30000, $this->em()->find(Obrigacao::class, $p1Id)->getValorOriginal());
    }

    #[TestDox('IDOR: editar acordo de outro tenant → 404')]
    public function testEditarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio, 'status' => StatusAcordo::Ativo])->_real();

        $client->request('POST', '/cobrancas/acordos/' . $acordoAlheio->getId() . '/editar', [
            'editar_acordo' => ['valorTotalNegociado' => '100,00', 'parcelas' => [], '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Detalhe: barra de ações e editor só aparecem para quem pode gerenciar')]
    public function testBarraDeAcoesGatedPelaCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();

        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordo->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#modalEditarAcordo'), 'sem capacidade não renderiza o editor');
        self::assertStringNotContainsString('Editar acordo', $crawler->filter('.cobrancas-page')->text());
    }
}
