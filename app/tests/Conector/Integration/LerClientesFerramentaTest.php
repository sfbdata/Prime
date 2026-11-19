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
use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_clientes` com o banco e o portão de verdade (spec mcp-remoto-oauth, 6.4 e 6.2, regras 1, 3 e 4): o cliente só
 * do escritório da chamada e que a pessoa vê, o mesmo "não encontrado" para alheio, inexistente e sem permissão; as
 * pastas só as que a pessoa abre, sem as em segredo (contadas); nenhum valor de documento, contato ou endereço no JSON
 * inteiro; `conferir_documento` com e sem máscara; `documentos_de_identidade` sem a pasta em segredo nem a pasta com a
 * aba Documentos oculta.
 */
#[CoversClass(LerClientesFerramenta::class)]
final class LerClientesFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CPF = '529.982.247-25';
    private const CPF_DIGITOS = '52998224725';
    private const CNPJ = '11.222.333/0001-81';
    private const CNPJ_DIGITOS = '11222333000181';
    private const CPF_DO_REPRESENTANTE = '111.444.777-35';
    private const EMAIL = 'maria.sigilosa@exemplo.com.br';
    private const TELEFONE = '(61) 98765-4321';
    private const WHATSAPP = '61912345678';
    private const RG = '7654321';
    private const LOGRADOURO = 'Rua das Acácias Escondidas';
    private const CEP = '70390-100';
    private const LEITOR = ['modules.clientes.view', 'resources.cliente.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;
    private User $colega;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::LEITOR);
        $this->colega = $this->pessoa();
        $this->vinculo($this->colega, $this->escritorio, permissoes: self::LEITOR);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('ler: nome, tipo, estado civil, profissão, nacionalidade, cidade e UF; quais campos estão preenchidos e quais faltam, sem o valor')]
    public function testLerPessoaFisica(): void
    {
        $cliente = $this->clientePF();

        $resposta = $this->ler(['acao' => 'ler', 'cliente_id' => $cliente->getId()]);

        self::assertSame([
            'id' => (int) $cliente->getId(),
            'nome' => 'MARIA SIGILOSA DA SILVA',
            'tipo' => 'PF',
            'estado_civil' => 'CASADO',
            'profissao' => 'PROFESSORA',
            'nacionalidade' => 'brasileira',
            'cidade' => 'BRASÍLIA',
            'uf' => 'DF',
        ], $resposta->dados['cliente']);
        foreach (['nome', 'cpf', 'rg', 'nascimento', 'estado_civil', 'cep', 'logradouro', 'cidade', 'uf', 'telefone', 'whatsapp', 'email'] as $campo) {
            self::assertContains($campo, $resposta->dados['campos_preenchidos'], $campo);
        }
        foreach (['bairro', 'complemento', 'genero', 'rg_orgao'] as $campo) {
            self::assertContains($campo, $resposta->dados['campos_que_faltam'], $campo);
        }
        self::assertSame([], array_intersect($resposta->dados['campos_preenchidos'], $resposta->dados['campos_que_faltam']));
        self::assertNotContains('cnpj', [...$resposta->dados['campos_preenchidos'], ...$resposta->dados['campos_que_faltam']], 'só os campos do tipo do cliente');
        self::assertContains(['texto' => 'Bairro não informado', 'aba' => 'endereco'], $resposta->dados['pendencias']);
        self::assertIsBool($resposta->dados['pode_editar']);
        self::assertSame(['cliente' => [(int) $cliente->getId()], 'pasta' => []], $resposta->ids);
        self::assertSemValoresDoCliente($resposta);
    }

    #[TestDox('ler: PJ com o representante legal só pelo nome e cargo, sem CNPJ nem o CPF dele')]
    public function testLerPessoaJuridica(): void
    {
        $cliente = (new ClientePJ())->setRazaoSocial('Empresa Reservada Ltda')->setCnpj(self::CNPJ_DIGITOS)
            ->setRepresentanteLegal('João Representante')->setRepresentanteCpf(self::CPF_DO_REPRESENTANTE)
            ->setRepresentanteRg(self::RG)->setRepresentanteCargo('Sócio administrador')->setEnderecSede('');
        $this->contatoEEndereco($cliente);

        $resposta = $this->ler(['acao' => 'ler', 'cliente_id' => $cliente->getId()]);

        self::assertSame('PJ', $resposta->dados['cliente']['tipo']);
        self::assertSame('EMPRESA RESERVADA LTDA', $resposta->dados['cliente']['nome']);
        self::assertNull($resposta->dados['cliente']['estado_civil']);
        self::assertSame(['nome' => 'JOÃO REPRESENTANTE', 'cargo' => 'SÓCIO ADMINISTRADOR'], $resposta->dados['cliente']['representante_legal']);
        foreach (['cnpj', 'razao_social', 'representante_legal', 'representante_cpf', 'representante_rg'] as $campo) {
            self::assertContains($campo, $resposta->dados['campos_preenchidos'], $campo);
        }
        self::assertContains('nome_fantasia', $resposta->dados['campos_que_faltam']);
        $json = self::json($resposta);
        foreach ([self::CNPJ, self::CNPJ_DIGITOS, self::CPF_DO_REPRESENTANTE, '11144477735'] as $valor) {
            self::assertStringNotContainsString($valor, $json);
        }
        self::assertSemValoresDoCliente($resposta);
    }

    #[TestDox('ler: cliente de outro escritório, inexistente ou que a pessoa não vê dão o mesmo não encontrado')]
    public function testIsolamento(): void
    {
        $meu = $this->clientePF();
        $outro = $this->escritorio('Outro');
        $alheio = $this->clientePF($outro);
        $semVer = $this->pessoa();
        $this->vinculo($semVer, $this->escritorio, permissoes: ['modules.clientes.view']);

        self::assertSame((int) $meu->getId(), $this->ler(['acao' => 'ler', 'cliente_id' => $meu->getId()])->dados['cliente']['id'], 'controle');
        static::getContainer()->get('services_resetter')->reset();
        foreach (['ler', 'documentos_de_identidade'] as $acao) {
            $this->assertMotivo(['acao' => $acao, 'cliente_id' => $alheio->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            $this->assertMotivo(['acao' => $acao, 'cliente_id' => 999_999_999], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            $this->assertMotivo(['acao' => $acao, 'cliente_id' => $meu->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $semVer);
        }
    }

    #[TestDox('sem o módulo clientes, toda ação recusa por permissão, antes de olhar o cliente')]
    public function testSemModulo(): void
    {
        $cliente = $this->clientePF();
        $semModulo = $this->pessoa();
        $this->vinculo($semModulo, $this->escritorio, permissoes: ['resources.cliente.view', 'resources.pasta.view']);

        $this->assertMotivo(['acao' => 'ler', 'cliente_id' => $cliente->getId()], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $semModulo);
        $this->assertMotivo(['acao' => 'conferir_documento', 'documento' => self::CPF], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $semModulo);
        $this->assertMotivo(['acao' => 'documentos_de_identidade', 'cliente_id' => $cliente->getId()], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $semModulo);
    }

    #[TestDox('ler: as pastas só por id, número e ação; a que a pessoa não abre não aparece; a ligada a processo em segredo sai e conta')]
    public function testPastasDoCliente(): void
    {
        $cliente = $this->clientePF();
        $aberta = $this->pasta($cliente);
        $fechada = $this->pasta($cliente);
        $secreta = $this->pasta($cliente);
        $this->vincularProcesso($secreta, nivelSigilo: 1);
        $soUma = $this->pessoa();
        $this->vinculo($soUma, $this->escritorio, permissoes: ['modules.clientes.view', 'resources.cliente.view']);
        $this->conceder($soUma, $aberta);
        $this->conceder($soUma, $secreta);

        $todas = $this->ler(['acao' => 'ler', 'cliente_id' => $cliente->getId()]);
        self::assertEqualsCanonicalizing([$aberta->getId(), $fechada->getId()], array_column($todas->dados['pastas'], 'pasta_id'), 'controle: quem vê todas');
        self::assertSame(1, $todas->dados['pastas_omitidas_em_segredo']);
        self::assertSame(['pasta_id', 'numero', 'acao'], array_keys($todas->dados['pastas'][0]));
        self::assertSame(1, $todas->contagens['em_segredo']);
        self::assertStringNotContainsString((string) $secreta->getNup(), self::json($todas));

        static::getContainer()->get('services_resetter')->reset();
        $daPessoa = $this->ler(['acao' => 'ler', 'cliente_id' => $cliente->getId()], $soUma);
        self::assertSame([(int) $aberta->getId()], array_column($daPessoa->dados['pastas'], 'pasta_id'));
        self::assertSame('AÇÃO PREVIDENCIÁRIA', $daPessoa->dados['pastas'][0]['acao']);
        self::assertSame(1, $daPessoa->dados['pastas_omitidas_em_segredo']);
        self::assertStringNotContainsString((string) $fechada->getNup(), self::json($daPessoa));
    }

    #[TestDox('conferir_documento: acha com e sem máscara, diz só id e nome; outro escritório e quem não vê o cliente recebem não encontrado')]
    public function testConferirDocumento(): void
    {
        $cliente = $this->clientePF();
        $outro = $this->escritorio('Outro');
        $this->clientePF($outro);
        $semVer = $this->pessoa();
        $this->vinculo($semVer, $this->escritorio, permissoes: ['modules.clientes.view']);
        $deOutro = $this->pessoa();
        $this->vinculo($deOutro, $outro, permissoes: self::LEITOR);

        foreach ([self::CPF, self::CPF_DIGITOS] as $documento) {
            $resposta = $this->ler(['acao' => 'conferir_documento', 'documento' => $documento]);
            self::assertSame(['encontrado' => true, 'cliente' => ['id' => (int) $cliente->getId(), 'nome' => 'MARIA SIGILOSA DA SILVA']], $resposta->dados);
            self::assertSemValoresDoCliente($resposta);
            static::getContainer()->get('services_resetter')->reset();
        }

        $semCadastro = $this->ler(['acao' => 'conferir_documento', 'documento' => '111.444.777-35']);
        self::assertSame(['encontrado' => false, 'cliente' => null], $semCadastro->dados);
        static::getContainer()->get('services_resetter')->reset();
        self::assertSame(['encontrado' => false, 'cliente' => null], $this->ler(['acao' => 'conferir_documento', 'documento' => self::CPF], $semVer)->dados);
        static::getContainer()->get('services_resetter')->reset();
        $noOutro = $this->ler(['acao' => 'conferir_documento', 'documento' => self::CPF], $deOutro, $outro);
        self::assertTrue($noOutro->dados['encontrado'], 'controle: no outro escritório há um cadastro com o mesmo CPF');
        self::assertNotSame((int) $cliente->getId(), $noOutro->dados['cliente']['id'], 'e é o dele, nunca o meu');
    }

    #[TestDox('documentos_de_identidade: metadados dos achados nas pastas; sem a pasta em segredo (contada) nem a pasta com a aba Documentos oculta')]
    public function testDocumentosDeIdentidade(): void
    {
        $cliente = $this->clientePF();
        $comum = $this->pasta($cliente);
        $achado = $this->documento($comum, 'rg-da-maria.pdf');
        $secreta = $this->pasta($cliente);
        $this->vincularProcesso($secreta, nivelSigilo: 1);
        $this->documento($secreta, 'rg-em-segredo.pdf');
        $semAba = $this->pasta($cliente);
        $this->documento($semAba, 'rg-aba-oculta.pdf');
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $semAba, AbaDaPasta::Documentos, $this->colega, new \DateTimeImmutable()));
        $this->em()->flush();

        $resposta = $this->ler(['acao' => 'documentos_de_identidade', 'cliente_id' => $cliente->getId()]);

        self::assertTrue($resposta->dados['documento_do_cadastro_valido']);
        self::assertCount(1, $resposta->dados['documentos']);
        $item = $resposta->dados['documentos'][0];
        self::assertSame((int) $achado->getId(), $item['documento_id']);
        self::assertSame((int) $comum->getId(), $item['pasta_id']);
        self::assertSame('rg-da-maria.pdf', $item['arquivo']);
        self::assertSame(PastaDocumento::CATEGORIA_IDENTIFICACAO, $item['categoria']);
        self::assertSame(['documento_id', 'pasta_id', 'pasta_numero', 'arquivo', 'categoria', 'categoria_rotulo', 'carregado_em', 'pode_vincular'], array_keys($item));
        self::assertSame(1, $resposta->dados['pastas_omitidas_em_segredo'], 'a em segredo conta; a da aba oculta sai sem contagem');
        $json = self::json($resposta);
        foreach (['rg-em-segredo', 'rg-aba-oculta', (string) $secreta->getNup(), (string) $semAba->getNup()] as $proibido) {
            self::assertStringNotContainsString($proibido, $json);
        }
        self::assertSame(['cliente' => [(int) $cliente->getId()], 'pasta' => [(int) $comum->getId()], 'documento' => [(int) $achado->getId()]], $resposta->ids);
        self::assertSemValoresDoCliente($resposta);
    }

    #[TestDox('documentos_de_identidade: sem CPF válido no cadastro, nada é procurado')]
    public function testDocumentosDeIdentidadeSemDocumentoValido(): void
    {
        $invalido = $this->clientePF(cpf: '123.456.789-00');
        $this->documento($this->pasta($invalido), 'rg-invalido.pdf');

        $resposta = $this->ler(['acao' => 'documentos_de_identidade', 'cliente_id' => $invalido->getId()]);

        self::assertFalse($resposta->dados['documento_do_cadastro_valido']);
        self::assertSame([], $resposta->dados['documentos']);
    }

    #[TestDox('documentos_de_identidade: quem vê o cliente mas não abre a pasta não vê o documento dela')]
    public function testDocumentosDeIdentidadeSemAPasta(): void
    {
        $cliente = $this->clientePF(cpf: '390.533.447-05');
        $this->documento($this->pasta($cliente), 'rg-fechado.pdf');
        $soCliente = $this->pessoa();
        $this->vinculo($soCliente, $this->escritorio, permissoes: ['modules.clientes.view', 'resources.cliente.view']);
        $daPessoa = $this->ler(['acao' => 'documentos_de_identidade', 'cliente_id' => $cliente->getId()], $soCliente);
        self::assertSame([], $daPessoa->dados['documentos']);
        self::assertStringNotContainsString('rg-fechado', self::json($daPessoa));
    }

    /**
     * Nenhum valor de documento, contato, nascimento ou endereço no JSON inteiro (6.2, regra 4). Cidade e UF podem sair.
     */
    private static function assertSemValoresDoCliente(RespostaDaFerramenta $resposta): void
    {
        $json = self::json($resposta);
        // Sem a pontuação de documento e telefone, mas com as aspas e vírgulas do JSON: um campo não cola no outro.
        $soDigitos = (string) preg_replace('#[.\-()/\s]#', '', $json);
        foreach ([self::CPF, self::CPF_DIGITOS, self::EMAIL, self::TELEFONE, self::WHATSAPP, self::RG, self::LOGRADOURO, self::CEP, '1985-03-12', '12/03/1985'] as $valor) {
            self::assertStringNotContainsString($valor, $json, 'o valor não sai: ' . $valor);
        }
        foreach ([self::CPF_DIGITOS, '61987654321', self::WHATSAPP, '70390100'] as $digitos) {
            self::assertStringNotContainsString($digitos, $soDigitos, 'nem os dígitos colados: ' . $digitos);
        }
        self::assertStringNotContainsString('maria.sigilosa', $json);
        self::assertStringNotContainsString('Acácias', $json);
    }

    private static function json(RespostaDaFerramenta $resposta): string
    {
        return json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $argumentos */
    private function ler(array $argumentos, ?User $pessoa = null, ?Tenant $escritorio = null): RespostaDaFerramenta
    {
        $pessoa ??= $this->pessoa;
        $escritorio ??= $this->escritorio;
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));

        return static::getContainer()->get(LerClientesFerramenta::class)->executar($argumentos, $chamada);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo, ?User $pessoa = null): void
    {
        try {
            $this->ler($argumentos, $pessoa);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);
            if ($motivo === ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO) {
                self::assertSame(ErroDaFerramenta::NAO_ENCONTRADO, $e->getMessage(), 'a mesma frase, sem dizer se o cliente existe');
            }

            return;
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function clientePF(?Tenant $escritorio = null, string $cpf = self::CPF): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto('Maria Sigilosa da Silva')->setCpf($cpf)->setRg(self::RG)->setRgOrgaoExpedidor('')
            ->setDataNascimento(new \DateTimeImmutable('1985-03-12'))->setEstadoCivil('CASADO')->setProfissao('Professora')
            ->setNacionalidade('brasileira');

        return $this->contatoEEndereco($cliente, $escritorio);
    }

    /**
     * @template T of Cliente
     *
     * @param T $cliente
     *
     * @return T
     */
    private function contatoEEndereco(Cliente $cliente, ?Tenant $escritorio = null): Cliente
    {
        $cliente->setEmail(self::EMAIL)->setTelefoneCelular(self::TELEFONE)->setWhatsapp(self::WHATSAPP)
            ->setCep(self::CEP)->setEndereco(self::LOGRADOURO)->setNumero('42')->setCidade('Brasília')->setEstado('DF')
            ->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function pasta(Cliente $cliente): Pasta
    {
        $pasta = (new Pasta())->setNup('CLI-' . uniqid());
        $pasta->setTenant($this->escritorio);
        $pasta->setNomeAcao('Ação Previdenciária');
        $pasta->addCliente($cliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function vincularProcesso(Pasta $pasta, int $nivelSigilo): void
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(sprintf('0701134572025807%04d', random_int(0, 9999)));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $processo->setNivelSigilo($nivelSigilo);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();
    }

    private function documento(Pasta $pasta, string $nome): PastaDocumento
    {
        $documento = (new PastaDocumento())->setPasta($pasta)->setTenant($this->escritorio)->setCategoria(PastaDocumento::CATEGORIA_IDENTIFICACAO)
            ->setTitulo($nome)->setNomeOriginal($nome)->setCaminhoArquivo('cli_' . bin2hex(random_bytes(6)) . '.pdf')
            ->setMimeType('application/pdf')->setTamanhoBytes(1234);
        $pasta->getDocumentos()->add($documento);
        $this->em()->persist($documento);
        $this->em()->flush();

        return $documento;
    }

    private function conceder(User $pessoa, Pasta $pasta): void
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($pessoa);
        $acesso->setTenant($this->escritorio);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }
}
