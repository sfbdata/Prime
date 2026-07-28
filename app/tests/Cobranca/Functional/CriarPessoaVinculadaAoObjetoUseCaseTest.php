<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\CriarPessoaVinculadaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Enum\TipoTelefone;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\UseCase\CriarPessoaVinculadaAoObjetoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `CriarPessoaVinculadaAoObjetoUseCase` — o cadastro de pessoa pelo modal único
 * (`docs/specs/cobranca-modal-unico-pessoa.md`).
 *
 * É KernelTestCase e não TestCase puro de propósito: o UseCase virou orquestrador de CINCO gravações
 * (pessoa → endereço → telefone → e-mail → vínculo) dentro de uma transação, e a regra da casa proíbe
 * mockar o EntityManager. Com o EM real, o teste prova o que interessa — que ou entra tudo, ou não
 * entra nada — em vez de provar que os mocks foram chamados na ordem em que os escrevi.
 */
#[CoversClass(CriarPessoaVinculadaAoObjetoUseCase::class)]
final class CriarPessoaVinculadaAoObjetoUseCaseTest extends KernelTestCase
{
    use Factories;

    #[TestDox('Falhar no vínculo (o último passo) não deixa pessoa órfã: a transação desfaz os 4 anteriores')]
    public function testFalhaNoVinculoNaoDeixaResiduo(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sut = static::getContainer()->get(CriarPessoaVinculadaAoObjetoUseCase::class);
        [$tenant, $usuario] = $this->tenantEUsuario($em);

        $input = $this->inputCompleto('Orfa Que Nao Pode Existir');

        // Objeto INEXISTENTE: quem recusa é o `VincularPessoaAObjetoUseCase`, o ÚLTIMO passo — depois de
        // a pessoa, o endereço, o telefone e o e-mail já terem sido gravados e flushados.
        $this->expectException(ObjetoNaoEncontradoException::class);

        try {
            $sut->executar($input, 999999999, $tenant, $usuario);
        } finally {
            $em->clear();
            $pessoa = $em->getRepository(Pessoa::class)->findOneBy(['nome' => 'Orfa Que Nao Pode Existir']);
            // O vínculo é o que faz a pessoa APARECER na aba. Sem ele, uma pessoa completa gravada aqui
            // seria invisível na tela e o gestor cadastraria de novo — duas fichas para o mesmo devedor.
            self::assertNull($pessoa, 'sem o vínculo, nada pode ter sobrado no banco');
            // Escopado ao tenant do teste: o banco de teste é um clone com dado real, e varrer a tabela
            // inteira mediria o acervo dos outros, não o resíduo desta gravação.
            self::assertCount(0, $em->getRepository(PessoaEndereco::class)->findBy(['tenant' => $tenant->getId()]));
            self::assertCount(0, $em->getRepository(PessoaTelefone::class)->findBy(['tenant' => $tenant->getId()]));
            self::assertCount(0, $em->getRepository(PessoaEmail::class)->findBy(['tenant' => $tenant->getId()]));
        }
    }

    #[TestDox('Cadastro completo: cada item vai para a LISTA da pessoa e nasce como o atual dela')]
    public function testCadastroCompletoGravaAsTresListas(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sut = static::getContainer()->get(CriarPessoaVinculadaAoObjetoUseCase::class);
        [$tenant, $usuario] = $this->tenantEUsuario($em);
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => CarteiraFactory::createOne(['tenant' => $tenant]),
        ])->_real();

        $vinculo = $sut->executar($this->inputCompleto('Devedora Do UseCase'), (int) $objeto->getId(), $tenant, $usuario);

        self::assertSame(TipoVinculo::Representante, $vinculo->getTipoVinculo());
        $pessoa = $vinculo->getPessoa();
        self::assertNotNull($pessoa);
        self::assertSame('Devedora Do UseCase', $pessoa->getNome());

        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $pessoa->getId()]);
        self::assertCount(1, $telefones, 'um registro só — o bridge de `Pessoa::setTelefone()` não pode gravar um segundo');
        self::assertSame(TipoTelefone::WhatsApp, $telefones[0]->getTipo());
        self::assertTrue($telefones[0]->isAtual());
        // A coluna-sombra continua sendo a leitura escalar de quem não quer iterar a lista.
        self::assertSame('(21) 98888-7777', $pessoa->getTelefone());

        self::assertCount(1, $em->getRepository(PessoaEndereco::class)->findBy(['pessoa' => $pessoa->getId()]));
        self::assertCount(1, $em->getRepository(PessoaEmail::class)->findBy(['pessoa' => $pessoa->getId()]));
    }

    #[TestDox('Sem endereço, telefone e e-mail, nenhuma lista é criada — os três blocos são opcionais')]
    public function testSemContatosNaoCriaListas(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sut = static::getContainer()->get(CriarPessoaVinculadaAoObjetoUseCase::class);
        [$tenant, $usuario] = $this->tenantEUsuario($em);
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => CarteiraFactory::createOne(['tenant' => $tenant]),
        ])->_real();

        $input = new CriarPessoaVinculadaInput();
        $input->nome = 'So O Nome';
        // Espaços em branco não são dado: o UseCase apara antes de decidir, senão " " viraria telefone.
        $input->telefone = '   ';
        $input->email = '  ';

        $vinculo = $sut->executar($input, (int) $objeto->getId(), $tenant, $usuario);
        $pessoaId = $vinculo->getPessoa()?->getId();

        self::assertCount(0, $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $pessoaId]));
        self::assertCount(0, $em->getRepository(PessoaEmail::class)->findBy(['pessoa' => $pessoaId]));
        self::assertCount(0, $em->getRepository(PessoaEndereco::class)->findBy(['pessoa' => $pessoaId]));
    }

    // ── apoio ────────────────────────────────────────────────────────────────────────────────────

    private function inputCompleto(string $nome): CriarPessoaVinculadaInput
    {
        $input = new CriarPessoaVinculadaInput();
        $input->nome = $nome;
        $input->tipoVinculo = TipoVinculo::Representante;
        $input->enderecoLogradouro = 'Rua das Palmeiras';
        $input->enderecoNumero = '250';
        $input->enderecoBairro = 'Centro';
        $input->enderecoCidade = 'Niterói';
        $input->enderecoUf = 'RJ';
        $input->enderecoCep = '24000-000';
        $input->telefone = '(21) 98888-7777';
        $input->tipoTelefone = TipoTelefone::WhatsApp;
        $input->email = 'usecase@example.com';

        return $input;
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantEUsuario(EntityManagerInterface $em): array
    {
        $tenant = TenantFactory::createOne()->_real();

        $user = new User();
        $user->setEmail('uc_pessoa_' . uniqid() . '@test.com');
        $user->setFullName('Gestor UseCase');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('irrelevante');
        $em->persist($user);
        $em->flush();

        return [$tenant, $user];
    }
}
