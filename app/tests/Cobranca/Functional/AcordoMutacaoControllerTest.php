<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\AcordoController;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Entity\CasoCobranca;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de Acordos (Onda 8B): criar (substitui obrigações + parcelas), romper, cancelar, cumprir.
 * Gate módulo + capacidade `resources.cobranca.gerenciar`, CSRF (Form e manual no cumprir), anti-IDOR
 * (404), erro de domínio (acordo não ativo) e happy path com persistência.
 */
#[CoversClass(AcordoController::class)]
final class AcordoMutacaoControllerTest extends CobrancaWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Criar acordo: happy path cria o acordo substituindo a obrigação')]
    public function testCriarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'acordo_criar');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => [
                'dataAcordo' => '2026-08-01',
                'obrigacoesSubstituidasIds' => [(string) $obrigacao->getId()],
                'parcelas' => [
                    ['descricao' => 'Parcela 1/1', 'valor' => '500,00', 'vencimento' => '2026-09-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        $acordos = static::getContainer()->get(AcordoRepository::class)->doCaso($casoFresh);
        self::assertCount(1, $acordos, 'o acordo deve ter sido criado');
    }

    #[TestDox('Criar acordo sem capacidade: negado')]
    public function testCriarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => ['dataAcordo' => '2026-08-01', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: criar acordo em caso de outro tenant → 404')]
    public function testCriarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/acordos', [
            'acordo_criar' => ['dataAcordo' => '2026-08-01', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Criar acordo com CSRF inválido: não cria')]
    public function testCriarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000])->_real();
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => [
                'dataAcordo' => '2026-08-01',
                'obrigacoesSubstituidasIds' => [(string) $obrigacao->getId()],
                'parcelas' => [['descricao' => 'P', 'valor' => '500,00', 'vencimento' => '2026-09-01']],
                '_token' => 'falso',
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        self::assertCount(0, static::getContainer()->get(AcordoRepository::class)->doCaso($casoFresh));
    }

    #[TestDox('Criar acordo pelo gerador: grava snapshot (total/entrada) e cria a entrada como obrigação')]
    public function testCriarComGeradorGravaSnapshotEEntrada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'acordo_criar');

        // Total 1.000,00 = entrada 400,00 + 2 parcelas de 300,00.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => [
                'dataAcordo' => '2026-08-01',
                'valorTotalNegociado' => '1.000,00',
                'valorEntrada' => '400,00',
                'obrigacoesSubstituidasIds' => [(string) $obrigacao->getId()],
                'parcelas' => [
                    ['descricao' => 'Parcela 1/2', 'valor' => '300,00', 'vencimento' => '2026-09-01'],
                    ['descricao' => 'Parcela 2/2', 'valor' => '300,00', 'vencimento' => '2026-10-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        $acordos = static::getContainer()->get(AcordoRepository::class)->doCaso($casoFresh);
        self::assertCount(1, $acordos);
        $acordo = $acordos[0];
        self::assertSame(100000, $acordo->getValorTotalNegociado());
        self::assertSame(40000, $acordo->getValorEntrada());
        // Entrada + 2 parcelas = 3 obrigações-parcela; uma delas é a "Entrada".
        $descricoes = array_map(static fn ($p): string => $p->getDescricao(), $acordo->getParcelas()->toArray());
        self::assertContains('Entrada', $descricoes);
        self::assertCount(3, $descricoes);
    }

    #[TestDox('Criar acordo pelo gerador com total que não fecha: rejeitado (INV-B), nada criado')]
    public function testCriarComGeradorTotalNaoFechaRejeitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'acordo_criar');

        // Total 1.000,00 mas entrada 100,00 + parcela 500,00 = 600,00 ≠ 1.000,00.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/acordos', [
            'acordo_criar' => [
                'dataAcordo' => '2026-08-01',
                'valorTotalNegociado' => '1.000,00',
                'valorEntrada' => '100,00',
                'obrigacoesSubstituidasIds' => [(string) $obrigacao->getId()],
                'parcelas' => [
                    ['descricao' => 'Parcela 1/1', 'valor' => '500,00', 'vencimento' => '2026-09-01'],
                ],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        $casoFresh = $this->em()->find(CasoCobranca::class, $casoId);
        self::assertCount(0, static::getContainer()->get(AcordoRepository::class)->doCaso($casoFresh), 'INV-B deve barrar o acordo');
    }

    #[TestDox('Prévia de parcelamento: gera N parcelas que fecham com o total menos a entrada')]
    public function testPreviaParcelamentoHappy(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $client->request('GET', '/cobrancas/acordos/previa-parcelamento', [
            'total' => 100000, 'entrada' => 40000, 'qtd' => 3, 'data1' => '2026-09-01', 'periodicidade' => 'mensal',
        ]);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($dados['ok']);
        self::assertCount(3, $dados['parcelas']);
        // 60.000 em 3 = 20.000 cada; soma fecha com total − entrada.
        self::assertSame(60000, array_sum(array_column($dados['parcelas'], 'valor')));
        self::assertSame('2026-09-01', $dados['parcelas'][0]['vencimento']);
        self::assertSame('2026-11-01', $dados['parcelas'][2]['vencimento']);
    }

    #[TestDox('Prévia de parcelamento inválida (entrada consome o total): ok=false com erro')]
    public function testPreviaParcelamentoInvalida(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $client->request('GET', '/cobrancas/acordos/previa-parcelamento', [
            'total' => 50000, 'entrada' => 50000, 'qtd' => 3, 'data1' => '2026-09-01', 'periodicidade' => 'mensal',
        ]);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($dados['ok']);
        self::assertNotEmpty($dados['erro']);
    }

    #[TestDox('Prévia de parcelamento com data inválida (overflow 31/02): ok=false')]
    public function testPreviaParcelamentoDataInvalida(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $client->request('GET', '/cobrancas/acordos/previa-parcelamento', [
            'total' => 100000, 'entrada' => 0, 'qtd' => 2, 'data1' => '2026-02-31', 'periodicidade' => 'mensal',
        ]);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($dados['ok']);
    }

    #[TestDox('Prévia de parcelamento sem capacidade: 403')]
    public function testPreviaParcelamentoSemCapacidade(): void
    {
        $client = static::createClient();
        $this->criarOperadorSemCapacidade($client);

        $client->request('GET', '/cobrancas/acordos/previa-parcelamento', [
            'total' => 100000, 'entrada' => 0, 'qtd' => 2, 'data1' => '2026-09-01', 'periodicidade' => 'mensal',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Romper acordo ativo: happy path muda o status')]
    public function testRomperHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'romper_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/romper', [
            'romper_acordo' => ['motivo' => 'Parcelas em atraso', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        self::assertSame(StatusAcordo::Rompido, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('Romper acordo não ativo: erro de domínio, status inalterado')]
    public function testRomperNaoAtivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Cancelado])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'romper_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/romper', [
            'romper_acordo' => ['motivo' => 'X', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        self::assertSame(StatusAcordo::Cancelado, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('IDOR: romper acordo de outro tenant → 404')]
    public function testRomperCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio])->_real();

        $client->request('POST', '/cobrancas/acordos/' . $acordoAlheio->getId() . '/romper', [
            'romper_acordo' => ['motivo' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Cancelar acordo ativo: some da tela e a rota dá 404, mas a linha sobrevive no banco')]
    public function testCancelarHappy(): void
    {
        // Decisão do dono (01/08): "cancelado perde tudo do acordo, não tem nem como abrir um acordo
        // cancelado". Para o gestor o acordo deixou de existir. A LINHA, porém, fica — é ela que dá ao
        // importador memória de que aquele número foi cancelado; sem ela a próxima importação criaria um
        // acordo novo ATIVO e a mesma dívida passaria a contar duas vezes.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'cancelar_acordo');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'Erro de lançamento', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        self::assertSame(
            StatusAcordo::Cancelado,
            $this->em()->find(Acordo::class, $acordoId)?->getStatus(),
            'A linha sobrevive — é a memória de que este número foi cancelado.',
        );

        // "Não tem nem como abrir": a rota do acordo cancelado responde 404.
        $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Acordo cancelado some da tela do objeto — nem como grupo, nem em "Acordos encerrados"')]
    public function testAcordoCanceladoNaoApareceNaTelaDoObjeto(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'acordoOrigem' => $acordo,
            'valorOriginal' => 23_66,
            'descricao' => 'Parcela 1/30 do acordo cancelado',
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'cancelar_acordo');
        // Antes de cancelar, o acordo aparece: é o que garante que o assert de ausência abaixo mede algo.
        self::assertStringContainsString('Acordo #' . $acordoId, $crawler->filter('body')->text());

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'Não consta na contabilidade', '_token' => $token],
        ]);
        $this->em()->clear();

        $depois = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $divida = $depois->filter('#secao-divida')->text();
        self::assertStringNotContainsString('Acordo #' . $acordoId, $divida, 'o acordo cancelado não vira grupo');
        self::assertStringNotContainsString('Parcela 1/30 do acordo cancelado', $divida, 'nem as parcelas dele');

        // Nem em "Acordos encerrados" — lá ficam os ROMPIDOS, que aconteceram de verdade.
        self::assertCount(0, $depois->filter('#secao-acordos-encerrados a[href="/cobrancas/acordos/' . $acordoId . '"]'));
        // E link nenhum para ele em lugar nenhum da página: a rota dá 404.
        self::assertCount(0, $depois->filter('a[href="/cobrancas/acordos/' . $acordoId . '"]'));

        // O que SOBRA é a linha do histórico — autocontida, porque não há mais acordo para abrir.
        self::assertStringContainsString(
            'Acordo #' . $acordoId . ' de 1 parcela(s) foi cancelado',
            $depois->filter('body')->text(),
        );
    }

    #[TestDox('Cancelar acordo sem capacidade: negado, status inalterado')]
    public function testCancelarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/', (string) $client->getResponse()->headers->get('Location'));
        $this->em()->clear();
        self::assertSame(StatusAcordo::Ativo, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('IDOR: cancelar acordo de outro tenant → 404')]
    public function testCancelarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $acordoAlheio = AcordoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio, 'status' => StatusAcordo::Ativo])->_real();

        $client->request('POST', '/cobrancas/acordos/' . $acordoAlheio->getId() . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Cancelar acordo com CSRF inválido: status inalterado')]
    public function testCancelarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cancelar', [
            'cancelar_acordo' => ['motivo' => 'X', '_token' => 'falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        self::assertSame(StatusAcordo::Ativo, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('Cumprir acordo: happy path (CSRF manual do modal do detalhe do acordo)')]
    public function testCumprirHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        // Ajuste 10: gerir o acordo (cumprir/romper/cancelar) é do detalhe do acordo — a página do objeto
        // mostra o acordo e leva até ele, em vez de repetir as ações num modal inline por linha.
        $crawler = $client->request('GET', '/cobrancas/acordos/' . $acordoId);
        $token = (string) $crawler->filter('#modalCumprirAcordoDetalhe input[name="_token"]')->attr('value');

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cumprir', ['_token' => $token]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        self::assertSame(StatusAcordo::Cumprido, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('Cumprir acordo com CSRF inválido: não muda o status')]
    public function testCumprirCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();

        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/cumprir', ['_token' => 'falso']);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $this->em()->clear();
        self::assertSame(StatusAcordo::Ativo, $this->em()->find(Acordo::class, $acordoId)->getStatus());
    }

    #[TestDox('B5: romper acordo sem motivo reabre o modal reutilizavel com a URL da acao e o erro')]
    public function testRomperAcordoInvalidoReabreModalComAcaoEErro(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => StatusAcordo::Ativo])->_real();
        $acordoId = (int) $acordo->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'romper_acordo');

        // Motivo vazio — NotBlank falha.
        $client->request('POST', '/cobrancas/acordos/' . $acordoId . '/romper', [
            'romper_acordo' => ['motivo' => '', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-divida');
        $crawler = $client->followRedirect();

        $marcador = $crawler->filter('[data-modal-erro]');
        self::assertSame('modalRomperAcordo', $marcador->attr('data-modal-erro'));
        self::assertSame('/cobrancas/acordos/' . $acordoId . '/romper', $marcador->attr('data-modal-erro-acao'));
        self::assertStringContainsString('Informe o motivo do rompimento.', $crawler->filter('#modalRomperAcordo')->html());
        $this->em()->clear();
        self::assertSame(StatusAcordo::Ativo, $this->em()->find(Acordo::class, $acordoId)->getStatus(), 'a recusa não rompe o acordo');
    }
}
