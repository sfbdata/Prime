<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerClientesFerramenta;
use App\Conector\Ferramenta\LerClientesPendencias;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_clientes`, ação `pendencias` (M9 de 26/11/2026, Fase 1, item 1), com o banco e o portão de verdade: numa chamada
 * só, os clientes sem CPF e as pastas deles; nenhum valor sensível no JSON; pasta em segredo de justiça fora e contada;
 * pasta trancada ou sem acesso fora sem contagem (como o `ler`); outro escritório nunca; paginação com o total do filtro;
 * a visão inversa (pastas ativas sem cliente).
 */
#[CoversClass(LerClientesPendencias::class)]
#[CoversClass(LerClientesFerramenta::class)]
#[CoversClass(PastaRepository::class)]
final class LerClientesPendenciasTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CPF = '529.982.247-25';
    private const CPF_DIGITOS = '52998224725';
    private const CNPJ_DIGITOS = '11222333000181';
    private const EMAIL = 'maria.sigilosa@exemplo.com.br';
    private const TELEFONE = '(61) 98765-4321';
    private const RG = '7654321';
    private const LOGRADOURO = 'Rua das Acácias Escondidas';
    private const CEP = '70390-100';
    private const LEITOR = ['modules.clientes.view', 'resources.cliente.view', 'resources.pasta.view'];
    /** A visão pastas_sem_cliente lista pastas: exige também o módulo Expediente, como a buscar_pastas. */
    private const LEITOR_DE_PASTAS = ['modules.clientes.view', 'resources.cliente.view', 'resources.pasta.view', 'modules.expediente.view'];

    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::LEITOR);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('aceite: uma chamada só responde quais clientes estão sem CPF e em quais pastas (id e número); quem tem CPF e a PJ não entram')]
    public function testClientesSemCpfEEmQuaisPastas(): void
    {
        $semCpf = $this->clientePF('Ana Sem Documento', cpf: '');
        $pastaA = $this->pasta($semCpf, 'PEND-A');
        $pastaB = $this->pasta($semCpf, 'PEND-B');
        $outroSemCpf = $this->clientePF('Bruno Sem Documento', cpf: '');
        $comCpf = $this->clientePF('Carla Com Documento');
        $this->pasta($comCpf, 'PEND-C');
        $this->clientePJ('Empresa Sem CNPJ', cnpj: '');

        $resposta = $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf']]);

        self::assertSame('clientes', $resposta->dados['visao']);
        self::assertSame(['cpf'], $resposta->dados['faltando']);
        self::assertSame(2, $resposta->dados['total']);
        self::assertSame([
            [
                'id' => (int) $semCpf->getId(),
                'nome' => 'ANA SEM DOCUMENTO',
                'tipo' => 'PF',
                'campos_que_faltam' => ['cpf'],
                'pastas' => [['id' => (int) $pastaA->getId(), 'numero' => 'PEND-A'], ['id' => (int) $pastaB->getId(), 'numero' => 'PEND-B']],
            ],
            [
                'id' => (int) $outroSemCpf->getId(),
                'nome' => 'BRUNO SEM DOCUMENTO',
                'tipo' => 'PF',
                'campos_que_faltam' => ['cpf'],
                'pastas' => [],
            ],
        ], $resposta->dados['clientes']);
        self::assertSame(0, $resposta->dados['pastas_omitidas_em_segredo']);
        self::assertNull($resposta->proximaPagina);
        self::assertNotContains((int) $comCpf->getId(), $resposta->ids['cliente']);
        self::assertSemValoresDoCliente($resposta);
    }

    #[TestDox('CPF, RG, e-mail, telefone, CEP, logradouro e nascimento nunca saem, nem do cliente listado nem do que tem tudo preenchido')]
    public function testNenhumValorSensivelSai(): void
    {
        $this->clientePF('Maria Sem Endereço', semEndereco: true);
        $this->clientePF('Joana Completa');

        foreach ([['faltando' => ['endereco', 'cpf', 'rg', 'email', 'telefone', 'nascimento']], ['faltando' => ['bairro']], []] as $filtro) {
            $resposta = $this->ler(['acao' => 'pendencias'] + $filtro);
            self::assertSemValoresDoCliente($resposta);
            static::getContainer()->get('services_resetter')->reset();
        }
        $endereco = $this->ler(['acao' => 'pendencias', 'faltando' => ['endereco']]);
        self::assertSame(['MARIA SEM ENDEREÇO'], array_column($endereco->dados['clientes'], 'nome'), 'só a do endereço vazio');
        self::assertSame(['endereco'], $endereco->dados['clientes'][0]['campos_que_faltam']);
    }

    #[TestDox('sem faltando, o documento principal: o CPF da PF e o CNPJ da PJ')]
    public function testPadraoCpfOuCnpj(): void
    {
        $pf = $this->clientePF('Pessoa Sem CPF', cpf: '');
        $pj = $this->clientePJ('Firma Sem CNPJ', cnpj: '');
        $this->clientePJ('Firma Com CNPJ');

        $resposta = $this->ler(['acao' => 'pendencias']);

        self::assertSame(['cpf', 'cnpj'], $resposta->dados['faltando']);
        $porId = array_column($resposta->dados['clientes'], null, 'id');
        self::assertEqualsCanonicalizing([(int) $pf->getId(), (int) $pj->getId()], array_keys($porId));
        self::assertSame(['cnpj'], $porId[(int) $pj->getId()]['campos_que_faltam']);
        self::assertSame('PJ', $porId[(int) $pj->getId()]['tipo']);
    }

    #[TestDox('pasta em segredo de justiça não aparece e conta na linha; a trancada e a sem acesso não aparecem nem contam, como no ler')]
    public function testSegredoTrancadaESemAcesso(): void
    {
        $cliente = $this->clientePF('Ana Sem Documento', cpf: '');
        $comum = $this->pasta($cliente, 'PEND-COMUM');
        $secreta = $this->pasta($cliente, 'PEND-SECRETA');
        $this->vincularProcesso($secreta);
        $trancada = $this->pasta($cliente, 'PEND-TRANCADA');
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();
        $soCliente = $this->pessoa();
        $this->vinculo($soCliente, $this->escritorio, permissoes: ['modules.clientes.view', 'resources.cliente.view']);

        $resposta = $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf']]);

        $linha = $resposta->dados['clientes'][0];
        self::assertSame([(int) $comum->getId()], array_column($linha['pastas'], 'id'));
        self::assertSame(1, $linha['pastas_em_segredo']);
        self::assertSame(1, $resposta->dados['pastas_omitidas_em_segredo']);
        $json = self::json($resposta);
        self::assertStringNotContainsString('PEND-SECRETA', $json);
        self::assertStringNotContainsString('PEND-TRANCADA', $json);
        self::assertSame(['cliente' => [(int) $cliente->getId()], 'pasta' => [(int) $comum->getId()]], $resposta->ids);

        static::getContainer()->get('services_resetter')->reset();
        $semPasta = $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf']], $soCliente);
        self::assertSame([], $semPasta->dados['clientes'][0]['pastas'], 'quem não abre pasta nenhuma vê o cliente sem pastas');
        self::assertArrayNotHasKey('pastas_em_segredo', $semPasta->dados['clientes'][0], 'e o segredo de pasta que não abre não é revelado');
    }

    #[TestDox('cliente de outro escritório não aparece; quem não vê clientes recebe a lista vazia; o restrito só os clientes das pastas dele')]
    public function testIsolamento(): void
    {
        $meu = $this->clientePF('Ana Sem Documento', cpf: '');
        $alheio = $this->clientePF('Bruno Sem Documento', cpf: '');
        $outro = $this->escritorio('Outro');
        $deOutro = $this->clientePF('Outro Escritório Sem CPF', cpf: '', escritorio: $outro);
        $semVer = $this->pessoa();
        $this->vinculo($semVer, $this->escritorio, permissoes: ['modules.clientes.view']);
        $restrito = $this->pessoa();
        $this->vinculo($restrito, $this->escritorio, permissoes: self::LEITOR)->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $colega = $this->pessoa();
        $this->vinculo($colega, $this->escritorio, permissoes: self::LEITOR);
        $this->pasta($meu, 'PEND-MINHA')->setResponsavel($restrito);
        $this->pasta($alheio, 'PEND-ALHEIA')->setResponsavel($colega);
        $this->em()->flush();

        $todos = $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf']]);
        self::assertEqualsCanonicalizing([(int) $meu->getId(), (int) $alheio->getId()], array_column($todos->dados['clientes'], 'id'));
        self::assertStringNotContainsString('OUTRO ESCRITÓRIO', self::json($todos));
        self::assertNotContains((int) $deOutro->getId(), $todos->ids['cliente']);

        static::getContainer()->get('services_resetter')->reset();
        self::assertSame(0, $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf']], $semVer)->dados['total']);
        static::getContainer()->get('services_resetter')->reset();
        $doRestrito = $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf']], $restrito);
        self::assertSame([(int) $meu->getId()], array_column($doRestrito->dados['clientes'], 'id'));
        self::assertSame(1, $doRestrito->dados['total']);
    }

    #[TestDox('paginação: o total do filtro vem antes de percorrer; por_pagina corta e proxima_pagina continua na ordem do nome')]
    public function testPaginacao(): void
    {
        foreach (['Carlos', 'Ana', 'Bruno'] as $nome) {
            $this->clientePF($nome . ' Sem CPF', cpf: '');
        }

        $primeira = $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf'], 'por_pagina' => 2]);
        self::assertSame(3, $primeira->dados['total']);
        self::assertSame(2, $primeira->dados['por_pagina']);
        self::assertSame(['ANA SEM CPF', 'BRUNO SEM CPF'], array_column($primeira->dados['clientes'], 'nome'));
        self::assertSame(2, $primeira->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf'], 'por_pagina' => 2, 'pagina' => 2]);
        self::assertSame(['CARLOS SEM CPF'], array_column($segunda->dados['clientes'], 'nome'));
        self::assertNull($segunda->proximaPagina);
    }

    #[TestDox('a página de 200 clientes com pastas cabe no teto do despacho')]
    public function testPaginaCheiaCabeNoTeto(): void
    {
        for ($i = 1; $i <= 200; ++$i) {
            $cliente = $this->clientePF(sprintf('Cliente Homônimo Com Nome Bem Comprido %03d', $i), cpf: '', flush: false);
            $this->pasta($cliente, sprintf('TETO-%04d', $i), flush: false);
        }
        $this->em()->flush();

        $resposta = $this->ler(['acao' => 'pendencias', 'faltando' => ['cpf'], 'por_pagina' => 200]);

        self::assertCount(200, $resposta->dados['clientes']);
        self::assertLessThan(45_000, mb_strlen(self::json($resposta)));
    }

    #[TestDox('visão pastas_sem_cliente: só as ativas sem nenhum cliente vinculado, com o nome escrito; a em segredo e a trancada saem e contam; outro escritório e arquivada não')]
    public function testPastasSemCliente(): void
    {
        $semCliente = $this->pasta(null, 'SEMCLI-1')->setNomeCliente('Fulano Escrito ' . self::CPF);
        $semNome = $this->pasta(null, 'SEMCLI-2');
        $this->pasta($this->clientePF('Ana Vinculada'), 'COMCLI-1');
        $this->pasta(null, 'SEMCLI-ARQ')->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $secreta = $this->pasta(null, 'SEMCLI-SECRETA');
        $this->vincularProcesso($secreta);
        $trancada = $this->pasta(null, 'SEMCLI-TRANCADA');
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->pasta(null, 'SEMCLI-OUTRO', escritorio: $this->escritorio('Outro'));
        $leitor = $this->pessoa();
        $this->vinculo($leitor, $this->escritorio, permissoes: self::LEITOR_DE_PASTAS);
        $this->em()->flush();

        $resposta = $this->ler(['acao' => 'pendencias', 'visao' => 'pastas_sem_cliente'], $leitor);

        self::assertSame('pastas_sem_cliente', $resposta->dados['visao']);
        self::assertSame(2, $resposta->dados['total']);
        $porId = array_column($resposta->dados['pastas'], null, 'id');
        self::assertEqualsCanonicalizing([(int) $semCliente->getId(), (int) $semNome->getId()], array_keys($porId));
        self::assertSame(['id', 'numero', 'nome_cliente_escrito'], array_keys($porId[(int) $semCliente->getId()]));
        self::assertStringContainsString('FULANO ESCRITO', (string) $porId[(int) $semCliente->getId()]['nome_cliente_escrito']);
        self::assertNull($porId[(int) $semNome->getId()]['nome_cliente_escrito']);
        self::assertSame(1, $resposta->dados['omitidas_em_segredo']);
        self::assertSame(1, $resposta->dados['omitidas_sem_acesso']);
        $json = self::json($resposta);
        foreach (['COMCLI', 'SEMCLI-ARQ', 'SEMCLI-SECRETA', 'SEMCLI-TRANCADA', 'SEMCLI-OUTRO', self::CPF, self::CPF_DIGITOS] as $proibido) {
            self::assertStringNotContainsString($proibido, $json);
        }
    }

    #[TestDox('visão pastas_sem_cliente sem o módulo Expediente recusa por permissão; a visão clientes segue aberta')]
    public function testPastasSemClienteSemExpediente(): void
    {
        $this->pasta(null, 'SEMCLI-SEM-EXPEDIENTE');

        try {
            $this->ler(['acao' => 'pendencias', 'visao' => 'pastas_sem_cliente']);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $e->motivo);
        }
        static::getContainer()->get('services_resetter')->reset();
        self::assertSame('clientes', $this->ler(['acao' => 'pendencias'])->dados['visao']);
    }

    #[TestDox('visão pastas_sem_cliente do restrito: só as pastas do escopo dele; as de fora não aparecem nem contam em omitidas_sem_acesso')]
    public function testPastasSemClienteDoRestrito(): void
    {
        $restrito = $this->pessoa();
        $this->vinculo($restrito, $this->escritorio, permissoes: self::LEITOR_DE_PASTAS)->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $colega = $this->pessoa();
        $this->vinculo($colega, $this->escritorio, permissoes: self::LEITOR_DE_PASTAS);
        $minha = $this->pasta(null, 'ESCOPO-MINHA')->setResponsavel($restrito);
        $this->pasta(null, 'ESCOPO-ALHEIA-1')->setResponsavel($colega);
        $this->pasta(null, 'ESCOPO-ALHEIA-2')->setResponsavel($colega);
        $this->em()->flush();

        $resposta = $this->ler(['acao' => 'pendencias', 'visao' => 'pastas_sem_cliente'], $restrito);

        self::assertSame([(int) $minha->getId()], array_column($resposta->dados['pastas'], 'id'));
        self::assertSame(1, $resposta->dados['total']);
        self::assertSame(0, $resposta->dados['omitidas_sem_acesso'], 'as pastas fora do escopo não são contadas');
        self::assertStringNotContainsString('ESCOPO-ALHEIA', self::json($resposta));

        static::getContainer()->get('services_resetter')->reset();
        $doColega = $this->ler(['acao' => 'pendencias', 'visao' => 'pastas_sem_cliente'], $colega);
        self::assertSame(3, $doColega->dados['total'], 'sem restrição, o colega vê as três');
    }

    #[TestDox('sem o módulo clientes, pendencias recusa por permissão')]
    public function testSemModulo(): void
    {
        $semModulo = $this->pessoa();
        $this->vinculo($semModulo, $this->escritorio, permissoes: ['resources.cliente.view', 'resources.pasta.view']);

        try {
            $this->ler(['acao' => 'pendencias'], $semModulo);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $e->motivo);
        }
    }

    private static function assertSemValoresDoCliente(RespostaDaFerramenta $resposta): void
    {
        $json = self::json($resposta);
        $soDigitos = (string) preg_replace('#[.\-()/\s]#', '', $json);
        foreach ([self::CPF, self::CPF_DIGITOS, self::CNPJ_DIGITOS, self::EMAIL, self::TELEFONE, self::RG, self::LOGRADOURO, self::CEP, '1985-03-12'] as $valor) {
            self::assertStringNotContainsString($valor, $json, 'o valor não sai: ' . $valor);
        }
        foreach ([self::CPF_DIGITOS, self::CNPJ_DIGITOS, '61987654321', '70390100'] as $digitos) {
            self::assertStringNotContainsString($digitos, $soDigitos, 'nem os dígitos colados: ' . $digitos);
        }
        self::assertStringNotContainsString('Acácias', $json);
    }

    private static function json(RespostaDaFerramenta $resposta): string
    {
        return json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $argumentos */
    private function ler(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        $pessoa ??= $this->pessoa;
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));

        return static::getContainer()->get(LerClientesFerramenta::class)->executar($argumentos, $chamada);
    }

    private function clientePF(string $nome, string $cpf = self::CPF, bool $semEndereco = false, ?Tenant $escritorio = null, bool $flush = true): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto($nome)->setCpf($cpf)->setRg(self::RG)->setRgOrgaoExpedidor('SSP')
            ->setDataNascimento(new \DateTimeImmutable('1985-03-12'))->setNacionalidade('brasileira');

        return $this->contatoEEndereco($cliente, $semEndereco, $escritorio, $flush);
    }

    private function clientePJ(string $razao, string $cnpj = self::CNPJ_DIGITOS): ClientePJ
    {
        $cliente = (new ClientePJ())->setRazaoSocial($razao)->setCnpj($cnpj)->setRepresentanteLegal('')->setRepresentanteCpf('')
            ->setRepresentanteRg('')->setRepresentanteCargo('')->setEnderecSede('');

        return $this->contatoEEndereco($cliente);
    }

    /**
     * @template T of Cliente
     *
     * @param T $cliente
     *
     * @return T
     */
    private function contatoEEndereco(Cliente $cliente, bool $semEndereco = false, ?Tenant $escritorio = null, bool $flush = true): Cliente
    {
        $cliente->setEmail(self::EMAIL)->setTelefoneCelular(self::TELEFONE)
            ->setCep($semEndereco ? '' : self::CEP)->setEndereco($semEndereco ? '' : self::LOGRADOURO)->setNumero('42')->setBairro('Asa Sul')
            ->setCidade('Brasília')->setEstado('DF')->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($cliente);
        if ($flush) {
            $this->em()->flush();
        }

        return $cliente;
    }

    private function pasta(?Cliente $cliente, string $nup, ?Tenant $escritorio = null, bool $flush = true): Pasta
    {
        $pasta = (new Pasta())->setNup($nup);
        $pasta->setTenant($escritorio ?? $this->escritorio);
        if ($cliente !== null) {
            $pasta->addCliente($cliente);
        }
        $this->em()->persist($pasta);
        if ($flush) {
            $this->em()->flush();
        }

        return $pasta;
    }

    private function vincularProcesso(Pasta $pasta): void
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(sprintf('0701134572025807%04d', random_int(0, 9999)));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $processo->setNivelSigilo(1);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();
    }
}
