<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Repository\ClienteDoConectorRepository;
use App\Conector\Repository\CodigoDoConectorRepository;
use App\Conector\Repository\ConcessaoDoConectorRepository;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Repository\PropostaDoConectorRepository;
use App\Conector\Repository\TokenDoConectorRepository;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Os repositórios do conector no banco: a busca por escritório não devolve linha de outro escritório (com o
 * `TenantFilter` desligado, como no `/oauth/*`), e as buscas pelo hash acham a linha que revela o escritório.
 */
#[CoversClass(ConsentimentoDoConectorRepository::class)]
#[CoversClass(PropostaDoConectorRepository::class)]
#[CoversClass(EscritorioDoConectorRepository::class)]
#[CoversClass(ClienteDoConectorRepository::class)]
#[CoversClass(CodigoDoConectorRepository::class)]
#[CoversClass(TokenDoConectorRepository::class)]
#[CoversClass(ConcessaoDoConectorRepository::class)]
final class RepositoriosDoConectorTest extends KernelTestCase
{
    use Factories;
    use SemeiaConector;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function repo(string $classe): object
    {
        return static::getContainer()->get($classe);
    }

    #[TestDox('Consentimento, proposta e escritório: a busca com o escritório B não acha nada do escritório A')]
    public function testBuscaPorEscritorioNaoVazaParaOutro(): void
    {
        $user = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $a = $this->tenantNovo();
        $b = $this->tenantNovo();
        $deA = $this->semearConector($a, $user, $cliente);
        $hashDaPropostaDeA = $deA['proposta']->getIdentificadorHash();

        $consentimentos = $this->repo(ConsentimentoDoConectorRepository::class);
        $propostas = $this->repo(PropostaDoConectorRepository::class);
        $escritorios = $this->repo(EscritorioDoConectorRepository::class);

        self::assertSame($deA['consentimento'], $consentimentos->buscarAtivo($user, $a, $cliente));
        self::assertNull($consentimentos->buscarAtivo($user, $b, $cliente), 'mesma pessoa e mesmo app, outro escritório');
        self::assertSame([$deA['consentimento']], $consentimentos->listarAtivosDaPessoa($user, $a));
        self::assertSame([], $consentimentos->listarAtivosDaPessoa($user, $b));
        self::assertSame($deA['proposta'], $propostas->buscarPorIdentificadorHash($hashDaPropostaDeA, $a));
        self::assertNull($propostas->buscarPorIdentificadorHash($hashDaPropostaDeA, $b), 'proposta de outro escritório não existe para quem pergunta');
        self::assertNotNull($escritorios->buscarDoEscritorio($a));
        self::assertNull($escritorios->buscarDoEscritorio($b));
    }

    #[TestDox('Consentimento revogado sai da lista e da busca de ativo')]
    public function testRevogadoNaoEhAtivo(): void
    {
        $user = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $a = $this->tenantNovo();
        $deA = $this->semearConector($a, $user, $cliente);
        $deA['consentimento']->revogar(new \DateTimeImmutable());
        $this->em->flush();

        $consentimentos = $this->repo(ConsentimentoDoConectorRepository::class);
        self::assertNull($consentimentos->buscarAtivo($user, $a, $cliente));
        self::assertSame([], $consentimentos->listarAtivosDaPessoa($user, $a));
    }

