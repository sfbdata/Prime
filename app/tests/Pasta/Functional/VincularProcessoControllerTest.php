<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\TipoDeApenso;
use App\Processo\Entity\Processo;
use App\Processo\Service\DatajudClient;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class VincularProcessoControllerTest extends JusPrimeWebTestCase
{
    use CriaUsuarioComPermissoesTrait;

    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    /** Obrigatórios do cadastro novo pela janela (desenho 02, `procSalvarVinc`: "Preencha: ..."). */
    private const OBRIGATORIOS = [
        'orgaoJulgador' => '2ª Vara Cível',
        'classeProcessual' => 'Procedimento Comum Cível',
        'assuntoProcessual' => 'Indenização por Dano Moral',
    ];

    #[TestDox('POST vincular sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/processo/vincular');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Vincular processo existente por id retorna 200 e marca como principal')]
    public function testVincularProcessoExistentePorId(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '11111111111111111111');

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token'      => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'processo_id' => $processo->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        // A resposta traz o HTML da lista atualizada para troca in-place (sem reload).
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('html', $data);
        self::assertStringContainsString('11111111111111111111', $data['html']);

        $pastaFresca = $this->recarregarPasta($pasta->getId());
        self::assertCount(1, $pastaFresca->getPastaProcessos());
        self::assertSame($processo->getId(), $pastaFresca->getProcessoPrincipal()?->getId());
    }

    #[TestDox('P5d: vincular escreve "processo N vinculado à pasta (classe · tribunal)." SEM a ação da pasta (texto livre), nem no audit_log; desvincular, "processo N desvinculado da pasta."; vincular de novo não repete')]
    public function testRegistroDoVincularEDesvincular(): void
    {
        $client          = static::createClient();
        $client->disableReboot(); // o token falso de CSRF precisa valer nas três requisições
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Ação de cobrança contra Joaquim Pereira');
        $processo        = $this->criarProcesso($tenant, '11111111111111111111');
        $processo->setSiglaTribunal('TJDFT');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->autenticar($client, $user, $tenant);
        $vincular = fn () => $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'processo_id' => $processo->getId(),
        ], [], self::XHR);

        $vincular();
        $vincular();
        self::assertSame(
            ['<p><strong>Processo:</strong> processo 1111111-11.1111.1.11.1111 vinculado à pasta (PROCEDIMENTO · TJDFT).</p>'],
            $this->registros($pasta),
            'o segundo vínculo é no-op e não repete o cartão',
        );
        $auditoria = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn(
            "SELECT coalesce(changes::text, '') FROM audit_log WHERE entity_class = :c AND tenant_id = :t",
            ['c' => \App\Pasta\Entity\PastaMensagem::class, 't' => $tenant->getId()],
        );
        self::assertNotSame([], $auditoria, 'o cartão do sistema é auditado');
        foreach ($auditoria as $linha) {
            self::assertStringNotContainsStringIgnoringCase('Joaquim', (string) $linha, 'o audit_log do registro não leva a ação da pasta (dado pessoal)');
        }

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/desvincular", [
            '_token' => $this->gerarCsrf('pasta_desvincular_processo_' . $pasta->getId()),
            'processo_id' => $processo->getId(),
        ], [], self::XHR);
        self::assertResponseIsSuccessful();
        self::assertSame('<p><strong>Processo:</strong> processo 1111111-11.1111.1.11.1111 desvinculado da pasta.</p>', $this->registros($pasta)[1] ?? null);
    }

    #[TestDox('P5d: cadastro novo pela janela grava valor da causa, jurisdição e as flags como dados MANUAIS do processo')]
    public function testCadastroNovoGravaDadosManuais(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'numeroProcesso' => '1234567-89.2024.1.23.4567',
            ...self::OBRIGATORIOS,
            'situacaoProcesso' => 'TRANSITADO_EM_JULGADO',
            'valorCausa' => 'R$ 48.750,00',
            'jurisdicao' => 'Brasília',
            'gratuita' => '1',
            'segredo' => '0',
            'tutela' => '',
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $linha = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            "SELECT valor_causa_manual, jurisdicao_manual, gratuita_manual, segredo_manual, tutela_manual, orgao_manual, situacao_processo FROM processo WHERE numero_processo = '12345678920241234567' AND tenant_id = :t",
            ['t' => $tenant->getId()],
        );
        self::assertNotFalse($linha);
        self::assertSame('48750.00', $linha['valor_causa_manual']);
        self::assertSame('Brasília', $linha['jurisdicao_manual']);
        self::assertTrue($linha['gratuita_manual']);
        self::assertFalse($linha['segredo_manual'], '"Não" sem sigilo informado vira manual');
        self::assertNull($linha['tutela_manual'], '"Não informado" não grava');
        self::assertNull($linha['orgao_manual'], 'os campos do cadastro (Datajud) não viram manuais');
        self::assertSame('TRANSITADO_EM_JULGADO', $linha['situacao_processo']);
    }

    #[TestDox('P5d: dado manual inválido no cadastro novo não some calado: 422 com a mensagem, e nada é gravado (nem processo, nem vínculo, nem registro)')]
    public function testCadastroComDadoManualInvalidoDevolve422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'numeroProcesso' => '7654321-89.2024.1.23.4567',
            ...self::OBRIGATORIOS,
            'valorCausa' => 'muito dinheiro',
        ], [], self::XHR);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Valor da causa inválido. Use R$ 0,00.'], json_decode((string) $client->getResponse()->getContent(), true));
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $conexao->fetchOne("SELECT count(*) FROM processo WHERE numero_processo = '76543218920241234567' AND tenant_id = :t", ['t' => $tenant->getId()]));
        self::assertSame(0, (int) $conexao->fetchOne('SELECT count(*) FROM pasta_processo WHERE pasta_id = :p', ['p' => $pasta->getId()]));
        self::assertSame([], $this->registros($pasta));
    }

    #[TestDox('cadastro novo sem órgão, classe ou assunto: 422 com "Preencha: ...", e nada é gravado (nem processo, nem vínculo, nem registro)')]
    public function testCadastroSemObrigatoriosDevolve422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'numeroProcesso' => '4444444-44.2024.1.23.4567',
            'classeProcessual' => 'Procedimento Comum Cível',
        ], [], self::XHR);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Preencha: órgão julgador, assunto processual.'], json_decode((string) $client->getResponse()->getContent(), true));
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $conexao->fetchOne("SELECT count(*) FROM processo WHERE numero_processo = '44444444420241234567' AND tenant_id = :t", ['t' => $tenant->getId()]));
        self::assertSame(0, (int) $conexao->fetchOne('SELECT count(*) FROM pasta_processo WHERE pasta_id = :p', ['p' => $pasta->getId()]));
        self::assertSame([], $this->registros($pasta));
    }

    #[TestDox('P5d com P4f: apensado cadastrado pelo CNJ na janela também grava os dados manuais (o 4º argumento chega ao modo pedido)')]
    public function testApensadoCadastradoGravaDadosManuais(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $principal       = $this->criarProcesso($tenant, '11111111111111111111');
        $this->vincular($pasta, $principal, true);
        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'modo' => 'apenso',
            'tipo_apenso' => 'embargos',
            'numeroProcesso' => '2222222-22.2024.1.23.4567',
            ...self::OBRIGATORIOS,
            'valorCausa' => 'R$ 1.500,00',
            'jurisdicao' => 'Brasília',
            'tutela' => '1',
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $linha = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            "SELECT valor_causa_manual, jurisdicao_manual, tutela_manual FROM processo WHERE numero_processo = '22222222220241234567' AND tenant_id = :t",
            ['t' => $tenant->getId()],
        );
        self::assertNotFalse($linha);
        self::assertSame('1500.00', $linha['valor_causa_manual']);
        self::assertSame('Brasília', $linha['jurisdicao_manual']);
        self::assertTrue($linha['tutela_manual']);
    }

    #[TestDox('P5d com P4f: apensado com dado manual inválido devolve 422 e nada é gravado (nem processo, nem vínculo)')]
    public function testApensadoComDadoInvalidoDevolve422(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $principal       = $this->criarProcesso($tenant, '11111111111111111111');
        $this->vincular($pasta, $principal, true);
        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'modo' => 'apenso',
            'tipo_apenso' => 'embargos',
            'numeroProcesso' => '3333333-33.2024.1.23.4567',
            ...self::OBRIGATORIOS,
            'valorCausa' => 'muito dinheiro',
        ], [], self::XHR);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Valor da causa inválido. Use R$ 0,00.'], json_decode((string) $client->getResponse()->getContent(), true));
        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $conexao->fetchOne("SELECT count(*) FROM processo WHERE numero_processo = '33333333320241234567' AND tenant_id = :t", ['t' => $tenant->getId()]));
        self::assertSame(1, (int) $conexao->fetchOne('SELECT count(*) FROM pasta_processo WHERE pasta_id = :p', ['p' => $pasta->getId()]), 'só o principal');
    }

    #[TestDox('P5d: processo JÁ cadastrado (por número ou por id) não ganha dados manuais pela janela (no cartão, isso exige EDIT no processo)')]
    public function testProcessoExistenteNaoGanhaDadosManuais(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outra           = $this->criarPasta($tenant);
        $existente       = $this->criarProcesso($tenant, '12345678920241234567');
        $porId           = $this->criarProcesso($tenant, '22222222222222222222');
        $this->autenticar($client, $user, $tenant);
        $manuais = ['valorCausa' => 'R$ 1.000,00', 'jurisdicao' => 'Brasília', 'gratuita' => '1'];

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", ['_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()), 'numeroProcesso' => '1234567-89.2024.1.23.4567'] + $manuais, [], self::XHR);
        self::assertResponseIsSuccessful();
        $client->request('POST', "/pasta/{$outra->getId()}/processo/vincular", ['_token' => $this->gerarCsrf('vincular_processo_' . $outra->getId()), 'processo_id' => $porId->getId()] + $manuais, [], self::XHR);
        self::assertResponseIsSuccessful();

        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT count(*) FROM processo WHERE id IN (:a, :b) AND (valor_causa_manual IS NOT NULL OR jurisdicao_manual IS NOT NULL OR gratuita_manual IS NOT NULL OR dados_manuais_em IS NOT NULL)',
            ['a' => $existente->getId(), 'b' => $porId->getId()],
        ));
    }

    #[TestDox('a2: número que já existe no escritório vincula o existente, devolve o aviso e não sobrescreve nada; por id ou número novo, sem aviso')]
    public function testNumeroJaCadastradoAvisa(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outra           = $this->criarPasta($tenant);
        $existente       = $this->criarProcesso($tenant, '12345678920241234567');
        $orgaoAntes      = $existente->getOrgaoJulgador();
        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", ['_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()), 'numeroProcesso' => '1234567-89.2024.1.23.4567', 'orgaoJulgador' => 'Vara digitada'], [], self::XHR);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(PastaController::AVISO_PROCESSO_JA_CADASTRADO, $json['aviso'] ?? null);
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame($orgaoAntes, $conn->fetchOne('SELECT orgao_julgador FROM processo WHERE id = :id', ['id' => $existente->getId()]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM pasta_processo WHERE pasta_id = :p AND processo_id = :id', ['p' => $pasta->getId(), 'id' => $existente->getId()]));

        $client->request('POST', "/pasta/{$outra->getId()}/processo/vincular", ['_token' => $this->gerarCsrf('vincular_processo_' . $outra->getId()), 'processo_id' => $existente->getId()], [], self::XHR);
        self::assertArrayNotHasKey('aviso', json_decode((string) $client->getResponse()->getContent(), true));

        $client->request('POST', "/pasta/{$outra->getId()}/processo/vincular", ['_token' => $this->gerarCsrf('vincular_processo_' . $outra->getId()), 'numeroProcesso' => '5555555-55.2024.1.23.4567', 'modo' => 'apenso', 'tipo_apenso' => 'embargos', ...self::OBRIGATORIOS], [], self::XHR);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('aviso', json_decode((string) $client->getResponse()->getContent(), true));
    }

    #[TestDox('a2 e escritório: o mesmo número em OUTRO escritório não conta; nasce um processo novo aqui, sem aviso, e o alheio não é vinculado')]
    public function testMesmoNumeroEmOutroEscritorioNaoConta(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        [, $outroTenant] = $this->criarUsuarioAdmin();
        $alheio          = $this->criarProcesso($outroTenant, '12345678920241234567');
        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", ['_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()), 'numeroProcesso' => '1234567-89.2024.1.23.4567', ...self::OBRIGATORIOS], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('aviso', json_decode((string) $client->getResponse()->getContent(), true));
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $novo = $conn->fetchOne("SELECT id FROM processo WHERE numero_processo = '12345678920241234567' AND tenant_id = :t", ['t' => $tenant->getId()]);
        self::assertNotFalse($novo, 'nasce um processo no escritório da sessão');
        self::assertNotSame($alheio->getId(), (int) $novo);
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM pasta_processo WHERE processo_id = :id', ['id' => $alheio->getId()]));
    }

    #[TestDox('a1: "Buscar no CNJ" guarda a consulta no servidor; o cadastro aplica o que a janela não leva (assuntos, sigilo, formato, sistema, códigos, id, bruto) e a janela vence campo a campo')]
    public function testCadastroAplicaAConsultaDoCnjGuardada(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->autenticar($client, $user, $tenant);
        $this->simularCnj();

        $client->request('POST', '/processos/api/search', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['numeroProcesso' => '0001234-56.2020.8.26.0100']));
        self::assertResponseIsSuccessful();

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'numeroProcesso' => '0001234-56.2020.8.26.0100',
            'orgaoJulgador' => 'Vara editada na janela',
            'classeProcessual' => 'Procedimento Comum Cível',
            'assuntoProcessual' => 'Dano Moral',
            'partes' => [['tipo' => 'PARTE', 'nome' => 'Ana Souza', 'documento' => '', 'papel' => 'Polo ativo']],
            'movimentacoes' => [
                ['dataMovimentacao' => '2020-05-04', 'descricao' => 'Distribuído por sorteio', 'tipo' => '26', 'orgao' => 'VARA CIVEL 1'],
                ['dataMovimentacao' => '2020-06-01', 'descricao' => 'Anotação do escritório', 'tipo' => '', 'orgao' => ''],
            ],
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $linha = $conn->fetchAssociative("SELECT id, orgao_julgador, nivel_sigilo, formato, sistema, sistema_codigo, classe_codigo, datajud_id, datajud_raw FROM processo WHERE numero_processo = '00012345620208260100' AND tenant_id = :t", ['t' => $tenant->getId()]);
        self::assertNotFalse($linha);
        self::assertSame('VARA EDITADA NA JANELA', $linha['orgao_julgador'], 'o editado na janela vence o CNJ');
        self::assertSame(1, $linha['nivel_sigilo']);
        self::assertSame('Eletrônico', $linha['formato']);
        self::assertSame('PJe', $linha['sistema']);
        self::assertSame(1, $linha['sistema_codigo']);
        self::assertSame(7, $linha['classe_codigo']);
        self::assertSame('TJSP_G1_00012345620208260100', $linha['datajud_id']);
        self::assertNotNull($linha['datajud_raw']);
        self::assertSame(['DANO MORAL', 'INDENIZAÇÃO'], $conn->fetchFirstColumn('SELECT nome FROM assunto_processo WHERE processo_id = :p ORDER BY nome', ['p' => $linha['id']]));
        self::assertSame(['ANA SOUZA'], $conn->fetchFirstColumn('SELECT nome FROM parte_processo WHERE processo_id = :p', ['p' => $linha['id']]), 'as partes são as da janela');
        $movs = $conn->fetchAllAssociative('SELECT descricao, orgao_codigo FROM movimentacao_processo WHERE processo_id = :p ORDER BY descricao', ['p' => $linha['id']]);
        self::assertSame([['descricao' => 'ANOTAÇÃO DO ESCRITÓRIO', 'orgao_codigo' => null], ['descricao' => 'DISTRIBUÍDO POR SORTEIO', 'orgao_codigo' => '1001']], $movs, 'a que veio igual do CNJ guarda o código do órgão; a que a pessoa tirou da janela não entra');
    }

    #[TestDox('a1: dados do Datajud mandados pelo navegador são ignorados; sem consulta guardada (ou de outro número), o cadastro fica só com a janela')]
    public function testDadosDoDatajudDoNavegadorSaoIgnorados(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->autenticar($client, $user, $tenant);
        $this->simularCnj();
        $client->request('POST', '/processos/api/search', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['numeroProcesso' => '0001234-56.2020.8.26.0100']));

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token' => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'numeroProcesso' => '6666666-66.2024.1.23.4567',
            ...self::OBRIGATORIOS,
            'nivelSigilo' => '5',
            'datajudId' => 'forjado',
            'datajudRaw' => '{"forjado":true}',
            'assuntos' => [['codigo' => '1', 'nome' => 'Forjado']],
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $linha = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            "SELECT id, nivel_sigilo, datajud_id, datajud_raw FROM processo WHERE numero_processo = '66666666620241234567' AND tenant_id = :t",
            ['t' => $tenant->getId()],
        );
        self::assertNotFalse($linha);
        self::assertNull($linha['nivel_sigilo']);
        self::assertNull($linha['datajud_id']);
        self::assertNull($linha['datajud_raw']);
        self::assertSame(0, (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT count(*) FROM assunto_processo WHERE processo_id = :p', ['p' => $linha['id']]));
    }

    /** O CNJ de mentira (o cliente HTTP é nosso): um processo do TJSP com o que a janela não leva de volta. */
    private function simularCnj(): void
    {
        $datajud = $this->createMock(DatajudClient::class);
        $datajud->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => [['_source' => [
            'id' => 'TJSP_G1_00012345620208260100',
            'numeroProcesso' => '00012345620208260100',
            'tribunal' => 'TJSP',
            'grau' => 'G1',
            'nivelSigilo' => 1,
            'classe' => ['codigo' => 7, 'nome' => 'Procedimento Comum Cível'],
            'formato' => ['codigo' => 1, 'nome' => 'Eletrônico'],
            'sistema' => ['codigo' => 1, 'nome' => 'PJe'],
            'orgaoJulgador' => ['codigo' => 1001, 'nome' => '1ª Vara Cível'],
            'assuntos' => [['codigo' => 10433, 'nome' => 'Indenização'], ['codigo' => 10434, 'nome' => 'Dano Moral']],
            'dataAjuizamento' => '20200504000000',
            'movimentos' => [
                ['codigo' => 26, 'nome' => 'Distribuído por sorteio', 'dataHora' => '2020-05-04T10:00:00.000Z', 'orgaoJulgador' => ['codigo' => '1001', 'nomeOrgao' => 'Vara Civel 1']],
                ['codigo' => 51, 'nome' => 'Conclusos para despacho', 'dataHora' => '2020-05-10T10:00:00.000Z', 'orgaoJulgador' => ['codigo' => '1001', 'nomeOrgao' => 'Vara Civel 1']],
            ],
        ]]]]]);
        static::getContainer()->set(DatajudClient::class, $datajud);
    }

    /** @return list<string> o conteúdo dos cartões do sistema "proc" da pasta, na ordem */
    private function registros(Pasta $pasta): array
    {
        return array_map('strval', static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn(
            "SELECT conteudo FROM pasta_mensagem WHERE pasta_id = :p AND sistema = 'proc' ORDER BY id",
            ['p' => $pasta->getId()],
        ));
    }

    #[TestDox('Vincular por número inexistente cria o processo e o vincula')]
    public function testVincularNovoViaNumeroCriaProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token'         => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'numeroProcesso' => '1234567-89.2024.1.23.4567',
            'siglaTribunal'  => 'tjdft',
            ...self::OBRIGATORIOS,
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $pastaFresca = $this->recarregarPasta($pasta->getId());
        self::assertCount(1, $pastaFresca->getPastaProcessos());
        self::assertSame('12345678920241234567', $pastaFresca->getProcessoPrincipal()?->getNumeroProcesso());
    }

    #[TestDox('Vincular o mesmo processo duas vezes é idempotente')]
    public function testVincularDuplicadoNaoDuplica(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '22222222222222222222');
        $this->vincular($pasta, $processo, principal: true); // já vinculado

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token'      => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'processo_id' => $processo->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->recarregarPasta($pasta->getId())->getPastaProcessos());
    }

    #[TestDox('Vincular processo de outro escritório é negado (isolamento de tenant)')]
    public function testVincularProcessoDeOutroTenantNegado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outroTenant     = $this->criarTenant();
        $processoAlheio  = $this->criarProcesso($outroTenant, '33333333333333333333');

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/vincular", [
            '_token'      => $this->gerarCsrf('vincular_processo_' . $pasta->getId()),
            'processo_id' => $processoAlheio->getId(),
        ], [], self::XHR);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->recarregarPasta($pasta->getId())->getPastaProcessos());
    }

    #[TestDox('Desvincular um processo específico o remove e promove um novo principal')]
    public function testDesvincularPromoveNovoPrincipal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $procA           = $this->criarProcesso($tenant, '44444444444444444444');
        $procB           = $this->criarProcesso($tenant, '55555555555555555555');
        $this->vincular($pasta, $procA, principal: true);
        $this->vincular($pasta, $procB, principal: false);

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/desvincular", [
            '_token'      => $this->gerarCsrf('pasta_desvincular_processo_' . $pasta->getId()),
            'processo_id' => $procA->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $pastaFresca = $this->recarregarPasta($pasta->getId());
        self::assertCount(1, $pastaFresca->getPastaProcessos());
        self::assertSame($procB->getId(), $pastaFresca->getProcessoPrincipal()?->getId());
    }

    #[TestDox('Definir processo principal troca o principal da pasta')]
    public function testDefinirProcessoPrincipal(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $procA           = $this->criarProcesso($tenant, '66666666666666666666');
        $procB           = $this->criarProcesso($tenant, '77777777777777777777');
        $this->vincular($pasta, $procA, principal: true);
        $this->vincular($pasta, $procB, principal: false);

        $this->autenticar($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/processo/principal", [
            '_token'      => $this->gerarCsrf('pasta_definir_principal_processo_' . $pasta->getId()),
            'processo_id' => $procB->getId(),
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        self::assertSame($procB->getId(), $this->recarregarPasta($pasta->getId())->getProcessoPrincipal()?->getId());
    }

    #[TestDox('Buscar processos retorna só os do tenant e exclui os já vinculados')]
    public function testBuscarRetornaSoDoTenantExcluindoVinculados(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $vinculado       = $this->criarProcesso($tenant, '88888888888888888888', 'EXECUCAO FISCAL');
        $disponivel      = $this->criarProcesso($tenant, '99999999999999999999', 'EXECUCAO FISCAL');
        $alheio          = $this->criarProcesso($this->criarTenant(), '10101010101010101010', 'EXECUCAO FISCAL');
        $this->vincular($pasta, $vinculado, principal: true);

        $this->autenticar($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/processo/buscar?q=execucao", [], [], self::XHR);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $ids  = array_column($data['results'], 'id');
        self::assertContains($disponivel->getId(), $ids);
        self::assertNotContains($vinculado->getId(), $ids);
        self::assertNotContains($alheio->getId(), $ids);
    }

    #[TestDox('Desenho N:299-302: sem termo, a busca devolve até 30 do escritório (a lista abre ao focar, inline), sem os de outro escritório')]
    public function testBuscarSemTermoDevolveOsDoTenant(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        for ($i = 0; $i < 10; ++$i) {
            $this->criarProcesso($tenant, '3' . sprintf('%019d', $i));
        }
        $alheio = $this->criarProcesso($this->criarTenant(), '40404040404040404040');

        $this->autenticar($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/processo/buscar", [], [], self::XHR);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(10, $data['results']);
        self::assertNotContains($alheio->getId(), array_column($data['results'], 'id'));
    }

    #[TestDox('Com termo, a busca filtra; o número digitado com a máscara do CNJ casa pelos dígitos')]
    public function testBuscarComTermoFiltra(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $alvo            = $this->criarProcesso($tenant, '07069580320218070018', 'MONITORIA');
        $outro           = $this->criarProcesso($tenant, '10701670520244013400', 'USUCAPIAO');

        $this->autenticar($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/processo/buscar?q=monit", [], [], self::XHR);
        $ids = array_column(json_decode((string) $client->getResponse()->getContent(), true)['results'], 'id');
        self::assertSame([$alvo->getId()], $ids);

        $client->request('GET', "/pasta/{$pasta->getId()}/processo/buscar?q=" . rawurlencode('0706958-03.2021'), [], [], self::XHR);
        $ids = array_column(json_decode((string) $client->getResponse()->getContent(), true)['results'], 'id');
        self::assertSame([$alvo->getId()], $ids);
        self::assertNotContains($outro->getId(), $ids);
    }

    #[TestDox('Cada item traz número, classe, tribunal e a origem: pasta N, apensado da pasta N ou Push processual')]
    public function testBuscarTrazAOrigem(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outraPasta      = $this->criarPasta($tenant);
        $daPasta         = $this->criarProcesso($tenant, '50000000000000000001', 'ORIGEMTESTE');
        $apensado        = $this->criarProcesso($tenant, '50000000000000000002', 'ORIGEMTESTE');
        $soPush          = $this->criarProcesso($tenant, '50000000000000000003', 'ORIGEMTESTE');
        $this->vincular($outraPasta, $daPasta, principal: true);
        $outraPasta->vincularApensado($apensado, TipoDeApenso::Embargos);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->autenticar($client, $user, $tenant);
        $client->request('GET', "/pasta/{$pasta->getId()}/processo/buscar?q=origemteste", [], [], self::XHR);

        self::assertResponseIsSuccessful();
        $porId = array_column(json_decode((string) $client->getResponse()->getContent(), true)['results'], null, 'id');
        self::assertSame(['tipo' => 'pasta', 'pasta' => $outraPasta->getNup()], $porId[$daPasta->getId()]['origem']);
        self::assertSame(['tipo' => 'apensado', 'pasta' => $outraPasta->getNup()], $porId[$apensado->getId()]['origem']);
        self::assertSame(['tipo' => 'push', 'pasta' => null], $porId[$soPush->getId()]['origem']);
        self::assertSame('50000000000000000001', $porId[$daPasta->getId()]['numero']);
        self::assertSame('ORIGEMTESTE', $porId[$daPasta->getId()]['classe']);
    }

    #[TestDox('Origem de pasta que o usuário não pode VER: "Outra pasta" (sem o número); com acesso à pasta, o número')]
    public function testOrigemSoComONumeroDaPastaQueOUsuarioVe(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [, $tenant]      = $this->criarUsuarioAdmin();
        $pastaA          = $this->criarPasta($tenant);
        $pastaB          = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '70000000000000000007', 'ORIGEMRESTRITA');
        $this->vincular($pastaB, $processo, principal: true);
        // Papel sem `resources.pasta.view`: vê só a pasta A, por acesso ao recurso.
        $usuario = $this->criarUsuarioComPermissoes($tenant, []);
        $this->darAcessoDeLeitura($usuario, $tenant, $pastaA);

        $this->autenticar($client, $usuario, $tenant);
        $client->request('GET', "/pasta/{$pastaA->getId()}/processo/buscar?q=origemrestrita", [], [], self::XHR);
        self::assertResponseIsSuccessful();
        $item = json_decode((string) $client->getResponse()->getContent(), true)['results'][0];
        self::assertSame($processo->getId(), $item['id']);
        self::assertSame(['tipo' => 'pasta', 'pasta' => null], $item['origem']);
        self::assertStringNotContainsString((string) $pastaB->getNup(), (string) $client->getResponse()->getContent());

        // A prova do guarda: só o acesso à pasta B muda.
        $this->darAcessoDeLeitura($usuario, $tenant, $pastaB);
        $client->request('GET', "/pasta/{$pastaA->getId()}/processo/buscar?q=origemrestrita", [], [], self::XHR);
        $item = json_decode((string) $client->getResponse()->getContent(), true)['results'][0];
        self::assertSame(['tipo' => 'pasta', 'pasta' => $pastaB->getNup()], $item['origem']);
    }

    #[TestDox('Processo em duas pastas, a principal invisível ao usuário e a outra visível: a origem mostra o número da visível')]
    public function testOrigemPrefereAPastaVisivel(): void
    {
        $client          = static::createClient();
        [, $tenant]      = $this->criarUsuarioAdmin();
        $pastaA          = $this->criarPasta($tenant);
        $invisivel       = $this->criarPasta($tenant);
        $visivel         = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '80000000000000000008', 'ORIGEMDUPLA');
        $this->vincular($invisivel, $processo, principal: true);
        $this->vincular($visivel, $processo, principal: true);
        $usuario = $this->criarUsuarioComPermissoes($tenant, []);
        $this->darAcessoDeLeitura($usuario, $tenant, $pastaA);
        $this->darAcessoDeLeitura($usuario, $tenant, $visivel);

        $this->autenticar($client, $usuario, $tenant);
        $client->request('GET', "/pasta/{$pastaA->getId()}/processo/buscar?q=origemdupla", [], [], self::XHR);

        self::assertResponseIsSuccessful();
        $item = json_decode((string) $client->getResponse()->getContent(), true)['results'][0];
        self::assertSame(['tipo' => 'pasta', 'pasta' => $visivel->getNup()], $item['origem']);
        self::assertStringNotContainsString((string) $invisivel->getNup(), (string) $client->getResponse()->getContent());
    }

    #[TestDox('Outro escritório não busca pela pasta alheia: nenhum processo dela vaza')]
    public function testBuscarNaoVazaOutroEscritorio(): void
    {
        $client             = static::createClient();
        [, $tenantA]         = $this->criarUsuarioAdmin();
        $pastaA              = $this->criarPasta($tenantA);
        $this->criarProcesso($tenantA, '60000000000000000006', 'SIGILOSO');
        [$intruso, $tenantB] = $this->criarUsuarioAdmin();

        $this->autenticar($client, $intruso, $tenantB);
        $client->request('GET', "/pasta/{$pastaA->getId()}/processo/buscar", [], [], self::XHR);

        self::assertNotSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('60000000000000000006', (string) $client->getResponse()->getContent());
    }

    // ── Helpers ──

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function darAcessoDeLeitura(User $user, Tenant $tenant, Pasta $pasta): void
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    private function autenticar($client, User $user, Tenant $tenant): void
    {
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Vinculo ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_vinculo_' . uniqid() . '@test.com');
        $user->setFullName('Admin Vinculo');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Alheio ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-VINC-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarProcesso(Tenant $tenant, string $numero, string $classe = 'PROCEDIMENTO'): Processo
    {
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual($classe);
        $em->persist($processo);
        $em->flush();

        return $processo;
    }

    private function vincular(Pasta $pasta, Processo $processo, bool $principal): void
    {
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $vinculo = $pasta->vincularProcesso($processo);
        $vinculo->setPrincipal($principal);
        $em->flush();
    }

    private function recarregarPasta(int $id): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Pasta::class)->find($id);
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }
}
