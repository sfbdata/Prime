<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CentralController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\ResultadoContato;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Camada HTTP da Central de Acompanhamento — Fatia 1 (spec §10). Cobre o gate de módulo, o render da
 * tabela, o ISOLAMENTO CROSS-TENANT das contagens, o anti-IDOR dos dois ids que a tela aceita do cliente
 * (carteira e usuário), o filtro de período e a rota de detalhe. Só GET/leitura — sem CSRF.
 */
#[CoversClass(CentralController::class)]
final class CentralControllerTest extends CobrancaWebTestCase
{
    /**
     * Grava um evento direto na tabela: o objetivo aqui é a LEITURA da central, e passar pelos UseCases
     * de escrita arrastaria regras que não são o alvo do teste (caso encerrado, acordo válido…).
     *
     * @param array<string, mixed>|null $dados
     */
    private function evento(
        Tenant $tenant,
        CasoCobranca $caso,
        ?User $usuario,
        TipoEventoHistorico $tipo,
        string $ocorridoEm,
        ?array $dados = null,
        string $descricao = 'evento de teste',
    ): void {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $evento = new EventoHistorico();
        $evento->setTenant($tenant);
        $evento->setCaso($caso);
        $evento->setUsuario($usuario);
        $evento->setTipo($tipo);
        $evento->setOcorridoEm(new \DateTimeImmutable($ocorridoEm));
        $evento->setDescricao($descricao);
        $evento->setDados($dados);

        $em->persist($evento);
        $em->flush();
    }

    private function contato(Tenant $tenant, CasoCobranca $caso, ?User $usuario, string $ocorridoEm, ResultadoContato $resultado): void
    {
        $this->evento($tenant, $caso, $usuario, TipoEventoHistorico::ContatoRealizado, $ocorridoEm, ['resultado' => $resultado->value]);
    }

    /**
     * Usuário real, vinculado a OUTRO escritório — sem logar (um kernel, um client por teste). É o id
     * que a rota de detalhe tem de recusar.
     */
    private function usuarioDeOutroEscritorio(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->tenantAvulso();

        $user = new User();
        $user->setEmail('central_alheio_' . uniqid() . '@test.com');
        $user->setFullName('Fulano De Outro Escritorio');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $em->persist($vinculo);
        $em->flush();

        return $user;
    }

    /**
     * Pastilhas do detalhe como mapa rótulo → quantidade. Existe para os testes asserirem o NÚMERO:
     * o rótulo sozinho não prova nada, porque a régua de desfechos é renderizada inteira, mesmo zerada.
     *
     * @return array<string, int>
     */
    private function pastilhas(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        $mapa = [];
        foreach ($crawler->filter('.cobranca-pastilha') as $no) {
            $pastilha = new \Symfony\Component\DomCrawler\Crawler($no);
            $quantidade = trim($pastilha->filter('strong')->text());
            $rotulo = trim(str_replace($quantidade, '', $pastilha->text()));

            $mapa[$rotulo] = (int) $quantidade;
        }

        return $mapa;
    }