    #[TestDox('Uma conexão ativa por pessoa, escritório e app: a segunda é recusada pelo banco; depois de revogar, pode')]
    public function testUmaAtivaPorPessoaEscritorioECliente(): void
    {
        $user = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $a = $this->tenantNovo();
        $deA = $this->semearConector($a, $user, $cliente);

        // Revogar e gravar ANTES de criar a nova: no mesmo flush o Doctrine insere antes de atualizar.
        $deA['consentimento']->revogar(new \DateTimeImmutable());
        $this->em->flush();
        $this->em->persist(new ConsentimentoDoConector($user, $a, $cliente, ['mcp:leitura'], new \DateTimeImmutable()));
        $this->em->flush();

        $this->em->persist(new ConsentimentoDoConector($user, $a, $cliente, ['mcp:escrita'], new \DateTimeImmutable()));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    #[TestDox('Revogar o consentimento derruba as concessões abertas dele num UPDATE só, com o motivo, e não toca outro escritório')]
    public function testRevogarConcessoesDoConsentimento(): void
    {
        $user = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $deA = $this->semearConector($this->tenantNovo(), $user, $cliente);
        $deB = $this->semearConector($this->tenantNovo(), $user, $cliente);
        $outra = new ConcessaoDoConector($deA['consentimento'], ['mcp:leitura'], 'https://bluejus.com.br/mcp', hash('sha256', 'senha'), new \DateTimeImmutable());
        $this->em->persist($outra);
        $this->em->flush();
        $idsDeA = [$deA['concessao']->getId(), $outra->getId()];
        $idDeB = $deB['concessao']->getId();
        $agora = new \DateTimeImmutable();

        $caidas = $this->repo(ConcessaoDoConectorRepository::class)->revogarDoConsentimento($deA['consentimento'], MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, $agora);
        $denovo = $this->repo(ConcessaoDoConectorRepository::class)->revogarDoConsentimento($deA['consentimento'], MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao, $agora);
        $this->em->clear();

        self::assertSame(2, $caidas);
        self::assertSame(0, $denovo, 'as já revogadas não mudam de motivo');
        foreach ($idsDeA as $id) {
            $concessao = $this->em->find(ConcessaoDoConector::class, $id);
            self::assertNotNull($concessao?->getRevogadaEm());
            self::assertSame(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, $concessao->getMotivo());
        }
        self::assertNull($this->em->find(ConcessaoDoConector::class, $idDeB)?->getRevogadaEm(), 'a do outro escritório fica');
    }

    #[TestDox('Revogar o par gerado por uma renovação derruba o acesso e a renovação novos e nada mais')]
    public function testRevogarParGeradoPor(): void
    {
        $user = UserFactory::createOne()->_real();
        $deA = $this->semearConector($this->tenantNovo(), $user, $this->clienteGlobal());
        $conn = $this->em->getConnection();
        $abertos = static fn (int $concessao): int => (int) $conn->fetchOne('SELECT COUNT(*) FROM conector_ia_token WHERE concessao_id = ? AND revogado_em IS NULL', [$concessao]);
        $idDaConcessao = (int) $deA['concessao']->getId();
        self::assertSame(4, $abertos($idDaConcessao), 'sanidade: dois pares abertos');

        $caidos = $this->repo(TokenDoConectorRepository::class)->revogarParGeradoPor($deA['renovacao'], new \DateTimeImmutable());

        self::assertSame(2, $caidos);
        self::assertSame(2, $abertos($idDaConcessao), 'o primeiro par fica');
        self::assertNotNull($conn->fetchOne('SELECT revogado_em FROM conector_ia_token WHERE id = ?', [$deA['renovacaoNova']->getId()]));
    }

    #[TestDox('A renovação a girar vem com a linha travada (FOR UPDATE): fora de transação o Doctrine recusa; acesso não serve')]
    public function testBuscarRenovacaoParaGirarTravaALinha(): void
    {
        $deA = $this->semearConector($this->tenantNovo(), UserFactory::createOne()->_real(), $this->clienteGlobal());
        $tokens = $this->repo(TokenDoConectorRepository::class);
        $hash = $deA['renovacao']->getIdentificadorHash();

        $achada = $this->em->wrapInTransaction(static fn () => $tokens->buscarRenovacaoParaGirar($hash));
        $doAcesso = $this->em->wrapInTransaction(static fn () => $tokens->buscarRenovacaoParaGirar($deA['acesso']->getIdentificadorHash()));

        self::assertSame($deA['renovacao'], $achada);
        self::assertNull($doAcesso, 'token de acesso não é renovação');
        $this->expectException(TransactionRequiredException::class);
        $tokens->buscarRenovacaoParaGirar($hash);
    }

    #[TestDox('A filha é a renovação mais recente gerada por esta; quem nunca girou não tem filha')]
    public function testBuscarRenovacaoGeradaPor(): void
    {
        $deA = $this->semearConector($this->tenantNovo(), UserFactory::createOne()->_real(), $this->clienteGlobal());
        $tokens = $this->repo(TokenDoConectorRepository::class);
        self::assertSame($deA['renovacaoNova'], $tokens->buscarRenovacaoGeradaPor($deA['renovacao']));
        self::assertNull($tokens->buscarRenovacaoGeradaPor($deA['renovacaoNova']));

        $maisNova = TokenDoConector::deRenovacao(hash('sha256', 'repeticao' . uniqid()), $deA['concessao'], new \DateTimeImmutable(), $deA['renovacao']);
        $this->em->persist($maisNova);
        $this->em->flush();

        self::assertSame($maisNova, $tokens->buscarRenovacaoGeradaPor($deA['renovacao']));
    }

    #[TestDox('As buscas pelo hash acham código, token e cliente, que é quem revela o escritório')]
    public function testBuscasPeloHash(): void
    {
        $user = UserFactory::createOne()->_real();
        $cliente = $this->clienteGlobal();
        $a = $this->tenantNovo();
        $deA = $this->semearConector($a, $user, $cliente);
        $tokens = $this->repo(TokenDoConectorRepository::class);
        $codigos = $this->repo(CodigoDoConectorRepository::class);

        self::assertSame($cliente, $this->repo(ClienteDoConectorRepository::class)->buscarPorIdentificador($cliente->getIdentificador()));
        self::assertSame($deA['acesso'], $tokens->buscarPorIdentificadorHash($deA['acesso']->getIdentificadorHash()));
        self::assertSame($deA['renovacaoNova'], $tokens->buscarPorIdentificadorHash($deA['renovacaoNova']->getIdentificadorHash()));
        self::assertSame($a, $deA['acesso']->getTenant());
        self::assertNull($tokens->buscarPorIdentificadorHash(hash('sha256', 'nao-existe')));
        self::assertSame($deA['codigo'], $codigos->buscarPorIdentificadorHash($deA['codigo']->getIdentificadorHash()));
        self::assertNull($codigos->buscarPorIdentificadorHash(hash('sha256', 'nao-existe')));
    }
}
