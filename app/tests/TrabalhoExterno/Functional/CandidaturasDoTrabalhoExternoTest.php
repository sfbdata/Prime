<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\CandidaturasController;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A candidatura pela HTTP (spec VS 18.10.26 §4.6): o externo com currículo se candidata ou aceita a
 * oferta; o servidor recusa o interno, a vaga própria e a fechada; o aceite respeita a quantidade da
 * rodada; a Central com `talentos` envia candidatos e muda o andamento; o dono externo vê só os enviados
 * e abre o currículo deles sem nota nem situação no banco.
 */
#[CoversClass(CandidaturasController::class)]
final class CandidaturasDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const VAGAS = '/trabalho-externo/api/vagas';
    private const CURRICULOS = '/trabalho-externo/api/curriculos';

    private KernelBrowser $client;

    #[TestDox('candidatar: sem currículo 409; enviada com histórico; repetida não duplica; interno, vaga própria, fechada e prazo vencido são 422')]
    public function testCandidatarERecusas(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        [$doA, $doB] = [$this->criarUsuario($a, nome: 'Ana'), $this->criarUsuario($b, nome: 'Bia')];
        $codigo = $this->publicar($master, $sede, self::vaga('Advogado cível'));

        $this->logarComTenant($this->client, $doA, $a);
        $sem = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['ok' => false, 'semCurriculo' => true], $sem);
        $this->postarJson($this->client, self::CURRICULOS . '/meu', self::curriculo('Ana Souza'));
        $ok = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertResponseIsSuccessful();
        self::assertSame(['titulo' => 'Candidatura enviada', 'texto' => 'Acompanhe em Meu currículo · Minhas candidaturas.'], $ok['toast']);
        self::assertSame(['enviada', 'recebida', 'Recebida'], [$ok['vaga']['botaoExterno'], $ok['vaga']['minhaCandidatura']['status'], $ok['vaga']['minhaCandidatura']['statusRotulo']]);
        self::assertSame([], $ok['vaga']['candidatos'], 'o externo não vê os outros candidatos');
        $repetida = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertSame('Candidatura já enviada', $repetida['toast']['titulo']);
        self::assertSame(1, $this->contar('trabalho_externo_candidatura'));
        self::assertTrue($this->cartao($codigo)['jaFoi']);

        $this->logarComTenant($this->client, $master, $sede);
        $interno = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura?como=solicitante");
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Só profissionais externos se candidatam às vagas.', $interno['mensagem']);
        $detalhe = $this->detalhe($codigo);
        self::assertSame(['CANDIDATOS (1)', 1], [$detalhe['candTitulo'], $this->cartao($codigo)['nCand']]);
        self::assertSame('Candidatura de Ana Souza', $detalhe['historico'][0]['txt']);

        $this->logarComTenant($this->client, $doB, $b);
        $this->postarJson($this->client, self::CURRICULOS . '/meu', self::curriculo('Bia Lima'));
        $ontem = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $this->em()->getConnection()->executeStatement('UPDATE trabalho_externo_vaga SET inscricoes_ate = ? WHERE id = ?', [$ontem, Vaga::idDoCodigo($codigo)]);
        $vencida = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Inscrições encerradas', 'As inscrições desta vaga estão encerradas.'], [$vencida['titulo'], $vencida['mensagem']]);

        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/acoes/encerrar");
        $this->logarComTenant($this->client, $doB, $b);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertResponseStatusCodeSame(422, 'a vaga encerrada já foi pública: 422, não 404');
        $this->logarComTenant($this->client, $doA, $a);
        self::assertSame('enviada', $this->detalhe($codigo)['botaoExterno'], 'quem se candidatou abre a vaga fechada por "Minhas candidaturas"');

        $propria = $this->divulgarEAprovar($doB, $b, $master, $sede, 'Assistente jurídico');
        $this->logarComTenant($this->client, $doB, $b);
        self::assertNull($this->detalhe($propria)['botaoExterno'], 'a vaga própria não mostra "Candidatar-se"');
        $erro = $this->postarJson($this->client, self::VAGAS . "/$propria/candidatura");
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Você não pode se candidatar à sua própria vaga.', $erro['mensagem']);
    }

    #[TestDox('aceite: até completar a quantidade; o seguinte é 422 com "quase"; "Ofertar novamente" conta a rodada do zero')]
    public function testAceiteDeOferta(): void
    {
        [$sede, $a, $b, $c] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B'), $this->criarTenant('C')];
        $master = $this->criarMaster($sede);
        $codigo = $this->publicar($master, $sede, self::vaga('Correspondente') + ['oferta' => true, 'duracaoDaOferta' => 15, 'qtd' => 2]);
        [$doA, $doB, $doC] = [$this->comCurriculo($a, 'Ana Souza'), $this->comCurriculo($b, 'Bia Lima'), $this->comCurriculo($c, 'Caio Reis')];

        $this->logarComTenant($this->client, $doA, $a);
        self::assertSame('aceitar', $this->detalhe($codigo)['botaoExterno']);
        $aceite = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertSame(['titulo' => 'Oferta aceita', 'texto' => 'A vaga está reservada para você. O escritório já foi avisado.'], $aceite['toast']);
        self::assertSame('aprovado', $aceite['vaga']['minhaCandidatura']['status']);
        self::assertSame('Você já aceitou', $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura")['toast']['titulo']);

        $this->logarComTenant($this->client, $doB, $b);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertResponseIsSuccessful();
        $this->logarComTenant($this->client, $doC, $c);
        $quase = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Vaga indisponível', 'Esta vaga já foi preenchida ou encerrada.', true], [$quase['titulo'], $quase['mensagem'], $quase['quase']]);

        $this->logarComTenant($this->client, $master, $sede);
        $cheia = $this->detalhe($codigo);
        self::assertSame(['ocupada', 2, ['Ana Souza', 'Bia Lima']], [$cheia['situacao'], $cheia['aceites'], $cheia['aceitosPor']]);
        self::assertStringStartsWith('Oferta preenchida em', $cheia['historico'][0]['txt']);
        self::assertSame('Aceita por Bia Lima (2 de 2)', $cheia['historico'][1]['txt']);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/acoes/ofertar");
        self::assertSame(0, $this->detalhe($codigo)['aceites'], 'a nova rodada conta do zero');

        $this->logarComTenant($this->client, $doC, $c);
        self::assertSame('Oferta aceita', $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura")['toast']['titulo']);
        $this->logarComTenant($this->client, $master, $sede);
        self::assertSame(['aberta', 1], [$this->detalhe($codigo)['situacao'], $this->detalhe($codigo)['aceites']]);
    }

    #[TestDox('envio: só talentos (403); sem seleção 422; minEnvio só na tela; o dono vê só os enviados, sem botões, e abre o currículo e os arquivos deles sem nota; outro externo 404; status exige talentos')]
    public function testEnvioEDono(): void
    {
        [$sede, $a, $b, $c, $d] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B'), $this->criarTenant('C'), $this->criarTenant('D')];
        $this->definirSede($sede);
        $master = $this->criarMaster($sede);
        $equipe = $this->criarUsuario($sede, nome: 'Equipe');
        [$doA, $doD] = [$this->criarUsuario($a, nome: 'Dono'), $this->criarUsuario($d, nome: 'Outro')];
        $codigo = $this->divulgarEAprovar($doA, $a, $master, $sede, 'Advogado trabalhista');
        [$doB, $doC] = [$this->comCurriculo($b, 'Bia Lima'), $this->comCurriculo($c, 'Caio Reis')];
        foreach ([[$doB, $b], [$doC, $c]] as [$u, $t]) {
            $this->logarComTenant($this->client, $u, $t);
            $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
            $this->anexarCurriculo();
        }

        $this->logarComTenant($this->client, $master, $sede);
        $antes = $this->detalhe($codigo);
        [$idB, $idC] = array_column($antes['candidatos'], 'id');
        [$codigoCvB, $codigoCvC] = array_column($antes['candidatos'], 'codigoCv');
        self::assertSame(['0 enviado(s) ao escritório · padrão: 3 ou mais', true, 3], [$antes['envioInfo'], $antes['podeEnviar'], $antes['minEnvio']]);
        $this->postarJson($this->client, self::CURRICULOS . "/$codigoCvB/nota", ['nota' => 'Nota secreta']);
        $vazio = $this->postarJson($this->client, self::VAGAS . "/$codigo/enviar", ['candidaturas' => []]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Selecione candidatos', 'Marque quem será enviado ao escritório.'], [$vazio['titulo'], $vazio['mensagem']]);
        $enviado = $this->postarJson($this->client, self::VAGAS . "/$codigo/enviar", ['candidaturas' => [$idB, 999999]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['titulo' => 'Candidatos enviados', 'texto' => '1 currículo(s) disponível(is) para Escritório que oferece.'], $enviado['toast']);
        self::assertSame([true, 'analise'], [$enviado['vaga']['candidatos'][0]['enviado'], $enviado['vaga']['candidatos'][0]['status']]);

        $this->semTalentos();
        $this->logarComTenant($this->client, $equipe, $sede);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/enviar", ['candidaturas' => [$idC]]);
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($this->client, "/trabalho-externo/api/candidaturas/$idB/status", ['status' => 'entrevista']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $master, $sede);
        $status = $this->postarJson($this->client, "/trabalho-externo/api/candidaturas/$idB/status", ['status' => 'entrevista']);
        self::assertSame(['entrevista', 'Entrevista', '#5b3f9e'], [$status['vaga']['candidatos'][0]['status'], $status['vaga']['candidatos'][0]['statusRotulo'], $status['vaga']['candidatos'][0]['statusCor']]);

        $this->logarComTenant($this->client, $doA, $a);
        $dono = $this->detalhe($codigo);
        self::assertSame('CANDIDATOS INDICADOS PELO BLUEJUS (1) · O BLUEJUS ATUALIZA O ANDAMENTO', $dono['candTitulo']);
        self::assertSame([$idB], array_column($dono['candidatos'], 'id'), 'o dono vê só os enviados');
        self::assertFalse($dono['podeMudarStatus'] || $dono['podeEnviar']);
        self::assertSame(1, $this->cartao($codigo)['indicadosN']);
        $cv = $this->curriculoPorCodigo($codigoCvB);
        self::assertSame(['Bia Lima', null, null, [], false], [$cv['nome'], $cv['nota'], $cv['status'], $cv['candidaturasVagas'], $cv['podeGerir']]);
        self::assertStringNotContainsString('Nota secreta', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', "/trabalho-externo/curriculos/$codigoCvB/documento");
        self::assertResponseIsSuccessful();
        $this->client->request('GET', "/trabalho-externo/curriculos/$codigoCvB/arquivos/curriculo");
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', "/trabalho-externo/curriculos/$codigoCvC/arquivos/curriculo");
        self::assertResponseStatusCodeSame(404, 'o arquivo do não enviado continua fechado ao dono');
        $this->client->request('GET', self::CURRICULOS . "/$codigoCvC");
        self::assertResponseStatusCodeSame(404, 'o não enviado continua fechado ao dono');

        $this->logarComTenant($this->client, $doD, $d);
        $this->client->request('GET', self::CURRICULOS . "/$codigoCvB");
        self::assertResponseStatusCodeSame(404, 'outro externo não abre o candidato');
        $this->client->request('GET', "/trabalho-externo/curriculos/$codigoCvB/pdf");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('"Minhas candidaturas" com marcações só do dono; excluir a vaga ou o currículo apaga as candidaturas e as marcações delas')]
    public function testMinhasEExclusoes(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        $primeira = $this->publicar($master, $sede, self::vaga('Controladoria'));
        $segunda = $this->publicar($master, $sede, self::vaga('Administrativo'));
        [$doA, $doB] = [$this->comCurriculo($a, 'Ana Souza'), $this->comCurriculo($b, 'Bia Lima')];

        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, self::VAGAS . "/$primeira/candidatura");
        $this->postarJson($this->client, self::VAGAS . "/$segunda/candidatura");
        $minhas = $this->lista()['minhas'];
        self::assertSame([$segunda, $primeira], array_column($minhas, 'codigoVaga'), 'a mais recente primeiro');
        self::assertSame(['recebida', 1], [$minhas[0]['status'], $minhas[0]['importancia']['nivel']]);
        self::assertSame(2, $this->lista()['meu']['candidaturas']);
        $ids = array_column($minhas, 'id');
        self::assertSame(2, $this->postarJson($this->client, '/trabalho-externo/api/candidaturas/marcacoes', ['codigos' => [(string) $ids[0], $ids[1]], 'op' => 'estrela'])['aplicados']);
        self::assertTrue($this->lista()['minhas'][0]['estrela']);

        $this->logarComTenant($this->client, $doB, $b);
        self::assertSame(0, $this->postarJson($this->client, '/trabalho-externo/api/candidaturas/marcacoes', ['codigos' => $ids, 'op' => 'estrela'])['aplicados'], 'a candidatura de outra pessoa é ignorada');
        $this->postarJson($this->client, self::VAGAS . "/$primeira/candidatura");

        $this->logarComTenant($this->client, $master, $sede);
        self::assertSame(3, $this->contar('trabalho_externo_candidatura'));
        $this->postarJson($this->client, self::VAGAS . "/$segunda/excluir");
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->contar('trabalho_externo_candidatura'));
        self::assertSame(1, $this->contar("trabalho_externo_marcacao WHERE tipo = 'candidatura'"), 'a marcação da candidatura da vaga excluída sai junto');

        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, self::CURRICULOS . '/meu/excluir');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contar('trabalho_externo_candidatura'), 'excluir o currículo apaga as candidaturas dele');
        self::assertSame(0, $this->contar("trabalho_externo_marcacao WHERE tipo = 'candidatura'"));
    }

    #[TestDox('"Salvar e candidatar-se": salva o currículo e candidata (toast próprio); repetida e vaga fechada dão o toast ou o 422 da candidatura')]
    public function testSalvarCurriculoComCandidatarA(): void
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $master = $this->criarMaster($sede);
        $codigo = $this->publicar($master, $sede, self::vaga('Estagiário de Direito'));
        $fechada = $this->publicar($master, $sede, self::vaga('Assistente'));
        $this->postarJson($this->client, self::VAGAS . "/$fechada/acoes/encerrar");
        $doA = $this->criarUsuario($a);

        $this->logarComTenant($this->client, $doA, $a);
        $salvo = $this->postarJson($this->client, self::CURRICULOS . '/meu', self::curriculo('Ana Souza') + ['candidatarA' => $codigo]);
        self::assertResponseIsSuccessful();
        self::assertSame(['titulo' => 'Currículo salvo e candidatura enviada', 'texto' => 'Estagiário de Direito'], $salvo['toast']);
        self::assertSame('Ana Souza', $salvo['curriculo']['nome']);
        self::assertSame([$codigo], array_column($this->lista()['minhas'], 'codigoVaga'));
        $repetida = $this->postarJson($this->client, self::CURRICULOS . '/meu', self::curriculo('Ana Souza') + ['candidatarA' => $codigo]);
        self::assertSame('Candidatura já enviada', $repetida['toast']['titulo']);
        self::assertSame(['titulo' => 'Currículo salvo', 'texto' => 'Use-o para se candidatar às vagas.'], $this->postarJson($this->client, self::CURRICULOS . '/meu', self::curriculo('Ana Souza'))['toast']);
        $erro = $this->postarJson($this->client, self::CURRICULOS . '/meu', self::curriculo('Ana Lima') + ['candidatarA' => $fechada]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Inscrições encerradas', false, true], [$erro['titulo'], $erro['quase'], $erro['curriculoSalvo']]);
        self::assertSame('Ana Lima', $this->lista()['meu']['nome'], 'o currículo ficou salvo');
    }

    #[TestDox('vaga de escritório fora do ar: some da lista e do detalhe de quem é de fora e recusa candidatura; quem já se candidatou ainda a abre')]
    public function testVagaDeEscritorioForaDoAr(): void
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('Dono suspenso')];
        $master = $this->criarMaster($sede);
        $dono = $this->criarUsuario($a, nome: 'Dono');
        $codigo = $this->divulgarEAprovar($dono, $a, $master, $sede, 'Correspondente em Maceió');
        $jaCandidato = $this->comCurriculo($this->criarTenant('B'), 'Bia Lima');
        $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertResponseIsSuccessful();
        $outro = $this->comCurriculo($tenantC = $this->criarTenant('C'), 'Caio Reis');
        self::assertContains($codigo, array_column($this->listaDeVagas(), 'codigo'), 'premissa: no ar, a vaga aparece');

        $a = $this->em()->find(Tenant::class, $a->getId());
        $a->setIsActive(false);
        $this->em()->flush();

        $this->logarComTenant($this->client, $outro, $tenantC);
        self::assertNotContains($codigo, array_column($this->listaDeVagas(), 'codigo'));
        $this->client->request('GET', self::VAGAS . "/$codigo");
        self::assertResponseStatusCodeSame(404);
        $recusa = $this->postarJson($this->client, self::VAGAS . "/$codigo/candidatura");
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Vaga indisponível', 'Esta vaga não está recebendo candidaturas.'], [$recusa['titulo'], $recusa['mensagem']]);

        $this->logarComTenant($this->client, $jaCandidato, $this->em()->getRepository(\App\Entity\Auth\UserTenant::class)->findOneBy(['user' => $jaCandidato])->getTenant());
        self::assertSame('enviada', $this->detalhe($codigo)['botaoExterno'], 'quem se candidatou acompanha a vaga');
        self::assertSame(1, $this->contar('trabalho_externo_candidatura'));
    }

    /** @return list<array<string, mixed>> */
    private function listaDeVagas(): array
    {
        $this->client->request('GET', self::VAGAS);
        self::assertResponseIsSuccessful();

        return $this->json($this->client)['vagas']['vagas'];
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->instalarCsrf($this->client);
    }

    /** @param array<string, mixed> $vaga */
    private function publicar(User $master, Tenant $sede, array $vaga): string
    {
        $this->logarComTenant($this->client, $master, $sede);
        $codigo = $this->postarJson($this->client, self::VAGAS, $vaga)['codigo'];
        self::assertResponseIsSuccessful();

        return $codigo;
    }

    /** Vaga comum do externo, aprovada pelo Master com o tempo padrão da oferta desligado. */
    private function divulgarEAprovar(User $dono, Tenant $escritorio, User $master, Tenant $sede, string $titulo): string
    {
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, '/trabalho-externo/api/configuracao', ['vagas' => ['dur' => 0]]);
        $this->logarComTenant($this->client, $dono, $escritorio);
        $codigo = $this->postarJson($this->client, self::VAGAS, self::vaga($titulo))['codigo'];
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::VAGAS . "/$codigo/acoes/aprovar");
        self::assertResponseIsSuccessful();

        return $codigo;
    }

    private function comCurriculo(Tenant $tenant, string $nome): User
    {
        $usuario = $this->criarUsuario($tenant, nome: $nome);
        $this->logarComTenant($this->client, $usuario, $tenant);
        $this->postarJson($this->client, self::CURRICULOS . '/meu', self::curriculo($nome));
        self::assertResponseIsSuccessful();

        return $usuario;
    }

    /** O currículo em PDF do usuário logado. */
    private function anexarCurriculo(): void
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($caminho, "%PDF-1.4\n%%EOF\n");
        $this->client->request('POST', self::CURRICULOS . '/meu/arquivos/curriculo', [], ['arquivo' => new UploadedFile($caminho, 'cv.pdf', 'application/pdf', null, true)], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function detalhe(string $codigo): array
    {
        $this->client->request('GET', self::VAGAS . "/$codigo");
        self::assertResponseIsSuccessful();

        return $this->json($this->client)['vaga'];
    }

    /** @return array<string, mixed> */
    private function cartao(string $codigo): array
    {
        $this->client->request('GET', self::VAGAS);

        return array_values(array_filter($this->json($this->client)['vagas']['vagas'], static fn (array $v): bool => $v['codigo'] === $codigo))[0];
    }

    /** @return array<string, mixed> */
    private function curriculoPorCodigo(string $codigo): array
    {
        $this->client->request('GET', self::CURRICULOS . "/$codigo");
        self::assertResponseIsSuccessful();

        return $this->json($this->client)['curriculo'];
    }

    /** @return array<string, mixed> */
    private function lista(): array
    {
        $this->client->request('GET', self::CURRICULOS);
        self::assertResponseIsSuccessful();

        return $this->json($this->client);
    }

    private function contar(string $tabela): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela);
    }

    private function semTalentos(): void
    {
        $repo = static::getContainer()->get(ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        $cfg->definirEquipe('talentos', false);
        $repo->salvar($cfg, flush: true);
    }

    /** @return array<string, mixed> */
    private static function vaga(string $titulo): array
    {
        return ['titulo' => $titulo, 'cargo' => 'Advogado(a)', 'contratacao' => 'CLT', 'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Cível'], 'ofertante' => ['nome' => 'Escritório que oferece']];
    }

    /** @return array<string, mixed> */
    private static function curriculo(string $nome): array
    {
        return [
            'nome' => $nome, 'email' => 'cv@exemplo.test', 'tel' => '(61) 99999-0000', 'situacao' => 'adv',
            'oabNum' => '12345', 'oabUf' => 'DF', 'cargo' => 'Advogado(a)', 'cidade' => 'Brasília', 'uf' => 'DF',
            'areas' => ['Cível'], 'resumo' => 'Atuação cível.', 'lgpd' => true,
        ];
    }
}
