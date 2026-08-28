<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraController;
use App\Cobranca\Entity\Carteira;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O aviso "até quando os dados estão em dia" no topo da carteira (`cobranca_carteira_show`).
 *
 * Existe por um defeito achado no smoke de 10/08: as três carteiras de dados reais — 81, 121 e 51
 * unidades, R$ 663 mil em aberto — exibiam **"Nenhum relatório importado ainda"**. A frase é FALSA e
 * aparece justamente no lugar da tela que existe para responder "posso confiar neste saldo?".
 *
 * A causa não é o mecanismo (os 4 comandos gravam a emissão corretamente): é que o dado já estava no
 * banco antes da funcionalidade existir, e a migração só criou a coluna. Ou seja, "sem emissão
 * gravada" NÃO quer dizer "nada foi importado" — são dois estados diferentes que a tela juntava numa
 * mensagem só, escolhendo a que mente.
 *
 * São TRÊS estados, e este teste tranca os três.
 */
#[CoversClass(CarteiraController::class)]
final class CarteiraDadosAtualizadosNaTelaTest extends CobrancaWebTestCase
{
    private function carteira(Tenant $tenant, bool $comUnidade): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();

        if ($comUnidade) {
            $objeto = ObjetoCobrancaFactory::createOne([
                'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => 'QUADRA 07 CHACARA 01/02',
            ])->_real();
            CasoCobrancaFactory::createOne([
                'tenant' => $tenant,
                'objeto' => $objeto,
                'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Devedor Teste']),
            ]);
        }

        return $carteira;
    }

    #[TestDox('carteira VAZIA e sem emissão: "nenhum relatório importado ainda" é verdade')]
    public function testCarteiraVaziaDizQueNadaFoiImportado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant, comUnidade: false);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhum relatório importado ainda', $crawler->filter('.content-header')->text());
    }

    #[TestDox('carteira COM unidades e sem emissão: não pode dizer que nada foi importado')]
    public function testCarteiraComDadosNaoDizQueNadaFoiImportado(): void
    {
        // O defeito medido no smoke. A carteira tem unidade — dizer "nenhum relatório importado ainda"
        // contradiz a própria lista logo abaixo.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant, comUnidade: true);

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        $topo = $crawler->filter('.content-header')->text();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Nenhum relatório importado ainda', $topo);
        // Diz o que de fato se sabe: a data é desconhecida, e como passa a aparecer.
        self::assertStringContainsString('Data dos relatórios desconhecida', $topo);
    }

    #[TestDox('com emissão gravada: mostra a data e o quanto faz')]
    public function testComEmissaoMostraADataEOAtraso(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant, comUnidade: true);

        $carteira->registrarEmissaoImportada('inadimplencia', new \DateTimeImmutable('-3 days'));
        $carteira->registrarEmissaoImportada('receitas', new \DateTimeImmutable('-1 day'));
        static::getContainer()->get('doctrine')->getManager()->flush();

        $crawler = $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        $topo = $crawler->filter('.content-header')->text();

        self::assertResponseIsSuccessful();
        // O elo MAIS FRACO manda: a carteira é a soma dos relatórios, então vale a emissão mais antiga.
        self::assertStringContainsString('Dados atualizados até', $topo);
        self::assertStringContainsString((new \DateTimeImmutable('-3 days'))->format('d/m/Y'), $topo);
        // Texto do desenho 1B. A CONTA continua a mesma (instante contra instante); só a frase mudou.
        self::assertStringContainsString('há 3 dias', $topo);
        self::assertStringNotContainsString('Nenhum relatório importado ainda', $topo);
        self::assertStringNotContainsString('Data dos relatórios desconhecida', $topo);
    }
}
