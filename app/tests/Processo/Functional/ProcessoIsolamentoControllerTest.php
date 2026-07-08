<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Processo\Controller\ProcessoController;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Enum\MotivoFalhaDatajud;
use App\Processo\Exception\ConsultaDatajudException;
use App\Processo\Service\DatajudClient;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Isolamento multi-tenant do ProcessoController via HTTP. Gestores isSystem (que bypassam o
 * PermissionChecker) provam que é o TenantFilter na camada de dados — não a permissão — que
 * fecha o vazamento. em->clear() após os fixtures força o find()/ParamConverter a executar SQL
 * real (em produção a identity map começa vazia por request).
 */
#[CoversClass(ProcessoController::class)]
final class ProcessoIsolamentoControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('Dono acessa o próprio processo; gestor de outro tenant recebe 404 (show)')]
    public function testShowIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'gestorB_' . uniqid() . '@test.com');
        $procB = $this->criarProcesso($tenantB);
        $id = (int) $procB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorB, $tenantB);
        $client->request('GET', "/processos/{$id}");
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', "/processos/{$id}");
        self::assertResponseStatusCodeSame(404, 'show não pode revelar processo de outro tenant');
    }

    #[TestDox('B8: POST /processos/api/search sem o módulo de processos retorna 403 (anti-abuso da API externa)')]
    public function testDatajudSearchExigeModulo(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuarioComum($tenant);
        $this->limparIdentityMap();

        $this->logarComTenant($client, $usuario, $tenant);
        $client->request(
            'POST',
            '/processos/api/search',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['numeroProcesso' => '00010203020255500']),
        );

        self::assertResponseStatusCodeSame(403, 'sem o módulo de processos não pode disparar a API externa');
    }

    #[TestDox('Busca no CNJ deriva a sigla do número e devolve os dados (happy path)')]
    public function testDatajudSearchDerivaSiglaDoNumero(): void
    {
        $client  = static::createClient();
        $client->disableReboot(); // preserva o container (e o mock) durante a requisição
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant, 'gestorSearch_' . uniqid() . '@test.com');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $gestor, $tenant);

        // Mocka o client (padrão do domínio): evita chamada real ao CNJ. A sigla, porém,
        // é derivada pelo controller a partir do número (resolver real) — é o que asseguramos.
        $mock = $this->createMock(DatajudClient::class);
        $mock->method('searchByNumeroProcesso')->willReturn([
            'hits' => ['hits' => [
                ['_source' => ['numeroProcesso' => '00012345620208260100', 'tribunal' => 'TJSP']],
            ]],
        ]);
        static::getContainer()->set(DatajudClient::class, $mock);

        $client->request(
            'POST',
            '/processos/api/search',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            // TJSP (J=8, TR=26), mascarado.
            (string) json_encode(['numeroProcesso' => '0001234-56.2020.8.26.0100']),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($payload['success'] ?? false);
        self::assertSame('TJSP', $payload['data']['siglaTribunal'] ?? null, 'a sigla vem derivada do número');
    }

    #[TestDox('Número fora do padrão CNJ retorna 422 amigável (client real, sem tocar a rede)')]
    public function testDatajudSearchNumeroInvalidoRetorna422(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant, 'gestor422_' . uniqid() . '@test.com');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $gestor, $tenant);

        // Client REAL: o resolver lança para um número não-CNJ ANTES de qualquer chamada HTTP,
        // então nenhuma requisição externa acontece — o controller devolve 422.
        $client->request(
            'POST',
            '/processos/api/search',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['numeroProcesso' => '123-nao-e-cnj']),
        );

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $payload);
        // Mensagem explicativa: diz quantos dígitos vieram vs os 20 do padrão CNJ.
        self::assertStringContainsString('dígito', $payload['error']);
        self::assertStringContainsString('20', $payload['error']);
    }

    #[TestDox('Processo não encontrado no CNJ cita o tribunal detectado (TJSP) e devolve 404')]
    public function testDatajudSearchNaoEncontradoCitaTribunal(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'gestorNF_' . uniqid() . '@test.com');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $gestor, $tenant);

        $mock = $this->createMock(DatajudClient::class);
        $mock->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => []]]);
        static::getContainer()->set(DatajudClient::class, $mock);

        $client->request(
            'POST',
            '/processos/api/search',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['numeroProcesso' => '0001234-56.2020.8.26.0100']), // TJSP
        );

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('TJSP', $payload['error'], 'a mensagem cita o tribunal detectado');
    }

    #[TestDox('CNJ instável usa a mensagem e o status do motivo Indisponivel (502)')]
    public function testDatajudSearchCnjInstavelUsaMensagemDoMotivo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'gestorInst_' . uniqid() . '@test.com');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $gestor, $tenant);

        $mock = $this->createMock(DatajudClient::class);
        $mock->method('searchByNumeroProcesso')
            ->willThrowException(new ConsultaDatajudException(MotivoFalhaDatajud::Indisponivel, 'HTTP 503'));
        static::getContainer()->set(DatajudClient::class, $mock);

        $client->request(
            'POST',
            '/processos/api/search',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['numeroProcesso' => '0001234-56.2020.8.26.0100']),
        );

        self::assertResponseStatusCodeSame(502);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('instável', $payload['error']);
    }

    #[TestDox('Tribunal sem base pública no CNJ avisa citando a sigla e devolve 422')]
    public function testDatajudSearchTribunalSemBasePublica(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'gestorSB_' . uniqid() . '@test.com');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $gestor, $tenant);

        $mock = $this->createMock(DatajudClient::class);
        $mock->method('searchByNumeroProcesso')
            ->willThrowException(new ConsultaDatajudException(MotivoFalhaDatajud::TribunalSemBasePublica, 'HTTP 404'));
        static::getContainer()->set(DatajudClient::class, $mock);

        $client->request(
            'POST',
            '/processos/api/search',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['numeroProcesso' => '0001234-56.2020.9.26.0100']), // TJMSP (militar estadual)
        );

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('TJMSP', $payload['error']);
        self::assertStringContainsString('consulta pública', $payload['error']);
    }

    #[TestDox('Form de novo processo renderiza o Tribunal como somente-leitura (sem select nem tribunalAlias)')]
    public function testFormularioNovoTribunalReadonly(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'gestorForm_' . uniqid() . '@test.com');
        $this->limparIdentityMap();
        $this->logarComTenant($client, $gestor, $tenant);

        $client->request('GET', '/processos/novo');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/<input[^>]*id="siglaTribunal"[^>]*readonly/', $html, 'o campo Tribunal deve ser um input read-only');
        self::assertStringNotContainsString('select2-tribunal', $html, 'o antigo select de tribunal foi removido');
        self::assertStringNotContainsString('tribunalAlias', $html, 'o JS não envia mais a sigla do tribunal');
    }

    #[TestDox('Editar/deletar processo de outro tenant retorna 404')]
    public function testEditarEDeletarIsolamPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $procB = $this->criarProcesso($tenantB);
        $id = (int) $procB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);

        $client->request('GET', "/processos/{$id}/editar");
        self::assertResponseStatusCodeSame(404, 'editar não pode revelar processo de outro tenant');

        $client->request('POST', "/processos/{$id}/deletar");
        self::assertResponseStatusCodeSame(404, 'deletar não pode tocar processo de outro tenant');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(Processo::class, $id));
    }

    #[TestDox('A listagem (index) não mostra processos de outro tenant')]
    public function testIndexNaoVazaOutroTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $procA = $this->criarProcesso($tenantA);
        $procB = $this->criarProcesso($tenantB);
        $numeroA = $procA->getNumeroProcesso();
        $numeroB = $procB->getNumeroProcesso();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', '/processos/');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($numeroA, $body, 'index deveria listar o processo do próprio tenant');
        self::assertStringNotContainsString($numeroB, $body, 'index vazou processo de outro tenant');
    }

    #[TestDox('O índice renderiza a barra de filtro reutilizável')]
    public function testIndexExibeBarraDeFiltro(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('GET', '/processos/');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-filtro-root', $body);
        self::assertStringContainsString('js-filtro-busca', $body);
    }

    #[TestDox('XHR em /processos/ devolve só o fragmento de resultado, sem o layout')]
    public function testXhrRetornaFragmentoSemLayout(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');
        $proc    = $this->criarProcesso($tenant);
        $numero  = $proc->getNumeroProcesso();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $client->xmlHttpRequest('GET', '/processos/');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $numero, $body);
        self::assertStringContainsString('resultado(s) encontrado', $body);
        self::assertStringNotContainsString('<!DOCTYPE', $body);
        self::assertStringNotContainsString('data-filtro-root', $body);
    }

    #[TestDox('XHR com busca sem correspondência devolve fragmento vazio')]
    public function testXhrBuscaSemResultado(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');
        $this->criarProcesso($tenant);
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $client->xmlHttpRequest('GET', '/processos/', ['busca' => 'zzz-inexistente-zzz']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhum processo encontrado', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Criar processo pela web persiste os metadados do Datajud, com nivelSigilo=0 (público) preservado — não vira null')]
    public function testCriarPersisteMetadadosDatajudComSigiloZero(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);

        $numero    = '5550000' . (++$this->seq) . '20238260100';
        $datajudId = 'TJSP_1689_G1_80064_' . $numero;

        $client->request('POST', '/processos/novo', [
            'numeroProcesso'    => $numero,
            'siglaTribunal'     => 'TJSP',
            'orgaoJulgador'     => '1a Vara Cível',
            'classeProcessual'  => 'Procedimento Comum',
            'assuntoProcessual' => 'Indenização',
            'situacaoProcesso'  => 'EM_ANDAMENTO',
            'instancia'         => 'G1',
            // Metadados do Datajud (hidden preenchidos pela busca no CNJ):
            'nivelSigilo'                => '0', // público — NÃO pode virar null
            'formato'                    => 'Eletrônico',
            'formatoCodigo'              => '1',
            'sistema'                    => 'SAJ',
            'sistemaCodigo'              => '3',
            'classeCodigo'               => '1689',
            'orgaoJulgadorCodigo'        => '80064',
            'orgaoJulgadorMunicipioIbge' => '2704302',
            'datajudId'                  => $datajudId,
        ]);

        self::assertResponseRedirects();

        // Filtro de tenant OFF para o find, como nos demais testes deste arquivo.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        $processo = $em->getRepository(Processo::class)->findOneBy(['numeroProcesso' => $numero]);
        self::assertNotNull($processo, 'o processo deveria ter sido criado');

        self::assertSame(0, $processo->getNivelSigilo(), 'nivelSigilo=0 (público) não pode virar null no caminho web');
        self::assertSame('Público', $processo->getNivelSigiloLabel());
        self::assertSame('Eletrônico', $processo->getFormato());
        self::assertSame(1, $processo->getFormatoCodigo());
        self::assertSame('SAJ', $processo->getSistema());
        self::assertSame(3, $processo->getSistemaCodigo());
        self::assertSame(1689, $processo->getClasseCodigo());
        self::assertSame('80064', $processo->getOrgaoJulgadorCodigo());
        self::assertSame(2704302, $processo->getOrgaoJulgadorMunicipioIbge());
        self::assertSame($datajudId, $processo->getDatajudId());
    }

    #[TestDox('Criar e editar processo pela web adiciona/reconcilia a coleção de assuntos (linhas do formulário)')]
    public function testCriarEEditarPersisteColecaoDeAssuntos(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);

        $numero = '5551111' . (++$this->seq) . '20238260100';

        // --- Criar com 2 assuntos ---
        $client->request('POST', '/processos/novo', [
            'numeroProcesso'    => $numero,
            'siglaTribunal'     => 'TJSP',
            'orgaoJulgador'     => '1a Vara',
            'classeProcessual'  => 'Procedimento Comum',
            'assuntoProcessual' => 'Indenização',
            'situacaoProcesso'  => 'EM_ANDAMENTO',
            'instancia'         => 'G1',
            'assuntos'          => [
                ['nome' => 'Dano Moral', 'codigo' => '10433'],
                ['nome' => 'Obrigações', 'codigo' => '7681'],
            ],
        ]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        $processo = $em->getRepository(Processo::class)->findOneBy(['numeroProcesso' => $numero]);
        self::assertNotNull($processo);
        self::assertCount(2, $processo->getAssuntos(), 'os 2 assuntos do formulário devem persistir');

        // Código TPU persistido + tenant correto (isolamento) para cada assunto criado pelo form.
        $porNome = [];
        foreach ($processo->getAssuntos() as $a) {
            $porNome[$a->getNome()] = $a;
            self::assertSame(
                (int) $tenant->getId(),
                (int) $a->getTenant()->getId(),
                'assunto criado pelo formulário deve nascer no tenant do usuário logado'
            );
        }
        self::assertSame(10433, $porNome['DANO MORAL']->getCodigo(), 'código TPU deve persistir');
        self::assertSame(7681, $porNome['OBRIGAÇÕES']->getCodigo());

        // Guarda o id de "Dano Moral" para mantê-lo na edição (reconciliação por id).
        $idDanoMoral = $porNome['DANO MORAL']->getId();
        self::assertNotNull($idDanoMoral);
        $processoId = (int) $processo->getId();
        $this->limparIdentityMap();

        // --- Editar: mantém "Dano Moral" (por id), remove "Obrigações", adiciona "Verbas Rescisórias" ---
        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('POST', "/processos/{$processoId}/editar", [
            'numeroProcesso'    => $numero,
            'siglaTribunal'     => 'TJSP',
            'orgaoJulgador'     => '1a Vara',
            'classeProcessual'  => 'Procedimento Comum',
            'assuntoProcessual' => 'Indenização',
            'situacaoProcesso'  => 'EM_ANDAMENTO',
            'instancia'         => 'G1',
            'assuntos'          => [
                ['id' => (string) $idDanoMoral, 'nome' => 'Dano Moral', 'codigo' => '10433'],
                ['nome' => 'Verbas Rescisórias', 'codigo' => '13970'],
            ],
        ]);
        self::assertResponseRedirects();

        $em->clear();
        $recarregado = $em->getRepository(Processo::class)->findOneBy(['numeroProcesso' => $numero]);
        $nomes = array_map(static fn($a) => $a->getNome(), $recarregado->getAssuntos()->toArray());
        sort($nomes);

        self::assertSame(['DANO MORAL', 'VERBAS RESCISÓRIAS'], $nomes, 'edição reconcilia: mantém, remove e adiciona');
        // O id de Dano Moral foi preservado (não recriado) e tudo segue no tenant correto.
        $mantido = false;
        foreach ($recarregado->getAssuntos() as $a) {
            if ($a->getId() === $idDanoMoral) {
                $mantido = true;
            }
            self::assertSame(
                (int) $tenant->getId(),
                (int) $a->getTenant()->getId(),
                'assunto após edição (inclusive o novo) deve permanecer no tenant correto'
            );
        }
        self::assertTrue($mantido, 'o assunto mantido deve reter o mesmo id (reconciliação por id)');
    }

    #[TestDox('Editar o processo pelo formulário preserva os complementos da movimentação (não editáveis à mão)')]
    public function testEditarPreservaComplementosDaMovimentacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');

        // Cria um processo com movimentação JÁ enriquecida com complementos (como vem do sync CNJ).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $numero = '5552222' . (++$this->seq) . '20238260100';
        $processo = new Processo();
        $processo->setNumeroProcesso($numero);
        $processo->setSiglaTribunal('TJSP');
        $processo->setTenant($tenant);
        $mov = new MovimentacaoProcesso();
        $mov->setDescricao('Distribuição');
        $mov->setTipo('26');
        $mov->setComplementos([['codigo' => 2, 'nome' => 'sorteio']]);
        $mov->setOrgaoCodigo('70867');
        $mov->setTenant($tenant);
        $processo->addMovimentacao($mov);
        $em->persist($processo);
        $em->flush();
        $movId = (int) $mov->getId();
        $processoId = (int) $processo->getId();
        $this->limparIdentityMap();

        // Edita o processo re-submetendo a movimentação com seu id (como o formulário renderiza),
        // sem carregar os complementos (o form não tem esse campo).
        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('POST', "/processos/{$processoId}/editar", [
            'numeroProcesso'    => $numero,
            'siglaTribunal'     => 'TJSP',
            'orgaoJulgador'     => '1a Vara',
            'classeProcessual'  => 'Procedimento Comum',
            'assuntoProcessual' => 'Indenização',
            'situacaoProcesso'  => 'EM_ANDAMENTO',
            'instancia'         => 'G1',
            'movimentacoes'     => [
                ['id' => (string) $movId, 'descricao' => 'Distribuição', 'tipo' => '26', 'dataMovimentacao' => '2025-11-25'],
            ],
        ]);
        self::assertResponseRedirects();

        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        $recarregado = $em->getRepository(MovimentacaoProcesso::class)->find($movId);
        self::assertNotNull($recarregado);
        self::assertSame([['codigo' => 2, 'nome' => 'sorteio']], $recarregado->getComplementos(), 'complementos não podem sumir ao editar o processo pelo form');
        self::assertSame('70867', $recarregado->getOrgaoCodigo(), 'código do órgão da movimentação também é preservado');
    }

    // ----------------------------------------------------------------- helpers

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PROC ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarUsuarioComum(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('comum_' . uniqid() . '@test.com');
        $user->setFullName('Comum ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel NÃO-system e sem permissões → não tem modules.processos.view.
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Comum ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarProcesso(Tenant $tenant): Processo
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $n  = ++$this->seq;

        $processo = new Processo();
        $processo->setNumeroProcesso('PROC' . $n . 'Z' . substr(uniqid(), -6));
        $processo->setSiglaTribunal('TRT2');
        $processo->setOrgaoJulgador('1a Vara');
        $processo->setClasseProcessual('Reclamação');
        $processo->setAssuntoProcessual('Rescisão');
        $processo->setSituacaoProcesso('Em Andamento');
        $processo->setInstancia('1a Instância');
        $processo->setTenant($tenant);
        $em->persist($processo);
        $em->flush();

        return $processo;
    }
}
