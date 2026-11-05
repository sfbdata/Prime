<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Auditoria 1.17: o sino e o Ver todas reaplicavam só o cadeado. Aviso antigo de pasta que a pessoa deixou de
 * ler (permissão, sem cadeado) ou com a aba Financeiro oculta depois da menção seguia com a prévia do texto.
 * Agora a leitura refaz a pergunta da Timeline da pasta, e o selo conta o mesmo que a lista mostra.
 */
#[Group('notificacao')]
final class SinoReaplicaALeituraDaPastaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    private function aviso(User $para, Tenant $tenant, string $titulo, ?string $url, string $tipo = Notificacao::TIPO_PASTA_MENCAO_REGISTRO): void
    {
        $n = new Notificacao();
        $n->setUsuario($para);
        $n->setTenant($tenant);
        $n->setTipo($tipo);
        $n->setTitulo($titulo);
        $n->setMensagem('PREVIA ' . $titulo);
        $n->setUrl($url);
        $this->em()->persist($n);
        $this->em()->flush();
    }

    /** @return array{0: string, 1: string, 2: int} Ver todas, HTML do sino e contagem do selo */
    private function telas(): array
    {
        $todas = (string) $this->client->request('GET', '/notificacoes')->html();
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/notificacoes/lista-dropdown');
        self::assertResponseIsSuccessful();
        $sino = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->client->request('GET', '/notificacoes/count');
        self::assertResponseIsSuccessful();
        $selo = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return [$todas, (string) $sino['html'], (int) $selo['count']];
    }

    #[TestDox('Aba Financeiro oculta depois da menção: o aviso #financeiro some do sino, do Ver todas e do selo; o de Detalhes e o de outra pasta ficam')]
    public function testFinanceiroOcultoTiraOAvisoFinanceiro(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $a = $this->criarPasta($tenant);
        $b = $this->criarPasta($tenant);
        $this->aviso($ana, $tenant, 'OBS FINANCEIRA A', '/pasta/' . $a->getId() . '#financeiro-obs-1');
        $this->aviso($ana, $tenant, 'OBS FINANCEIRA A COM QUERY', '/pasta/' . $a->getId() . '?aba=1#financeiro-obs-3');
        $this->aviso($ana, $tenant, 'OBS DETALHES A', '/pasta/' . $a->getId() . '#detalhes-obs-2');
        $this->aviso($ana, $tenant, 'OBS FINANCEIRA B', '/pasta/' . $b->getId() . '#financeiro-obs-4');
        // A regra vem depois dos avisos.
        $this->em()->persist(AbaOcultaDaPasta::paraAlguns($tenant, $a, AbaDaPasta::Financeiro, [(int) $ana->getId()], $primario, new \DateTimeImmutable()));
        $this->em()->flush();

        $this->entrar($ana, $tenant);
        [$todas, $sino, $selo] = $this->telas();

        foreach (['Ver todas' => $todas, 'sino' => $sino] as $onde => $tela) {
            self::assertStringNotContainsString('OBS FINANCEIRA A', $tela, $onde);
            self::assertStringContainsString('OBS DETALHES A', $tela, $onde);
            self::assertStringContainsString('OBS FINANCEIRA B', $tela, $onde);
        }
        self::assertSame(2, $selo, 'o selo conta só o que a lista mostra');
    }

    #[TestDox('Sem permissão de ver a pasta (sem cadeado): o aviso ligado a /pasta/X some e não conta; aviso sem pasta e resposta ao pedido ficam')]
    public function testSemLeituraDaPastaTiraOAviso(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $beto = $this->criarColaborador($tenant, 'Beto Sem Pasta', ['modules.expediente.view']);
        $x = $this->criarPasta($tenant);
        $this->aviso($beto, $tenant, 'MENCAO NA PASTA X', '/pasta/' . $x->getId() . '#detalhes-obs-1');
        $this->aviso($beto, $tenant, 'DOCUMENTO DA PASTA X', '/pasta/' . $x->getId() . '/documentos');
        $this->aviso($beto, $tenant, 'AVISO DA AGENDA', '/agenda');
        $this->aviso($beto, $tenant, 'RESPOSTA AO PEDIDO', '/pasta/' . $x->getId(), Notificacao::TIPO_PASTA_ACESSO_DECIDIDO);

        $this->entrar($beto, $tenant);
        [$todas, $sino, $selo] = $this->telas();

        foreach (['Ver todas' => $todas, 'sino' => $sino] as $onde => $tela) {
            self::assertStringNotContainsString('PASTA X', $tela, $onde);
            self::assertStringContainsString('AVISO DA AGENDA', $tela, $onde);
            self::assertStringContainsString('RESPOSTA AO PEDIDO', $tela, $onde);
        }
        self::assertSame(2, $selo);
    }

    #[TestDox('Quem vê a pasta e o Financeiro (colega e primário) continua recebendo os mesmos avisos')]
    public function testQuemVeContinuaIgual(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant);
        $a = $this->criarPasta($tenant);
        $this->em()->persist(AbaOcultaDaPasta::paraAlguns($tenant, $a, AbaDaPasta::Financeiro, [(int) $ana->getId()], $primario, new \DateTimeImmutable()));
        $this->em()->flush();
        $carla = $this->criarColaborador($tenant, 'Carla Vê');

        foreach ([$carla, $primario] as $quem) {
            $this->aviso($quem, $tenant, 'OBS FINANCEIRA A', '/pasta/' . $a->getId() . '#financeiro-obs-1');
        }

        foreach ([$carla, $primario] as $quem) {
            $this->entrar($quem, $tenant);
            [$todas, $sino, $selo] = $this->telas();

            self::assertStringContainsString('OBS FINANCEIRA A', $todas);
            self::assertStringContainsString('OBS FINANCEIRA A', $sino);
            self::assertSame(1, $selo);
        }
    }
}
