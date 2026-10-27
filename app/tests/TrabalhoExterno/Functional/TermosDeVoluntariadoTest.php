<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\TermosController;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O Termo de Voluntariado pela HTTP (spec `trabalho-externo-termo-voluntariado.md`): gerar e enviar (só quem gere o
 * banco), aceitar (só o dono do currículo), o HTML único do servidor, o estado na lista, no filtro, no KPI e no CSV,
 * e o dado pessoal (CPF e RG) só no snapshot: fora de lista, CSV, aviso e auditoria. Outro voluntário ou outro
 * escritório: 404.
 */
#[CoversClass(TermosController::class)]
final class TermosDeVoluntariadoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const API = '/trabalho-externo/api/curriculos';
    private const CPF = '111.222.333-44';
    private const RG = '9.876.543 SSP/DF';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->instalarCsrf($this->client);
    }

    #[TestDox('fluxo: rascunho pré-preenchido, envio, aceite do voluntário com hash do HTML, "Novo termo" copia o último e o estado é o do mais recente')]
    public function testFluxo(): void
    {
        [$sede, $a, $master, $voluntaria, $codigo] = $this->cenario();

        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::API . "/$codigo/termo/rascunho");
        self::assertResponseIsSuccessful();
        $rascunho = $this->json($this->client);
        self::assertSame(['rascunho', '1.0', 'Ana Souza', '', ''], [$rascunho['status'], $rascunho['versao'], $rascunho['campos']['vNome'], $rascunho['campos']['vCpf'], $rascunho['campos']['vRg']]);
        self::assertSame($master->getFullName(), $rascunho['campos']['resp']);
        self::assertSame(0, $this->contarTermos(), 'o rascunho não grava');
        self::assertSame('sem', $this->cartao($codigo)['termo']);

        $erro = $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", ['eNome' => 'Sede Ltda'] + $rascunho['campos']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Preencha antes de enviar: CNPJ/CPF do escritório, CPF do voluntário, foro.', $erro['mensagem']);

        $this->client->request('POST', self::API . "/$codigo/termo/previa", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], json_encode(self::campos(), JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $previa = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<b>' . self::CPF . '</b>', $previa);
        self::assertSame(0, $this->contarTermos(), 'a prévia não grava');

        $termo = $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos())['termo'];
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^TV-\d{4,}$/', $termo['codigo']);
        self::assertSame(['enviado', '1.0', false, '#9a5b00'], [$termo['status'], $termo['versao'], $termo['podeAceitar'], $termo['cor']]);
        self::assertStringStartsWith('Aguardando aceite do(a) voluntário(a) desde ', $termo['statusTxt']);
        self::assertStringStartsWith('Termo enviado em ', $termo['botaoTxt']);
        self::assertSame(['enviado', 'analise'], [$this->cartao($codigo)['termo'], $this->cartao($codigo)['status']], 'currículo novo vira análise');
        self::assertTrue($this->detalhe($codigo)['podeGerarTermo']);
        self::assertSame($termo['codigo'], $this->detalhe($codigo)['termoResumo']['codigo']);

        // O mesmo HTML para a Central e para o voluntário.
        $this->client->request('GET', $termo['documentoUrl']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $docCentral = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('em duas vias de igual teor', $docCentral);
        self::assertSame(1, $this->avisos('trabalho_externo_termo_enviado', $voluntaria), 'o voluntário foi avisado');

        // O voluntário: vê o resumo, aceita com o checkbox.
        $this->logarComTenant($this->client, $voluntaria, $a);
        $meu = $this->detalhe($codigo);
        self::assertTrue($meu['termoResumo']['podeAceitar']);
        self::assertFalse($meu['podeGerarTermo']);
        $this->client->request('GET', $termo['documentoUrl']);
        self::assertSame($docCentral, (string) $this->client->getResponse()->getContent());

        $this->postarJson($this->client, "/trabalho-externo/api/termos/{$termo['codigo']}/aceitar", ['li' => false]);
        self::assertResponseStatusCodeSame(422);
        $aceito = $this->postarJson($this->client, "/trabalho-externo/api/termos/{$termo['codigo']}/aceitar", ['li' => true], 'TOKEN_ajax')['termo'];
        self::assertResponseIsSuccessful();
        self::assertSame(['aceito', false], [$aceito['status'], $aceito['podeAceitar']]);
        self::assertStringStartsWith('Aceito eletronicamente em ', $aceito['statusTxt']);

        $linha = $this->em()->getConnection()->fetchAssociative('SELECT * FROM trabalho_externo_termo_voluntariado WHERE codigo = ?', [$termo['codigo']]);
        self::assertNotFalse($linha);
        self::assertSame(['aceito', $voluntaria->getId()], [$linha['status'], (int) $linha['aceito_por_id']]);
        self::assertNotNull($linha['aceito_em']);
        self::assertNotNull($linha['aceite_ip']);
        $this->client->request('GET', $termo['documentoUrl']);
        $docAceito = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('aceite eletrônico registrado no BlueJus', $docAceito);
        self::assertSame(hash('sha256', $docAceito), $linha['hash_aceite'], 'o hash é reproduzível a partir do snapshot e da data do aceite');

        $this->postarJson($this->client, "/trabalho-externo/api/termos/{$termo['codigo']}/aceitar", ['li' => true]);
        self::assertResponseStatusCodeSame(409, 'não se aceita duas vezes');
        self::assertSame(1, $this->avisos('trabalho_externo_termo_aceito', $master), 'o segundo aceite não avisa de novo');
        self::assertSame($linha['aceito_em'], $this->em()->getConnection()->fetchOne('SELECT aceito_em FROM trabalho_externo_termo_voluntariado WHERE codigo = ?', [$termo['codigo']]), 'o aceite não é reescrito');
        self::assertSame($linha['hash_aceite'], $this->em()->getConnection()->fetchOne('SELECT hash_aceite FROM trabalho_externo_termo_voluntariado WHERE codigo = ?', [$termo['codigo']]));
        self::assertGreaterThanOrEqual(1, $this->avisos('trabalho_externo_termo_aceito', $master));

        // "Novo termo": cria outro registro copiando o último; o estado é o do mais recente.
        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::API . "/$codigo/termo/rascunho");
        $copia = $this->json($this->client)['campos'];
        self::assertSame([self::CPF, self::RG], [$copia['vCpf'], $copia['vRg']]);
        $segundo = $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", $copia)['termo'];
        self::assertNotSame($termo['codigo'], $segundo['codigo']);
        self::assertSame(2, $this->contarTermos());
        self::assertSame('enviado', $this->cartao($codigo)['termo']);
        $this->client->request('GET', self::API . "/$codigo/termo");
        self::assertSame($segundo['codigo'], $this->json($this->client)['termo']['codigo']);
        $this->client->request('GET', $termo['documentoUrl']);
        self::assertSame($docAceito, (string) $this->client->getResponse()->getContent(), 'o termo enviado é imutável');
    }

    #[TestDox('só o termo mais recente aceita: após "Novo termo" o antigo dá 409 e o novo aceita')]
    public function testSoOMaisRecenteAceita(): void
    {
        [$sede, $a, $master, $voluntaria, $codigo] = $this->cenario();
        $this->logarComTenant($this->client, $master, $sede);
        $antigo = $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos())['termo'];
        $novo = $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos())['termo'];
        $this->logarComTenant($this->client, $voluntaria, $a);
        $this->postarJson($this->client, "/trabalho-externo/api/termos/{$antigo['codigo']}/aceitar", ['li' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM trabalho_externo_termo_voluntariado WHERE status = 'aceito'"));
        $this->postarJson($this->client, "/trabalho-externo/api/termos/{$novo['codigo']}/aceitar", ['li' => true]);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('permissões: Equipe sem talentos 403; currículo que não aceita voluntário 403; outro voluntário e outro escritório 404; interno não aceita; CSRF e prévia')]
    public function testPermissoes(): void
    {
        [$sede, $a, $master, $voluntaria, $codigo] = $this->cenario();
        $b = $this->criarTenant('B');
        $outra = $this->criarUsuario($b);
        $equipe = $this->criarUsuario($sede, nome: 'Equipe');
        $this->logarComTenant($this->client, $master, $sede);
        $termo = $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos())['termo'];

        // Outro voluntário, em outro escritório (com currículo próprio).
        $this->logarComTenant($this->client, $outra, $b);
        $this->postarJson($this->client, self::API . '/meu', self::curriculo('Bia Lima'));
        $this->client->request('GET', $termo['documentoUrl']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::API . "/$codigo/termo");
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::API . "/$codigo/termo/rascunho");
        self::assertResponseStatusCodeSame(404);
        $this->postarJson($this->client, "/trabalho-externo/api/termos/{$termo['codigo']}/aceitar", ['li' => true]);
        self::assertResponseStatusCodeSame(404);
        $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos());
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->contarTermos());

        // O voluntário não gera nem envia o próprio termo.
        $this->logarComTenant($this->client, $voluntaria, $a);
        $this->client->request('GET', self::API . "/$codigo/termo/rascunho");
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos());
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($this->client, "/trabalho-externo/api/termos/{$termo['codigo']}/aceitar", ['li' => true], 'errado');
        self::assertResponseStatusCodeSame(403, 'CSRF');

        // O interno que gere não aceita; a Equipe sem talentos nem gera nem lê o termo completo.
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, "/trabalho-externo/api/termos/{$termo['codigo']}/aceitar", ['li' => true]);
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos(), 'errado');
        self::assertResponseStatusCodeSame(403, 'CSRF');
        $this->client->request('GET', self::API . "/$codigo/termo/rascunho?como=equipe");
        self::assertResponseStatusCodeSame(403, 'a prévia do Master não gera');
        $this->semTalentos();
        $this->logarComTenant($this->client, $equipe, $sede);
        $this->client->request('GET', self::API . "/$codigo/termo/rascunho");
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $termo['documentoUrl']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('enviado', $this->cartao($codigo)['termo'], 'a Equipe vê o status na lista');
        self::assertNull($this->detalhe($codigo)['termoResumo']);
        self::assertFalse($this->detalhe($codigo)['podeGerarTermo']);
    }

    #[TestDox('currículo que não aceita voluntário não gera termo (403)')]
    public function testSemVoluntariado(): void
    {
        [$sede, $a, $master] = [$this->criarTenant('Sede'), $this->criarTenant('A'), null];
        $this->definirSede($sede);
        $master = $this->criarMaster($sede);
        $this->logarComTenant($this->client, $this->criarUsuario($a), $a);
        $codigo = $this->postarJson($this->client, self::API . '/meu', ['vol' => 'nao'] + self::curriculo('Caio Dias'))['curriculo']['codigo'];
        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::API . "/$codigo/termo/rascunho");
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->detalhe($codigo)['podeGerarTermo']);
    }

    #[TestDox('filtro, KPI e CSV leem o termo mais recente; CPF e RG não aparecem em lista, painel, CSV, aviso nem auditoria')]
    public function testIntegracoesESemDadoPessoal(): void
    {
        [$sede, $a, $master, $voluntaria, $codigo] = $this->cenario();
        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv');
        self::assertStringContainsString('"Sim";"Sem termo";', (string) $this->client->getResponse()->getContent());

        $termo = $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos())['termo'];
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv?termo=enviado');
        self::assertStringContainsString('"Sim";"Aguardando aceite";', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv?termo=aceito');
        self::assertStringNotContainsString('Ana Souza', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', self::API . '/painel');
        self::assertSame('0 termo(s) aceito(s) · 1 aguardando', $this->json($this->client)['kpis'][3]['sub']);

        $this->logarComTenant($this->client, $voluntaria, $a);
        $this->postarJson($this->client, "/trabalho-externo/api/termos/{$termo['codigo']}/aceitar", ['li' => true]);

        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv?termo=aceito');
        $csvAceito = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"Sim";"Aceito";', $csvAceito);
        $this->client->request('GET', self::API);
        $lista = $this->client->getResponse()->getContent();
        self::assertSame('aceito', $this->json($this->client)['curriculos'][0]['termo']);
        $this->client->request('GET', self::API . '/painel');
        $painel = (string) $this->client->getResponse()->getContent();
        self::assertSame('1 termo(s) aceito(s) · 0 aguardando', $this->json($this->client)['kpis'][3]['sub']);
        $this->client->request('GET', self::API . "/$codigo");
        $detalhe = (string) $this->client->getResponse()->getContent();

        $conn = $this->em()->getConnection();
        $avisos = json_encode($conn->fetchAllAssociative("SELECT titulo, mensagem, url FROM notificacao WHERE tipo LIKE 'trabalho_externo_termo_%'"), JSON_THROW_ON_ERROR);
        $auditoria = json_encode($conn->fetchAllAssociative('SELECT action, entity_id, changes FROM audit_log WHERE entity_class = ? ORDER BY id', [TermoDeVoluntariado::class]), JSON_THROW_ON_ERROR);
        $logs = json_encode($conn->fetchAllAssociative("SELECT changes FROM audit_log WHERE entity_class LIKE '%Notificacao%'"), JSON_THROW_ON_ERROR);
        foreach ([self::CPF, self::RG, '111222333', '9876543'] as $dado) {
            foreach (['lista' => $lista, 'csv' => $csvAceito, 'painel' => $painel, 'detalhe' => $detalhe, 'avisos' => $avisos, 'auditoria' => $auditoria, 'logs' => $logs] as $onde => $texto) {
                self::assertStringNotContainsString($dado, (string) $texto, "$dado vazou em $onde");
            }
        }
        self::assertStringContainsString($termo['codigo'], $auditoria, 'a auditoria registra o termo enviado e aceito, com o código');
        self::assertSame(['create', 'update'], array_column(json_decode($auditoria, true, 512, JSON_THROW_ON_ERROR), 'action'));
        self::assertStringNotContainsString('"campos"', $auditoria, 'o snapshot fica fora do diff');
        self::assertStringNotContainsString('aceiteIp', $auditoria, 'o IP fica fora do diff');
        self::assertStringNotContainsString('aceiteUserAgent', $auditoria, 'o user-agent fica fora do diff');
        self::assertNotNull($conn->fetchOne('SELECT campos FROM trabalho_externo_termo_voluntariado WHERE codigo = ?', [$termo['codigo']]), 'o CPF está no snapshot, e só nele');
        self::assertStringContainsString(self::CPF, (string) $conn->fetchOne('SELECT campos::text FROM trabalho_externo_termo_voluntariado WHERE codigo = ?', [$termo['codigo']]));
    }

    #[TestDox('excluir o currículo leva o termo (FK CASCADE) e o servidor registra a exclusão na auditoria sem dado pessoal; a purga do escritório emissor apaga o termo')]
    public function testExclusaoEPurga(): void
    {
        [$sede, $a, $master, , $codigo] = $this->cenario();
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::API . "/$codigo/termo/enviar", self::campos());
        self::assertSame(1, $this->contarTermos());

        $this->postarJson($this->client, self::API . "/$codigo/excluir");
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->contarTermos());
        $conn = $this->em()->getConnection();
        $apagado = $conn->fetchAssociative("SELECT action, changes::text AS changes FROM audit_log WHERE entity_class = ? AND action = 'delete'", [TermoDeVoluntariado::class]);
        self::assertNotFalse($apagado, 'a exclusão fica registrada');
        self::assertStringNotContainsString(self::CPF, $apagado['changes']);

        // Purga: um termo emitido por um escritório em quarentena vencida sai com ele.
        $this->logarComTenant($this->client, $this->criarUsuario($a, nome: 'Voluntária 2'), $a);
        $outro = $this->postarJson($this->client, self::API . '/meu', self::curriculo('Dora Alves'))['curriculo']['codigo'];
        $emissor = $this->criarTenant('Emissor');
        $this->definirSede($emissor);
        $this->logarComTenant($this->client, $this->criarUsuario($emissor, nome: 'Equipe'), $emissor); // a conta do Master nunca é purgada: quem emite é a Equipe
        $this->postarJson($this->client, self::API . "/$outro/termo/enviar", self::campos());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contarTermos());

        $emissor = $this->em()->find(Tenant::class, $emissor->getId());
        $emissor->setIsActive(false);
        $emissor->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        $this->em()->flush();
        static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($emissor, false);
        self::assertSame(0, $this->contarTermos());
    }

    /** @return array{0: Tenant, 1: Tenant, 2: User, 3: User, 4: string} sede, escritório da voluntária, Master, voluntária, código do currículo dela (voluntariado: sim) */
    private function cenario(): array
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $this->definirSede($sede);
        $master = $this->criarMaster($sede);
        $voluntaria = $this->criarUsuario($a, nome: 'Voluntária');
        $this->logarComTenant($this->client, $voluntaria, $a);
        $codigo = $this->postarJson($this->client, self::API . '/meu', ['vol' => 'sim'] + self::curriculo('Ana Souza'))['curriculo']['codigo'];

        return [$sede, $a, $master, $voluntaria, $codigo];
    }

    /** @return array<string, string> */
    private static function campos(): array
    {
        return [
            'eNome' => 'Sede Ltda', 'eDoc' => '12.345.678/0001-90', 'eEnd' => 'SQS 100, Brasília/DF', 'resp' => 'Marta Master', 'vNome' => 'Ana Souza',
            'vCpf' => self::CPF, 'vRg' => self::RG, 'ativ' => 'pesquisa jurídica', 'horas' => '20', 'modal' => 'regime híbrido', 'inicio' => '2026-10-27',
            'meses' => '6', 'aviso' => '10', 'ress' => 'nao', 'foro' => 'Brasília', 'cidade' => 'Brasília/DF', 'data' => '2026-10-27',
        ];
    }

    /** @return array<string, mixed> */
    private static function curriculo(string $nome): array
    {
        return [
            'nome' => $nome, 'email' => 'ana@exemplo.test', 'tel' => '(61) 99999-0000', 'situacao' => 'adv', 'oabNum' => '12345', 'oabUf' => 'DF',
            'cargo' => 'Advogado(a)', 'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Cível'], 'resumo' => 'Atuação cível.', 'vol' => 'sim', 'lgpd' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function detalhe(string $codigo): array
    {
        $this->client->request('GET', self::API . '/' . $codigo);
        self::assertResponseIsSuccessful();

        return $this->json($this->client)['curriculo'];
    }

    /** @return array<string, mixed> o cartão do currículo na lista da Central */
    private function cartao(string $codigo): array
    {
        $this->client->request('GET', self::API);
        foreach ($this->json($this->client)['curriculos'] as $cv) {
            if ($cv['codigo'] === $codigo) {
                return $cv;
            }
        }
        self::fail('o currículo não está na lista');
    }

    private function contarTermos(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM trabalho_externo_termo_voluntariado');
    }

    private function avisos(string $tipo, User $usuario): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM notificacao WHERE tipo = ? AND usuario_id = ?', [$tipo, $usuario->getId()]);
    }

    private function semTalentos(): void
    {
        $repo = static::getContainer()->get(\App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        $cfg->definirEquipe('talentos', false);
        $repo->salvar($cfg, flush: true);
    }
}