    private function hoje(string $hora = '10:00:00'): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d') . ' ' . $hora;
    }

    #[TestDox('GET /cobrancas/central sem autenticação redireciona para login')]
    public function testSemAutenticacao(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cobrancas/central');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /cobrancas/central sem o módulo cobrancas é bloqueado (redirect, não 200)')]
    public function testSemModulo(): void
    {
        $client = static::createClient();
        $this->criarUsuarioSemModulo($client);

        $client->request('GET', '/cobrancas/central');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /cobrancas/central com o módulo responde 200 com as 4 abas (3 em construção)')]
    public function testRenderComAsQuatroAbas(): void
    {
        $client = static::createClient();
        $this->criarOperadorSemCapacidade($client);

        $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Atividade', $html);
        self::assertStringContainsString('Resultado', $html);
        self::assertStringContainsString('Pendências', $html);
        self::assertStringContainsString('Extrato do devedor', $html);
        self::assertStringContainsString('em construção', $html);
    }

    #[TestDox('A tabela conta contatos, atendidos, acordos e baixas da pessoa')]
    public function testContagensNaTabela(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->contato($tenant, $caso, $user, $this->hoje('09:00:00'), ResultadoContato::Atendido);
        $this->contato($tenant, $caso, $user, $this->hoje('09:30:00'), ResultadoContato::Atendido);
        $this->contato($tenant, $caso, $user, $this->hoje('10:00:00'), ResultadoContato::NaoAtendido);
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::AcordoCriado, $this->hoje('11:00:00'));
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::PagamentoRegistrado, $this->hoje('12:00:00'));
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::LiquidacaoRegistrada, $this->hoje('13:00:00'));

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td');
        self::assertSame('3', trim($linha->eq(0)->text()), 'contatos');
        self::assertSame('2', trim($linha->eq(1)->text()), 'falou com o devedor');
        self::assertSame('1', trim($linha->eq(2)->text()), 'acordos fechados');
        self::assertSame('2', trim($linha->eq(3)->text()), 'baixas registradas');
    }

    #[TestDox('Quem tem o módulo mas não registrou nada aparece ZERADO, não some da lista')]
    public function testUsuarioSemAtividadeApareceZerado(): void
    {
        $client = static::createClient();
        [$user] = $this->criarOperadorSemCapacidade($client);

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"]');
        self::assertCount(1, $linha, 'o usuário sem atividade tem de continuar na tabela');
        self::assertSame('0', trim($linha->filter('td')->eq(0)->text()));
    }

    #[TestDox('ISOLAMENTO: evento de outro escritório não entra em nenhuma contagem')]
    public function testIsolamentoCrossTenant(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $casoProprio] = $this->semearGrafo($tenant);
        $this->contato($tenant, $casoProprio, $user, $this->hoje('09:00:00'), ResultadoContato::Atendido);

        // Mesmo USUÁRIO, escritório diferente: nada dali pode entrar na conta desta tela.
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        for ($i = 0; $i < 5; ++$i) {
            $this->contato($outroTenant, $casoAlheio, $user, $this->hoje('14:0' . $i . ':00'), ResultadoContato::Atendido);
        }

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td');
        self::assertSame('1', trim($linha->eq(0)->text()), 'só o contato do próprio escritório conta');
        self::assertSame('1', trim($crawler->filter('.cobranca-central-total td')->eq(0)->text()), 'o total do setor também não vaza');
    }

    #[TestDox('ISOLAMENTO: o detalhe não lista eventos de outro escritório')]
    public function testDetalheNaoVazaEventosDeOutroTenant(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $casoProprio] = $this->semearGrafo($tenant);
        $this->evento($tenant, $casoProprio, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['resultado' => 'atendido'], 'contato deste escritorio');

        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $this->evento($outroTenant, $casoAlheio, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:30:00'), ['resultado' => 'atendido'], 'contato do outro escritorio');

        $client->request('GET', '/cobrancas/central/atividade/' . $user->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('contato deste escritorio', $html);
        self::assertStringNotContainsString('contato do outro escritorio', $html);
    }

    #[TestDox('IDOR: filtro por carteira de OUTRO tenant devolve 404, não lista vazia')]
    public function testIdorCarteira404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [$carteiraAlheia] = $this->semearGrafo($this->tenantAvulso());

        $client->request('GET', '/cobrancas/central', ['carteira' => (string) $carteiraAlheia->getId()]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: detalhe de usuário de OUTRO escritório devolve 404 (não vaza o nome dele)')]
    public function testIdorUsuarioDeOutroTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $client->request('GET', '/cobrancas/central/atividade/' . $this->usuarioDeOutroEscritorio()->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: o detalhe também recusa carteira de outro tenant')]
    public function testIdorCarteiraNoDetalhe404(): void
    {
        $client = static::createClient();
        [$user] = $this->criarAdminLogado($client);
        [$carteiraAlheia] = $this->semearGrafo($this->tenantAvulso());

        $client->request('GET', '/cobrancas/central/atividade/' . $user->getId(), ['carteira' => (string) $carteiraAlheia->getId()]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('O filtro de período reflete na tabela: evento de ontem não entra no recorte de hoje')]
    public function testFiltroDePeriodo(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $ontem = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $this->contato($tenant, $caso, $user, $ontem . ' 10:00:00', ResultadoContato::Atendido);
        $this->contato($tenant, $caso, $user, $this->hoje('10:00:00'), ResultadoContato::Atendido);

        $crawler = $client->request('GET', '/cobrancas/central', ['periodo' => 'hoje']);
        self::assertSame('1', trim($crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td')->eq(0)->text()));

        $crawler = $client->request('GET', '/cobrancas/central', ['periodo' => 'ontem']);
        self::assertSame('1', trim($crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td')->eq(0)->text()));

        // "Este mês" contém os dois — salvo quando hoje é dia 1º e o de ontem caiu no mês passado.
        $crawler = $client->request('GET', '/cobrancas/central', ['periodo' => 'mes']);
        $esperado = (new \DateTimeImmutable('today'))->format('j') === '1' ? '1' : '2';
        self::assertSame($esperado, trim($crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td')->eq(0)->text()));
    }

    #[TestDox('B2: data impossível NÃO é reinterpretada em silêncio — cai no padrão (hoje)')]
    public function testDataInvalidaCaiNoPadrao(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $hoje = (new \DateTimeImmutable('today'))->format('Y-m-d');

        // `2026-13-45` não existe. Com `createFromFormat` sem checagem de erro, o PHP "corrige" para
        // 2027-02-14 e o gestor lê um relatório de um período que ele não pediu — pior que recusar.
        $crawler = $client->request('GET', '/cobrancas/central', [
            'periodo' => 'personalizado',
            'inicio' => '2026-13-45',
            'fim' => '2026-02-31',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($hoje, $crawler->filter('#filtroInicio')->attr('value'), 'inicio impossivel tem de cair no padrao');
        self::assertSame($hoje, $crawler->filter('#filtroFim')->attr('value'), 'fim impossivel tem de cair no padrao');
    }

    #[TestDox('B2: com uma data válida e outra impossível, só a impossível cai no padrão')]
    public function testDataParcialmenteInvalida(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $hoje = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $crawler = $client->request('GET', '/cobrancas/central', [
            'periodo' => 'personalizado',
            'inicio' => '2026-02-10',
            'fim' => '2026-13-45',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('2026-02-10', $crawler->filter('#filtroInicio')->attr('value'), 'a data valida e preservada');
        self::assertSame($hoje, $crawler->filter('#filtroFim')->attr('value'), 'a impossivel cai no padrao');
    }

    #[TestDox('B2: data bem formada continua sendo respeitada')]
    public function testDataValidaEhRespeitada(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $crawler = $client->request('GET', '/cobrancas/central', [
            'periodo' => 'personalizado',
            'inicio' => '2026-02-10',
            'fim' => '2026-02-20',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('2026-02-10', $crawler->filter('#filtroInicio')->attr('value'));
        self::assertSame('2026-02-20', $crawler->filter('#filtroFim')->attr('value'));
    }

    #[TestDox('O filtro de carteira restringe as contagens à carteira escolhida')]
    public function testFiltroDeCarteira(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [$carteiraA, $casoA] = $this->semearGrafo($tenant);
        [, $casoB] = $this->semearGrafo($tenant);

        $this->contato($tenant, $casoA, $user, $this->hoje('09:00:00'), ResultadoContato::Atendido);
        $this->contato($tenant, $casoB, $user, $this->hoje('09:30:00'), ResultadoContato::Atendido);
        $this->contato($tenant, $casoB, $user, $this->hoje('10:00:00'), ResultadoContato::Atendido);

        $crawler = $client->request('GET', '/cobrancas/central', ['carteira' => (string) $carteiraA->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSame('1', trim($crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td')->eq(0)->text()));
    }

    #[TestDox('Evento sem usuário aparece na linha "Sem responsável", nunca atribuído a alguém')]
    public function testEventoOrfaoNaLinhaSemResponsavel(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->contato($tenant, $caso, null, $this->hoje('08:00:00'), ResultadoContato::NaoAtendido);
        $this->contato($tenant, $caso, $user, $this->hoje('09:00:00'), ResultadoContato::Atendido);

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $orfa = $crawler->filter('.js-linha-pessoa[data-chave="sem-responsavel"]');
        self::assertCount(1, $orfa);
        self::assertSame('1', trim($orfa->filter('td')->eq(0)->text()));
        self::assertSame('1', trim($crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td')->eq(0)->text()));
        self::assertSame('2', trim($crawler->filter('.cobranca-central-total td')->eq(0)->text()), 'o órfão entra no total do setor');
    }

    #[TestDox('A rota de detalhe responde 200, lista os eventos da pessoa e CONTA as pastilhas de desfecho')]
    public function testDetalheDaPessoa(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['resultado' => 'caixa_postal'], 'ligacao caiu na caixa postal');
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:30:00'), ['resultado' => 'atendido'], 'falou com o devedor');
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:45:00'), ['resultado' => 'atendido'], 'falou de novo');

        $crawler = $client->request('GET', '/cobrancas/central/atividade/' . $user->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ligacao caiu na caixa postal', $html);
        self::assertStringContainsString('falou com o devedor', $html);

        // B1: asserir a QUANTIDADE, não o rótulo. Os rótulos da régua fixa são renderizados sempre,
        // inclusive zerados — conferir só o texto passaria com a contagem vazia.
        $pastilhas = $this->pastilhas($crawler);
        self::assertSame(2, $pastilhas['Atendido'] ?? null);
        self::assertSame(1, $pastilhas['Caixa postal'] ?? null);
        self::assertSame(0, $pastilhas['Número errado'] ?? null, 'desfecho sem ocorrencia aparece zerado');
    }

    #[TestDox('B3: o detalhe COM carteira válida conta só os contatos daquela carteira')]
    public function testDetalheComCarteiraValida(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [$carteiraA, $casoA] = $this->semearGrafo($tenant);
        [, $casoB] = $this->semearGrafo($tenant);

        $this->evento($tenant, $casoA, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['resultado' => 'atendido'], 'contato da carteira A');
        $this->evento($tenant, $casoB, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:30:00'), ['resultado' => 'atendido'], 'contato da carteira B');
        $this->evento($tenant, $casoB, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:45:00'), ['resultado' => 'caixa_postal'], 'outro da carteira B');

        // Este é o caminho de duplo sprintf do repositório (desfechos + JOIN de carteira); sem este
        // teste, uma SQL malformada só apareceria em produção.
        $crawler = $client->request('GET', '/cobrancas/central/atividade/' . $user->getId(), [
            'carteira' => (string) $carteiraA->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $pastilhas = $this->pastilhas($crawler);
        self::assertSame(1, $pastilhas['Atendido'] ?? null, 'so o contato da carteira A conta');
        self::assertSame(0, $pastilhas['Caixa postal'] ?? null, 'o desfecho da carteira B nao entra');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('contato da carteira A', $html);
        self::assertStringNotContainsString('contato da carteira B', $html);
        self::assertStringNotContainsString('outro da carteira B', $html);
    }

    #[TestDox('O detalhe de "Sem responsável" abre e lista os eventos órfãos')]
    public function testDetalheSemResponsavel(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->evento($tenant, $caso, null, TipoEventoHistorico::ContatoRealizado, $this->hoje('08:00:00'), ['resultado' => 'nao_atendido'], 'contato sem autor registrado');

        $client->request('GET', '/cobrancas/central/atividade/sem-responsavel');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('contato sem autor registrado', (string) $client->getResponse()->getContent());
    }

    #[TestDox('O detalhe conta o legado prometeu_pagar quando ele existe no payload')]
    public function testDetalheComLegadoPrometeuPagar(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->contato($tenant, $caso, $user, $this->hoje('09:00:00'), ResultadoContato::PrometeuPagar);

        $client->request('GET', '/cobrancas/central/atividade/' . $user->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Prometeu pagar', (string) $client->getResponse()->getContent());
    }

    // ── §5.1: trabalho de cobrança × lançamento de cadastro/importação ──────────────────────────

    #[TestDox('§5.1: "Última ação" ignora importação — quem só lançou cadastro aparece com —')]
    public function testUltimaAcaoIgnoraLancamentoDeCadastro(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Só importação: 3 obrigações criadas e um caso aberto, nada de cobrança.
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::CasoAberto, $this->hoje('10:00:00'));
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('10:00:05'));
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('10:00:06'));

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td');
        self::assertSame('—', trim($linha->eq(4)->text()), 'importar planilha nao e cobrar ninguem');
        self::assertSame('—', trim($crawler->filter('.cobranca-central-total td')->eq(4)->text()));
    }

    #[TestDox('§5.1: período só de importação NÃO diz "nenhum registro" — diz nenhum trabalho')]
    public function testAvisoNaoMenteQuandoSoHouveImportacao(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('10:00:00'));
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::CasoAberto, $this->hoje('10:00:01'));

        $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Nenhum trabalho de cobrança registrado', $html);
        self::assertStringNotContainsString('Nenhum registro no período', $html, 'dizer "nenhum registro" com 2 lancamentos e mentira');
    }

    #[TestDox('§5.1: um contato depois da importação faz a "Última ação" apontar para o contato')]
    public function testUltimaAcaoUsaOTrabalhoMesmoQuandoAImportacaoEhPosterior(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->contato($tenant, $caso, $user, $this->hoje('08:15:00'), ResultadoContato::Atendido);
        // Importação MAIS RECENTE que o contato: sem o filtro por tipo, ela roubaria a "última ação".
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('23:50:00'));

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $linha = $crawler->filter('.js-linha-pessoa[data-chave="' . $user->getId() . '"] td');
        self::assertSame('08:15', trim($linha->eq(4)->text()));
    }

    #[TestDox('§5.1: o detalhe lista o trabalho e RESUME os lançamentos de cadastro, sem escondê-los')]
    public function testDetalheSeparaTrabalhoDeCadastro(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['resultado' => 'atendido'], 'trabalho de cobranca de verdade');
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('10:00:00'), null, 'lancamento vindo da planilha');
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('10:00:01'), null, 'outro lancamento da planilha');

        $crawler = $client->request('GET', '/cobrancas/central/atividade/' . $user->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('trabalho de cobranca de verdade', $html);
        self::assertStringNotContainsString('lancamento vindo da planilha', $html, 'cadastro nao entra na lista padrao');

        // Mas o número aparece: o §12 proíbe truncar calado, e isto é truncagem por tipo.
        $botao = $crawler->filter('.js-expandir-cadastro');
        self::assertCount(1, $botao);
        self::assertStringContainsString('2 lançamentos de cadastro/importação', trim($botao->text()));
    }

    #[TestDox('§5.1: expandir mostra os lançamentos de cadastro, no mesmo recorte de filtros')]
    public function testExpandirLancamentosDeCadastro(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [$carteiraA, $casoA] = $this->semearGrafo($tenant);
        [, $casoB] = $this->semearGrafo($tenant);

        $this->evento($tenant, $casoA, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('10:00:00'), null, 'lancamento da carteira A');
        $this->evento($tenant, $casoB, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('10:00:01'), null, 'lancamento da carteira B');

        $client->request('GET', '/cobrancas/central/atividade/' . $user->getId(), [
            'cadastro' => '1',
            'carteira' => (string) $carteiraA->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('lancamento da carteira A', $html);
        self::assertStringNotContainsString('lancamento da carteira B', $html, 'expandir respeita o filtro de carteira');
    }

    #[TestDox('§5.1: sem lançamento de cadastro no período, não aparece linha de resumo')]
    public function testDetalheSemCadastroNaoMostraResumo(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->contato($tenant, $caso, $user, $this->hoje('09:00:00'), ResultadoContato::Atendido);

        $crawler = $client->request('GET', '/cobrancas/central/atividade/' . $user->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.js-expandir-cadastro'));
    }

    #[TestDox('§5.1: o link de expandir carrega os filtros correntes da tela')]
    public function testLinkDeExpandirPreservaOsFiltros(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ObrigacaoCriada, $this->hoje('10:00:00'));

        $crawler = $client->request('GET', '/cobrancas/central/atividade/' . $user->getId(), [
            'periodo' => 'mes',
            'carteira' => (string) $carteira->getId(),
        ]);

        self::assertResponseIsSuccessful();
        $url = (string) $crawler->filter('.js-expandir-cadastro')->attr('data-url');
        self::assertStringContainsString('cadastro=1', $url);
        self::assertStringContainsString('periodo=mes', $url);
        self::assertStringContainsString('carteira=' . $carteira->getId(), $url);
    }

    // ── Canais (spec §4/§5): "quantos contatos e por qual meio" ─────────────────────────────────

    #[TestDox('A faixa de canais conta os contatos do setor por meio, respeitando os filtros')]
    public function testFaixaDeCanais(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        foreach ([['telefone', '09:00:00'], ['telefone', '09:05:00'], ['whatsapp', '09:10:00'], ['email', '09:15:00']] as [$canal, $hora]) {
            $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje($hora), [
                'canal' => $canal,
                'resultado' => 'nao_atendido',
            ]);
        }

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $faixa = $this->pastilhas($crawler->filter('.cobranca-central-canais'));
        self::assertSame(2, $faixa['Telefone'] ?? null);
        self::assertSame(1, $faixa['WhatsApp'] ?? null);
        self::assertSame(1, $faixa['E-mail'] ?? null);
        self::assertSame(0, $faixa['SMS'] ?? null, 'canal sem uso aparece zerado');
    }

    #[TestDox('A faixa de canais FECHA com o total de contatos da tabela (142 = 71+54+12+5)')]
    public function testFaixaDeCanaisFechaComOTotalDaTabela(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // Inclui um contato SEM canal e um evento órfão: os dois têm de entrar nas duas contas, senão
        // a faixa e a coluna "Contatos" passam a contar universos diferentes na mesma tela.
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['canal' => 'telefone']);
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:05:00'), ['canal' => 'sms']);
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:10:00'), ['resultado' => 'atendido']);
        $this->evento($tenant, $caso, null, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:15:00'), ['canal' => 'whatsapp']);

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        $somaDaFaixa = array_sum($this->pastilhas($crawler->filter('.cobranca-central-canais')));
        $totalDaTabela = (int) trim($crawler->filter('.cobranca-central-total td')->eq(0)->text());

        self::assertSame(4, $totalDaTabela);
        self::assertSame($totalDaTabela, $somaDaFaixa, 'a faixa e a coluna Contatos contam a mesma coisa');
        self::assertStringContainsString('4', $crawler->filter('.cobranca-central-canais-total')->text());
    }

    #[TestDox('A faixa de canais respeita o filtro de PERÍODO')]
    public function testFaixaDeCanaisRespeitaOPeriodo(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $ontem = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $ontem . ' 10:00:00', ['canal' => 'telefone']);
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('10:00:00'), ['canal' => 'telefone']);

        $crawler = $client->request('GET', '/cobrancas/central', ['periodo' => 'hoje']);
        self::assertSame(1, $this->pastilhas($crawler->filter('.cobranca-central-canais'))['Telefone'] ?? null);

        $crawler = $client->request('GET', '/cobrancas/central', ['periodo' => 'ontem']);
        self::assertSame(1, $this->pastilhas($crawler->filter('.cobranca-central-canais'))['Telefone'] ?? null);
    }

    #[TestDox('A faixa de canais respeita o filtro de CARTEIRA')]
    public function testFaixaDeCanaisRespeitaACarteira(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [$carteiraA, $casoA] = $this->semearGrafo($tenant);
        [, $casoB] = $this->semearGrafo($tenant);

        $this->evento($tenant, $casoA, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['canal' => 'telefone']);
        $this->evento($tenant, $casoB, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:05:00'), ['canal' => 'telefone']);
        $this->evento($tenant, $casoB, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:10:00'), ['canal' => 'whatsapp']);

        $crawler = $client->request('GET', '/cobrancas/central', ['carteira' => (string) $carteiraA->getId()]);

        self::assertResponseIsSuccessful();
        $faixa = $this->pastilhas($crawler->filter('.cobranca-central-canais'));
        self::assertSame(1, $faixa['Telefone'] ?? null);
        self::assertSame(0, $faixa['WhatsApp'] ?? null, 'contato da outra carteira nao entra na faixa');
    }

    #[TestDox('ISOLAMENTO: a faixa de canais não conta contato de outro escritório')]
    public function testFaixaDeCanaisNaoVazaOutroTenant(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $casoProprio] = $this->semearGrafo($tenant);
        $this->evento($tenant, $casoProprio, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['canal' => 'telefone']);

        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        for ($i = 0; $i < 4; ++$i) {
            $this->evento($outroTenant, $casoAlheio, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('14:0' . $i . ':00'), ['canal' => 'telefone']);
        }

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->pastilhas($crawler->filter('.cobranca-central-canais'))['Telefone'] ?? null);
    }

    #[TestDox('O detalhe mostra as pastilhas de canal da pessoa, separadas dos desfechos')]
    public function testDetalheComPastilhasDeCanal(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['canal' => 'whatsapp', 'resultado' => 'atendido']);
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:30:00'), ['canal' => 'whatsapp', 'resultado' => 'nao_atendido']);
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:45:00'), ['canal' => 'sms', 'resultado' => 'nao_atendido']);

        $crawler = $client->request('GET', '/cobrancas/central/atividade/' . $user->getId());

        self::assertResponseIsSuccessful();
        $pastilhas = $this->pastilhas($crawler);
        self::assertSame(2, $pastilhas['WhatsApp'] ?? null);
        self::assertSame(1, $pastilhas['SMS'] ?? null);
        self::assertSame(1, $pastilhas['Atendido'] ?? null);
        self::assertSame(2, $pastilhas['Não atendido'] ?? null);
        self::assertSame(0, $pastilhas['Telefone'] ?? null);
    }

    #[TestDox('Contato antigo sem canal no payload vira "Não informado", não some da conta')]
    public function testContatoSemCanalNoPayload(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['resultado' => 'atendido']);

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->pastilhas($crawler->filter('.cobranca-central-canais'))['Não informado'] ?? null);
    }

    #[TestDox('A central é leitura pura: nenhum formulário de escrita no conteúdo da tela')]
    public function testTelaEhSomenteLeitura(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);

        $crawler = $client->request('GET', '/cobrancas/central');

        self::assertResponseIsSuccessful();
        // Escopado ao conteúdo do módulo: o layout base traz forms próprios (logout, troca de escritório).
        self::assertCount(0, $crawler->filter('.cobrancas-page form[method="post"]'));
    }
}
