<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObrigacaoController;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
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
                // Taxa por-obrigação (spec taxa-por-obrigacao): os quatro encargos deixaram de ser um
                // VALOR digitado — as taxas ficam OMITIDAS de propósito (herdam a taxa neutra do caso,
                // 0 bp), provando que a edição de cadastro não exige mexer em taxa nenhuma.
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
        self::assertSame('2026-09-01', $fresh->getVencimentoOriginal()->format('Y-m-d'));
        // Nenhuma taxa foi submetida (herda): a carteira do semearGrafo é neutra (0 bp em tudo), então
        // o motor recompõe os quatro encargos como 0 — prova que "herda" não inventa taxa nenhuma.
        self::assertSame(0, $fresh->getJuros());
        self::assertSame(0, $fresh->getMulta());
        self::assertSame(0, $fresh->getCorrecao());
        self::assertSame(0, $fresh->getHonorarios());
        self::assertSame(0, $fresh->getEncargosReconhecidos(), 'o agregado é DERIVADO (INV-E1), continua 0');
        self::assertSame(120000, $fresh->valorExigivel(), 'exigível = só o original, sem taxa própria');
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

    #[TestDox('Editar obrigação: override de taxa separa o split e recompõe os honorários pelo motor, SEM congelar')]
    public function testEditarObrigacaoRecomporEncargosSeparaSplitSemCongelar(): void
    {
        // Regressão do achado I5 da F2 (a ponte deprecada achatava o agregado em `juros` e zerava
        // multa/correção): no modelo de taxa por-obrigação (spec taxa-por-obrigacao) cada override vai
        // para o SEU campo (jurosBp/multaBp/correcaoBp), nunca achatado — e os honorários são SEMPRE
        // RECOMPOSTOS pelo motor sobre a base nova (a UI não os edita direto). Este teste prova que
        // editar SUBSTITUI por completo um cache "legado" (split gravado direto, simulando dado
        // anterior à feature), com cada componente indo para o campo certo.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Honorários acrescidos à dívida (20% sobre a base composta) — sem isso a taxa de honorários
        // fica em 0 e o recálculo não prova nada. T1 (cascata ao vivo sem snapshot): a fonte AO VIVO
        // é a CARTEIRA (via objeto) — o snapshot do caso virou coluna-sombra morta, não é mais lido
        // pelo resolvedor. Juros fica de fora do override de propósito (herda 0 bp da carteira neutra
        // do semearGrafo): assim o resultado é determinístico, independente de quantos dias se
        // passaram entre o vencimento (fixo, no passado) e "hoje" (relógio real).
        $caso->getObjeto()->getCarteira()
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('20.00');

        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 100000, 'encargosReconhecidos' => 0,
        ])->_real();
        // Split de partida (com honorários velhos), gravado direto: a factory só conhece o agregado. O
        // vencimento vai bem no passado para o atraso ultrapassar a carência de honorários.
        $obrigacao->definirEncargos(30000, 4000, 1000, 9000, new \DateTimeImmutable('2020-01-01'));
        $obrigacao->setVencimentoOriginal(new \DateTimeImmutable('2020-01-01'));
        $em->flush();
        $obrigacaoId = (int) $obrigacao->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'editar_obrigacao');

        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Recomposicao',
                'valorOriginal' => '1.000,00',
                'vencimentoOriginal' => '2020-01-01',
                'modoMulta' => 'percent',
                'multaBp' => '2,00',
                'modoCorrecao' => 'percent',
                'correcaoBp' => '1,50',
                'motivo' => 'juros lançados como multa',
                '_token' => $token,
            ],
        ]);

        $em->clear();
        $fresh = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame(0, $fresh->getJuros(), 'juros herdado da carteira neutra do semearGrafo (0 bp)');
        self::assertSame(2000, $fresh->getMulta(), 'multa = 2% de R$1.000,00, no SEU campo (não achatada com juros)');
        self::assertSame(1500, $fresh->getCorrecao(), 'correção = 1,5% de R$1.000,00, no SEU campo');
        // Honorários RECOMPOSTOS pelo motor: base composta 100000 + 0 (juros) + 2000 (multa) + 1500
        // (correção) = 103500 · 20% = 20700. Bem diferente do 9000 do cache "legado": prova que o
        // cache antigo foi SUBSTITUÍDO, não mesclado.
        self::assertSame(20700, $fresh->getHonorarios(), 'a UI não edita honorários — o motor os recompõe sobre a base nova');
        self::assertFalse($fresh->encargosCongelados(), 'ao vivo (D6): editar à mão NÃO congela — a obrigação segue Viva');
    }

    #[TestDox('Registrar obrigação com override de taxa: a taxa própria é gravada e aplicada pelo motor, SEM congelar (ao vivo)')]
    public function testRegistrarObrigacaoComOverrideDeTaxaNaoCongela(): void
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
                // Vencimento no passado (padrão do arquivo): sem atraso o motor sempre devolve 0
                // (`dias === 0`), e o override não teria nada a provar.
                'vencimentoOriginal' => '2020-01-01',
                'modoMulta' => 'percent',
                'multaBp' => '2,00',
                'modoCorrecao' => 'percent',
                'correcaoBp' => '0,50',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $criada = $em->getRepository(Obrigacao::class)->findOneBy(['descricao' => 'Divida vinda de outro sistema']);
        self::assertNotNull($criada);
        self::assertSame(200, $criada->getTaxaMultaBp(), 'a taxa própria (override) foi gravada, não herdada');
        self::assertSame(50, $criada->getTaxaCorrecaoBp(), 'a taxa própria (override) foi gravada, não herdada');
        self::assertSame(0, $criada->getJuros(), 'juros herdado da carteira neutra do semearGrafo (0 bp)');
        self::assertSame(2000, $criada->getMulta(), 'multa = 2% de R$1.000,00, aplicada pelo motor com a taxa própria');
        self::assertSame(500, $criada->getCorrecao(), 'correção = 0,5% de R$1.000,00');
        self::assertSame(102500, $criada->valorExigivel());
        // Ao vivo (D6): a carteira de teste é NEUTRA (0% honorários) e nenhum override de honorário foi
        // submetido, então os honorários dão 0; a completude pelo motor é provada no unit (carteira TOPLIFE).
        self::assertSame(0, $criada->getHonorarios());
        self::assertFalse(
            $criada->encargosCongelados(),
            'ao vivo: registrar com taxa própria não congela — a obrigação nasce Viva',
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

    #[TestDox('Registrar obrigação com override de honorário (%): a taxa é gravada e aplicada pelo motor, fica fora do exigível, SEM congelar')]
    public function testRegistrarObrigacaoComOverrideDeHonorarioNaoCongela(): void
    {
        // O honorário é o 4º encargo do modal. Com o modelo de taxa (spec taxa-por-obrigacao), não se
        // digita mais o VALOR — a UI grava a TAXA própria (%), aplicada pelo motor sobre a base
        // composta. Fica FORA do exigível (INV-E2). Ao vivo (D6), NÃO congela. Vencimento no passado
        // (padrão do arquivo) para o atraso ultrapassar a carência de honorários — sem isso o override
        // não teria nada a provar.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'registrar_obrigacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/obrigacoes', [
            'registrar_obrigacao' => [
                'descricao' => 'Obrigacao com honorario fixo',
                'valorOriginal' => '1.000,00',
                'vencimentoOriginal' => '2020-01-01',
                'modoHonorarios' => 'percent',
                'honorariosBp' => '6,00',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $criada = $em->getRepository(Obrigacao::class)->findOneBy(['descricao' => 'Obrigacao com honorario fixo']);
        self::assertNotNull($criada);
        self::assertSame(600, $criada->getTaxaHonorariosBp(), 'a taxa própria (override) foi gravada, não herdada');
        self::assertSame(6000, $criada->getHonorarios(), 'o motor aplica a taxa própria: 6% de R$1.000,00');
        // INV-E2 revogada (spec `cobranca-honorario-no-total.md`): o honorário entra no que se cobra.
        self::assertSame(106000, $criada->valorExigivel(), 'exigível = 1.000,00 + 60,00 de honorário');
        self::assertFalse($criada->encargosCongelados(), 'ao vivo: override de honorário não congela — nasce Viva');
    }

    #[TestDox('Editar obrigação com override de honorário (%): a taxa é gravada e aplicada pelo motor, fica fora do exigível, SEM congelar')]
    public function testEditarObrigacaoComOverrideDeHonorarioNaoCongela(): void
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

        // SÓ o override de honorário é submetido (juros/multa/correção ficam 'herda' — a carteira do
        // semearGrafo é neutra, dão 0). Vencimento no passado (padrão do arquivo) para o atraso
        // ultrapassar a carência de honorários — sem isso o override não teria nada a provar.
        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Honorario ajustado',
                'valorOriginal' => '1.000,00',
                'vencimentoOriginal' => '2020-01-01',
                'modoHonorarios' => 'percent',
                'honorariosBp' => '7,50',
                'motivo' => 'fixar a taxa de honorário da obrigação',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '#secao-divida');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Obrigacao::class, $obrigacaoId);
        self::assertSame(750, $fresh->getTaxaHonorariosBp(), 'o override de honorário é gravado');
        self::assertSame(7500, $fresh->getHonorarios(), 'o motor aplica a taxa: 7,5% de R$1.000,00');
        // INV-E2 revogada (spec `cobranca-honorario-no-total.md`): o honorário entra no que se cobra.
        self::assertSame(107500, $fresh->valorExigivel(), 'exigível = 1.000,00 + 75,00 de honorário');
        self::assertFalse($fresh->encargosCongelados(), 'ao vivo: override de honorário não congela — segue Viva');
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

        // Corrige a descrição mas esquece o motivo (NotBlank) — validação de campo falha. As taxas
        // usam os DOIS modos de propósito (percent + reais), para provar que o B5 reidrata qualquer um.
        $client->request('POST', '/cobrancas/obrigacoes/' . $obrigacaoId . '/editar', [
            'editar_obrigacao' => [
                'descricao' => 'Descrição corrigida XYZ',
                'valorOriginal' => '1.200,00',
                'vencimentoOriginal' => '2026-09-01',
                'modoJuros' => 'percent',
                'jurosBp' => '1,20',
                'modoMulta' => 'percent',
                'multaBp' => '0,30',
                'modoCorrecao' => 'reais',
                'correcaoReais' => '7,50',
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

        // Taxa por-obrigação (spec taxa-por-obrigacao): o trio modo/%/R$ de cada encargo é campo do
        // Form como qualquer outro, então o B5 os reidrata igual — o gestor não redigita a taxa por
        // causa de um motivo esquecido.
        self::assertSame(
            '1,20',
            $crawler->filter('#modalEditarObrigacao input[name="editar_obrigacao[jurosBp]"]')->attr('value'),
            'a taxa de juros digitada (%) sobrevive ao redirect',
        );
        self::assertSame(
            '0,30',
            $crawler->filter('#modalEditarObrigacao input[name="editar_obrigacao[multaBp]"]')->attr('value'),
            'a taxa de multa digitada (%) sobrevive ao redirect',
        );
        // A correção entra pelo campo R$ (modo 'reais') de propósito: com '0,00' o teste passaria mesmo
        // se o campo não reidratasse nada (zero é o default), e uma regressão que perdesse só a
        // correção seguiria verde.
        self::assertSame(
            '7,50',
            $crawler->filter('#modalEditarObrigacao input[name="editar_obrigacao[correcaoReais]"]')->attr('value'),
            'o valor de correção digitado (R$) sobrevive ao redirect',
        );
    }
}
