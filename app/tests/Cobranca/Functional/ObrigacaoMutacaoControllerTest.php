<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObrigacaoController;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Mutações de Obrigações (Onda 8B): registrar obrigação e reconhecer valor. Cobre gate de módulo +
 * capacidade (`resources.cobranca.gerenciar`), CSRF, anti-IDOR cross-tenant (404), erro de domínio
 * (caso encerrado) e o happy path com persistência.
 */
#[CoversClass(ObrigacaoController::class)]
final class ObrigacaoMutacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Registrar obrigação: happy path persiste e volta ao caso')]
    public function testRegistrarObrigacaoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => [
                'descricao' => 'Boleto marcador ABC',
                'valorOriginal' => '1.500,00',
                'vencimentoOriginal' => '2026-08-10',
                'referenciaExterna' => 'REF-1',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $client->followRedirect();
        self::assertStringContainsString('Boleto marcador ABC', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Registrar obrigação em caso ENCERRADO: erro de domínio, não persiste')]
    public function testRegistrarObrigacaoCasoEncerrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Caso ativo só para renderizar o form e obter o token; o alvo é um caso encerrado.
        [, $casoAtivo] = $this->semearGrafo($tenant);
        [, $casoEncerrado] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $casoAtivo->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');

        $client->request('POST', '/cobrancas/casos/' . $casoEncerrado->getId() . '/obrigacoes', [
            'registrar_obrigacao' => [
                'descricao' => 'NAO DEVE PERSISTIR XYZ',
                'valorOriginal' => '100,00',
                'vencimentoOriginal' => '2026-08-10',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $casoEncerrado->getObjeto()->getId() . '#secao-divida');
        $client->followRedirect();
        self::assertStringNotContainsString('NAO DEVE PERSISTIR XYZ', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Registrar obrigação sem a capacidade: negado (redirect, não caso)')]
    public function testRegistrarObrigacaoSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-08-10', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'), 'a capacidade nega antes do CSRF; deve ir para a homepage, não para o caso');
    }

    #[TestDox('IDOR: registrar obrigação em caso de OUTRO tenant devolve 404')]
    public function testRegistrarObrigacaoCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/obrigacoes', [
            'registrar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-08-10', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: registrar obrigação não persiste')]
    public function testRegistrarObrigacaoCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => ['descricao' => 'MARCADOR CSRF RUIM', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-08-10', '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');
        $crawler = $client->followRedirect();
        self::assertStringNotContainsString('MARCADOR CSRF RUIM', (string) $client->getResponse()->getContent());
        // B5 é para erro de VALIDAÇÃO; falha de CSRF (erro de raiz) é rejeição limpa — o modal NÃO reabre
        // ecoando o payload. Trava a fronteira entre "corrigir campo" e evento de segurança.
        self::assertCount(0, $crawler->filter('[data-modal-erro]'), 'CSRF inválido não reabre o modal com o digitado');
    }

    #[TestDox('Editar obrigação: happy path corrige os campos e volta ao objeto')]
    public function testEditarObrigacaoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
        ])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Boleto corrigido ABC',
                'valorOriginal' => '1.200,00',
                'vencimentoOriginal' => '2026-09-01',
                'referenciaExterna' => 'REF-9',
                // F4: os encargos deixaram de ser um campo só. Valores DISTINTOS de propósito — se o
                // form achatasse tudo num componente (era o que a ponte deprecada fazia), três valores
                // iguais ou um agregado só não denunciariam nada.
                'juros' => '200,00',
                'multa' => '40,00',
                'correcao' => '10,00',
                'motivo' => 'Valor digitado errado na importação',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame('Boleto corrigido ABC', $fresh->getDescricao());
        self::assertSame(120000, $fresh->getValorOriginal(), 'valor original corrigido para R$1.200,00');
        self::assertSame(20000, $fresh->getJuros(), 'juros corrigidos para R$200,00');
        self::assertSame(4000, $fresh->getMulta(), 'multa corrigida para R$40,00');
        self::assertSame(1000, $fresh->getCorrecao(), 'correção corrigida para R$10,00');
        // O agregado é DERIVADO (INV-E1): continua valendo R$250,00, como no contrato antigo.
        self::assertSame(25000, $fresh->getEncargosReconhecidos(), 'o agregado é a soma dos três');
        self::assertSame(145000, $fresh->valorExigivel(), 'exigível = original + os três encargos');
        self::assertSame('2026-09-01', $fresh->getVencimentoOriginal()->format('Y-m-d'));
    }

    #[TestDox('Editar obrigação: os encargos separados NÃO são obrigatórios — o form sem eles é válido')]
    public function testEditarObrigacaoSemInformarEncargosEhValido(): void
    {
        // Nenhum campo novo pode ser obrigatório: um `NotBlank` neles quebraria todo POST que não os
        // manda (payload legado, chamador programático) DEPOIS do deploy, em produção.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
        ])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'So a descricao mudou',
                'valorOriginal' => '1.000,00',
                'vencimentoOriginal' => '2026-09-01',
                'motivo' => 'typo na descrição',
                '_token' => $token,
            ],
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertCount(0, $crawler->filter('[data-modal-erro]'), 'o form sem os encargos não pode falhar na validação');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame('So a descricao mudou', $fresh->getDescricao(), 'a edição foi aplicada');
        self::assertFalse($fresh->encargosCongelados(), 'não mexeu em encargos → não congela');
    }

    #[TestDox('Editar obrigação: trocar a composição dos encargos congela e preserva os honorários')]
    public function testEditarObrigacaoRecomporEncargosCongelaEPreservaHonorarios(): void
    {
        // Regressão do achado I5 da F2: a ponte deprecada `setEncargosReconhecidos()` jogava o agregado
        // inteiro em `juros` e ZERAVA multa/correção. Aqui o agregado é o MESMO antes e depois
        // (R$ 350,00) e só a composição muda — o único jeito de o teste ficar verde é cada componente
        // ter ido para o seu campo.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
        ])->_real();
        // O split de partida (com honorários materializados) não sai da factory: ela só conhece o
        // agregado. Grava-se aqui, antes do POST.
        $obrigacao->definirEncargos(30000, 4000, 1000, 9000, new \DateTimeImmutable('2026-02-01'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Recomposicao',
                'valorOriginal' => '1.000,00',
                'vencimentoOriginal' => '2026-09-01',
                // Agregado idêntico (35000), composição diferente.
                'juros' => '200,00',
                'multa' => '140,00',
                'correcao' => '10,00',
                'motivo' => 'juros lançados como multa',
                '_token' => $token,
            ],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame(20000, $fresh->getJuros());
        self::assertSame(14000, $fresh->getMulta());
        self::assertSame(1000, $fresh->getCorrecao());
        self::assertSame(9000, $fresh->getHonorarios(), 'a UI não edita honorários — eles não podem ser zerados por uma correção');
        self::assertTrue($fresh->encargosCongelados(), 'mexer em dinheiro à mão tira a obrigação do cron (INV-E4)');
    }

    #[TestDox('Registrar obrigação com encargos: nasce com o split e RECALCULÁVEL (não congela)')]
    public function testRegistrarObrigacaoComEncargosNaoCongela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => [
                'descricao' => 'Divida vinda de outro sistema',
                'valorOriginal' => '1.000,00',
                'vencimentoOriginal' => '2026-08-10',
                'juros' => '50,00',
                'multa' => '20,00',
                'correcao' => '5,00',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $criada = $em->getRepository(Obrigacao::class)->findOneBy(['descricao' => 'Divida vinda de outro sistema']);
        self::assertNotNull($criada);
        self::assertSame(5000, $criada->getJuros());
        self::assertSame(2000, $criada->getMulta());
        self::assertSame(500, $criada->getCorrecao());
        self::assertSame(107500, $criada->valorExigivel());
        // Honorários não são digitados neste form; congelar no lançamento os deixaria em zero PARA
        // SEMPRE (a obrigação sairia do cron e não há UI de descongelar). Quem quiser travar, edita
        // depois — a edição congela. O digitado não corre risco de encolher: o cron só grava para cima.
        self::assertSame(0, $criada->getHonorarios());
        self::assertFalse(
            $criada->encargosCongelados(),
            'lançar não é editar: a obrigação segue recalculável para os honorários serem materializados',
        );
    }

    #[TestDox('Registrar obrigação sem encargos: nasce zerada e NÃO congelada (o cron cuida)')]
    public function testRegistrarObrigacaoSemEncargosNaoCongela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => [
                'descricao' => 'Boleto novo sem encargos',
                'valorOriginal' => '1.000,00',
                'vencimentoOriginal' => '2026-08-10',
                '_token' => $token,
            ],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $criada = $em->getRepository(Obrigacao::class)->findOneBy(['descricao' => 'Boleto novo sem encargos']);
        self::assertNotNull($criada, 'os encargos são opcionais: o lançamento sem eles continua válido');
        self::assertSame(0, $criada->getEncargosReconhecidos());
        self::assertFalse(
            $criada->encargosCongelados(),
            'congelar aqui tiraria do cron toda obrigação criada à mão, sem UI de descongelar',
        );
    }

    #[TestDox('Editar obrigação sem a capacidade: negado (redirect, não caso)')]
    public function testEditarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacao->getId() . '/editar', [
            'editar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-09-01', 'motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: editar obrigação de OUTRO tenant devolve 404')]
    public function testEditarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $obrigacaoAlheia = ObrigacaoFactory::createOne([
            'tenant' => $casoAlheio->getTenant(),
            'caso' => $casoAlheio,
        ])->_real();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoAlheia->getId() . '/editar', [
            'editar_obrigacao' => ['descricao' => 'X', 'valorOriginal' => '10,00', 'vencimentoOriginal' => '2026-09-01', 'motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: editar obrigação não altera')]
    public function testEditarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'descricao' => 'Original XYZ'])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => ['descricao' => 'NAO DEVE MUDAR', 'valorOriginal' => '1,00', 'vencimentoOriginal' => '2026-09-01', 'motivo' => 'x', '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('Original XYZ', $em->find(Obrigacao::class, $obrigacaoId)->getDescricao(), 'CSRF inválido não altera a obrigação');
    }

    #[TestDox('Excluir obrigação: happy path remove a linha e volta ao objeto')]
    public function testExcluirObrigacaoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        // O token CSRF (manual, por obrigação) vem do botão excluir da linha.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $crawler->filter('button[data-acao-url="/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir"]')->attr('data-token');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => 'Lançada em duplicidade',
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Obrigacao::class, $obrigacaoId), 'a obrigação foi removida');
    }

    #[TestDox('Excluir sem a capacidade: negado (redirect, não caso), obrigação intacta')]
    public function testExcluirSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => 'x', '_token' => 'irrelevante',
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/', (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Obrigacao::class, $obrigacaoId), 'sem capacidade não exclui');
    }

    #[TestDox('IDOR: excluir obrigação de OUTRO tenant devolve 404')]
    public function testExcluirCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());
        $obrigacaoAlheia = ObrigacaoFactory::createOne(['tenant' => $casoAlheio->getTenant(), 'caso' => $casoAlheio])->_real();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoAlheia->getId() . '/excluir', [
            'motivo' => 'x', '_token' => 'irrelevante',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: excluir obrigação não remove')]
    public function testExcluirCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => 'x', '_token' => 'token-falso',
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Obrigacao::class, $obrigacaoId), 'CSRF inválido não remove a obrigação');
    }

    #[TestDox('Excluir obrigação com pagamento alocado: bloqueado no servidor, não remove')]
    public function testExcluirComPagamentoAlocadoBloqueia(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0])->_real();
        $obrigacaoId = (int) $obrigacao->getId();
        // Pagamento com alocação nesta obrigação → guard de exclusão barra.
        $pagamento = PagamentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorDivida' => 10000, 'valorHonorarios' => 0])->_real();
        AlocacaoPagamentoFactory::createOne(['tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => 10000]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $crawler->filter('button[data-acao-url="/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir"]')->attr('data-token');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => 'tentando excluir', '_token' => $token,
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Obrigacao::class, $obrigacaoId), 'obrigação com pagamento alocado não é excluída');
    }

    #[TestDox('Excluir obrigação sem motivo: rejeitado, não remove')]
    public function testExcluirSemMotivo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $crawler->filter('button[data-acao-url="/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir"]')->attr('data-token');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/excluir', [
            'motivo' => '   ', '_token' => $token,
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Obrigacao::class, $obrigacaoId), 'sem motivo não exclui');
    }

    #[TestDox('B5: obrigação inválida reabre o modal com o erro e preserva o que o usuário digitou')]
    public function testRegistrarObrigacaoInvalidaReabreModalComErroEPreservaODigitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');

        // O gestor digita a descrição mas esquece o valor — a validação falha.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => [
                'descricao' => 'Cota condominial de teste',
                'valorOriginal' => '',
                'vencimentoOriginal' => '2026-09-10',
                'referenciaExterna' => '',
                '_token' => $token,
            ],
        ]);

        // PRG preservado: erro não vira 200 na própria action (F5 não re-posta).
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-divida');
        $crawler = $client->followRedirect();

        // 1) A página sinaliza QUAL modal reabrir (o load dá o show via Bootstrap).
        self::assertSame(
            'modalRegistrarObrigacao',
            $crawler->filter('[data-modal-erro]')->attr('data-modal-erro'),
            'após o erro a página deve reabrir o modal certo',
        );
        // 2) O erro aparece DENTRO do modal, não como flash solto que apaga o contexto.
        self::assertStringContainsString(
            'Informe o valor da obrigação.',
            $crawler->filter('#modalRegistrarObrigacao')->html(),
            'o gestor precisa ver o erro no campo',
        );
        // 3) O que ele digitou sobrevive ao redirect.
        self::assertSame(
            'Cota condominial de teste',
            $crawler->filter('#modalRegistrarObrigacao input[name="registrar_obrigacao[descricao]"]')->attr('value'),
            'o digitado não pode ser apagado',
        );

        // 4) One-shot: a visita seguinte volta limpa, sem reabrir nem repetir o valor.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        self::assertCount(0, $crawler->filter('[data-modal-erro]'), 'o estado de erro é consumido na leitura (one-shot)');
        self::assertSame(
            '',
            (string) $crawler->filter('#modalRegistrarObrigacao input[name="registrar_obrigacao[descricao]"]')->attr('value'),
            'na visita seguinte o modal volta vazio',
        );
    }

    #[TestDox('B5: editar obrigação inválida reabre o modal reutilizável com a URL da ação e o digitado')]
    public function testEditarObrigacaoInvalidaReabreModalComAcaoEPreservaODigitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $obrigacao = ObrigacaoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0])->_real();
        $obrigacaoId = (int) $obrigacao->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        // Corrige a descrição mas esquece o motivo (NotBlank) — validação de campo falha.
        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Descrição corrigida XYZ',
                'valorOriginal' => '1.200,00',
                'vencimentoOriginal' => '2026-09-01',
                'juros' => '12,00',
                'multa' => '3,00',
                'correcao' => '7,50',
                'motivo' => '',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '#secao-divida');
        $crawler = $client->followRedirect();

        $marcador = $crawler->filter('[data-modal-erro]');
        self::assertSame('modalEditarObrigacao', $marcador->attr('data-modal-erro'));
        // O NOVO nos reutilizáveis: a URL da ação daquela obrigação é reposta (o JS a aplica ao form).
        self::assertSame('/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', $marcador->attr('data-modal-erro-acao'));
        $modalHtml = $crawler->filter('#modalEditarObrigacao')->html();
        self::assertStringContainsString('Informe o motivo da correção.', $modalHtml);
        self::assertStringContainsString('Descrição corrigida XYZ', $modalHtml, 'o digitado tem de sobreviver ao redirect');

        // F4: os encargos separados são campos do Form, então o B5 os reidrata como qualquer outro —
        // o gestor não redigita dinheiro por causa de um motivo esquecido. (O "%" ao lado não precisa
        // reidratar: não é submetido, o JS o deriva destes valores quando o modal reabre.)
        self::assertSame(
            '12,00',
            $crawler->filter('#modalEditarObrigacao input[name="editar_obrigacao[juros]"]')->attr('value'),
            'os juros digitados sobrevivem ao redirect',
        );
        self::assertSame(
            '3,00',
            $crawler->filter('#modalEditarObrigacao input[name="editar_obrigacao[multa]"]')->attr('value'),
            'a multa digitada sobrevive ao redirect',
        );
        // A correção entra com valor DIFERENTE de zero de propósito: com '0,00' o teste passaria mesmo
        // se o campo não reidratasse nada (zero é o default), e uma regressão que perdesse só a
        // correção seguiria verde.
        self::assertSame(
            '7,50',
            $crawler->filter('#modalEditarObrigacao input[name="editar_obrigacao[correcao]"]')->attr('value'),
            'a correção digitada sobrevive ao redirect',
        );
    }
}
