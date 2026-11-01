<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CarteiraExportacaoController;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\ListaGrandeDemaisParaExportarException;
use App\Cobranca\Service\ListaCarteiraXlsxExporter;
use App\Cobranca\UseCase\ListarCasosParaExportarCarteiraUseCase;
use App\Cobranca\UseCase\MontarVisaoCarteiraUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Exportar lista (.xlsx)" do ⋮ da carteira (desenho Carteira 1B, L75-84).
 *
 * Prova o que a planilha promete: baixa como .xlsx com nome sem dado pessoal; abre; traz as colunas
 * da tela e só elas (nada de CPF/e-mail que a tela não mostra); valores em número; respeita busca e
 * Estado e ignora a paginação; e tem a MESMA guarda da tela (outro escritório 404, sem módulo o mesmo
 * redirect da tela).
 */
#[CoversClass(CarteiraExportacaoController::class)]
#[CoversClass(ListarCasosParaExportarCarteiraUseCase::class)]
#[CoversClass(ListaCarteiraXlsxExporter::class)]
final class CarteiraExportarListaTest extends CobrancaWebTestCase
{
    private const CPF = '52998224725';
    private const EMAIL = 'ana.devedora@exemplo.com.br';

    /**
     * Carteira com um caso ATIVO (com saldo e com CPF/e-mail na pessoa) e um JUDICIALIZADO.
     */
    private function carteira(Tenant $tenant): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant, 'nomeCompleto' => 'Condominio Credor Secreto']);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente, 'rotuloObjeto' => 'Unidade'])->_real();

        $ana = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Ana Ativa'])->_real();
        $ana->setCpf(self::CPF);
        $ana->setEmail(self::EMAIL);

        $objetoA = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => '05-03', 'descricao' => 'Bloco B',
        ])->_real();
        $casoA = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objetoA, 'pessoaCobradaAtual' => $ana, 'status' => StatusCaso::Ativo,
        ])->_real();
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $casoA, 'valorOriginal' => 10050, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('-40 days'),
        ]);
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $casoA, 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('+40 days'),
        ]);

        $objetoB = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => '07-01',
        ])->_real();
        CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objetoB,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Bruno Judicial']),
            'status' => StatusCaso::Judicializado,
        ]);

        static::getContainer()->get('doctrine')->getManager()->flush();

        return $carteira;
    }

    private function planilha(KernelBrowser $client): Worksheet
    {
        // StreamedResponse: o conteúdo só existe na resposta interna do browser de teste.
        $arquivo = tempnam(sys_get_temp_dir(), 'carteira_xlsx_');
        self::assertIsString($arquivo);
        file_put_contents($arquivo, (string) $client->getInternalResponse()->getContent());

        try {
            return IOFactory::load($arquivo)->getActiveSheet();
        } finally {
            unlink($arquivo);
        }
    }

    /**
     * @return list<list<mixed>>
     */
    private function linhas(Worksheet $aba): array
    {
        return array_values(array_map('array_values', $aba->toArray(null, false, false, false)));
    }

    #[TestDox('Baixa um .xlsx com o tipo certo e nome sem dado pessoal')]
    public function testBaixaComTipoENomeCertos(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $nome = sprintf('carteira-%d-%s.xlsx', $carteira->getId(), (new \DateTimeImmutable('today'))->format('Y-m-d'));
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=' . $nome);

        $disposicao = (string) $client->getInternalResponse()->getHeader('Content-Disposition');
        self::assertStringNotContainsStringIgnoringCase('Credor', $disposicao);
        self::assertStringNotContainsStringIgnoringCase('Ana', $disposicao);
    }

    #[TestDox('A planilha abre e traz as colunas da tela, com saldo e vencido em número')]
    public function testPlanilhaTrazAsColunasDaTela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar');
        self::assertResponseIsSuccessful();
        $aba = $this->planilha($client);
        $linhas = $this->linhas($aba);

        self::assertSame(
            ['Unidade', 'Descrição', 'Pessoa cobrada', 'Estado', 'Pronto para encerrar', 'Saldo (R$)', 'Vencido (R$)', 'Tem documentos'],
            $linhas[0],
            'O cabeçalho usa o rótulo do objeto da carteira, como a tabela da tela',
        );
        self::assertCount(3, $linhas, 'Cabeçalho + os dois casos');

        // Ordem padrão da tela: saldo decrescente. O caso com saldo vem primeiro.
        self::assertSame(['05-03', 'Bloco B', 'Ana Ativa', 'Ativo', 'Não'], array_slice($linhas[1], 0, 5));
        self::assertEqualsWithDelta(300.50, $linhas[1][5], 0.001, 'Saldo exigível em reais');
        self::assertEqualsWithDelta(100.50, $linhas[1][6], 0.001, 'Só a obrigação vencida');
        self::assertSame('Não', $linhas[1][7]);

        self::assertSame('07-01', $linhas[2][0]);
        self::assertSame('Bruno Judicial', $linhas[2][2]);
        self::assertSame('Judicializado', $linhas[2][3]);

        // Número de verdade (a planilha soma e filtra), e identificação como texto (não vira data).
        self::assertSame(DataType::TYPE_NUMERIC, $aba->getCell('F2')->getDataType());
        self::assertSame(DataType::TYPE_NUMERIC, $aba->getCell('G2')->getDataType());
        self::assertSame(DataType::TYPE_STRING, $aba->getCell('A2')->getDataType());
    }

    #[TestDox('Nenhum CPF, e-mail ou nome do credor entra na planilha')]
    public function testSemDadoPessoalQueATelaNaoMostra(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar');
        self::assertResponseIsSuccessful();
        $texto = json_encode($this->linhas($this->planilha($client)), \JSON_UNESCAPED_UNICODE);
        self::assertIsString($texto);

        self::assertStringContainsString('Ana Ativa', $texto, 'O nome da pessoa cobrada a tela mostra');
        self::assertStringNotContainsString(self::CPF, $texto);
        self::assertStringNotContainsString('529.982.247-25', $texto);
        self::assertStringNotContainsString(self::EMAIL, $texto);
        self::assertStringNotContainsString('@', $texto);
    }

    #[TestDox('Respeita a busca e o filtro de Estado da tela')]
    public function testRespeitaBuscaEEstado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);
        $url = '/cobrancas/carteiras/' . $carteira->getId() . '/exportar';

        $client->request('GET', $url . '?busca=bruno');
        self::assertResponseIsSuccessful();
        $linhas = $this->linhas($this->planilha($client));
        self::assertCount(2, $linhas);
        self::assertSame('Bruno Judicial', $linhas[1][2]);

        $client->request('GET', $url . '?estado=ativo');
        self::assertResponseIsSuccessful();
        $linhas = $this->linhas($this->planilha($client));
        self::assertCount(2, $linhas);
        self::assertSame('Ana Ativa', $linhas[1][2]);

        $client->request('GET', $url . '?estado=vencidos');
        self::assertResponseIsSuccessful();
        $linhas = $this->linhas($this->planilha($client));
        self::assertCount(2, $linhas, 'Só com atraso: só o caso com obrigação vencida');
        self::assertSame('05-03', $linhas[1][0]);

        $client->request('GET', $url . '?ordenar=objeto&direcao=desc');
        self::assertResponseIsSuccessful();
        $linhas = $this->linhas($this->planilha($client));
        self::assertSame(['07-01', '05-03'], [$linhas[1][0], $linhas[2][0]], 'Mesma ordenação da tela');
    }

    #[TestDox('Leva a lista INTEIRA, sem a paginação de 20 da tela')]
    public function testIgnoraAPaginacao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();
        for ($i = 1; $i <= 22; ++$i) {
            CasoCobrancaFactory::createOne([
                'tenant' => $tenant,
                'objeto' => ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => 'U-' . $i]),
                'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            ]);
        }

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar?page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(23, $this->linhas($this->planilha($client)), 'Cabeçalho + 22 casos, mesmo pedindo a página 2');
    }

    #[TestDox('Carteira de outro escritório é 404')]
    public function testOutroTenantE404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $carteiraAlheia = $this->carteira($this->tenantAvulso());

        $client->request('GET', '/cobrancas/carteiras/' . $carteiraAlheia->getId() . '/exportar');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem o módulo de Cobranças, o mesmo redirect com aviso que a tela da carteira devolve')]
    public function testSemModuloTemAMesmaRespostaDaTela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarUsuarioSemModulo($client);
        $carteira = $this->carteira($tenant);

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId());
        self::assertResponseRedirects('/inicio');
        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar');
        self::assertResponseRedirects('/inicio');
        self::assertResponseHeaderNotSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[TestDox('Quem só lê a carteira também exporta (mesma guarda da tela)')]
    public function testLeitorExporta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        $carteira = $this->carteira($tenant);

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->linhas($this->planilha($client)));
    }

    #[TestDox('O teto conta as linhas do RECORTE, não a carteira inteira')]
    public function testTetoContaORecorte(): void
    {
        self::assertSame(5000, ListarCasosParaExportarCarteiraUseCase::LIMITE_LINHAS);

        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);

        // Teto reduzido para 1 (a carteira tem 2 casos): o mesmo UseCase, com o teto injetado.
        $container = static::getContainer();
        $container->set(
            ListarCasosParaExportarCarteiraUseCase::class,
            new ListarCasosParaExportarCarteiraUseCase($container->get(MontarVisaoCarteiraUseCase::class), 1),
        );

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar?estado=ativo');
        // A carteira tem 2 casos (acima do teto 1), mas o Estado "ativo" deixa 1: dentro do teto.
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[TestDox('Acima do teto: redirect para a carteira com o mesmo recorte e o aviso, sem planilha')]
    public function testAcimaDoTetoRedirecionaComAviso(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = $this->carteira($tenant);

        $container = static::getContainer();
        $container->set(
            ListarCasosParaExportarCarteiraUseCase::class,
            new ListarCasosParaExportarCarteiraUseCase($container->get(MontarVisaoCarteiraUseCase::class), 1),
        );

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar?busca=a&ordenar=objeto&direcao=asc');

        self::assertResponseRedirects('/cobrancas/carteiras/' . $carteira->getId() . '?busca=a&ordenar=objeto&direcao=asc');
        self::assertResponseHeaderNotSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'A lista tem mais de 1 linhas. Use a busca ou o filtro de Estado para exportar em partes.',
            $crawler->text(),
        );
    }

    #[TestDox('A mensagem do teto de produção diz 5.000, como pedido')]
    public function testMensagemDoTetoDeProducao(): void
    {
        self::assertSame(
            'A lista tem mais de 5.000 linhas. Use a busca ou o filtro de Estado para exportar em partes.',
            (new ListaGrandeDemaisParaExportarException(ListarCasosParaExportarCarteiraUseCase::LIMITE_LINHAS))->getMessage(),
        );
    }

    #[TestDox('Rótulo do objeto vazio cai em "Objeto", como na tela')]
    public function testRotuloVazioViraObjeto(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant, 'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]), 'rotuloObjeto' => '',
        ])->_real();

        $client->request('GET', '/cobrancas/carteiras/' . $carteira->getId() . '/exportar');

        self::assertResponseIsSuccessful();
        self::assertSame('Objeto', $this->linhas($this->planilha($client))[0][0]);
    }
}
