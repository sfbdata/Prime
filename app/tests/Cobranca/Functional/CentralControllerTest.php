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

    #[TestDox('A rota de detalhe responde 200, lista os eventos da pessoa e as pastilhas de desfecho')]
    public function testDetalheDaPessoa(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:00:00'), ['resultado' => 'caixa_postal'], 'ligacao caiu na caixa postal');
        $this->evento($tenant, $caso, $user, TipoEventoHistorico::ContatoRealizado, $this->hoje('09:30:00'), ['resultado' => 'atendido'], 'falou com o devedor');

        $client->request('GET', '/cobrancas/central/atividade/' . $user->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ligacao caiu na caixa postal', $html);
        self::assertStringContainsString('falou com o devedor', $html);
        self::assertStringContainsString('Caixa postal', $html);
        self::assertStringContainsString('Atendido', $html);
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
