<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\SeloDaTrilhaDoPonto;
use App\Ponto\Service\TrilhaDoPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A gravação de um evento na trilha do Ponto (E9), com o repositório falso: a ordem (trava, último elo, inserção), o
 * elo com a linha anterior, o selo com a chave da versão atual e a linha sem selo quando não há segredo.
 */
#[CoversClass(TrilhaDoPonto::class)]
final class TrilhaDoPontoTest extends TestCase
{
    use TrilhaDoPontoDeTesteTrait;

    #[TestDox('Com segredo: sequência 1, 2, 3 encadeada, selo da chave da versão 1 sobre a linha gravada')]
    public function testComSegredoSelaEEncadeia(): void
    {
        $trilha = $this->trilhaDeTeste('segredo-de-teste');
        $autor  = $this->pessoa(5, 'Ana Souza');

        foreach (['batida_registrada', 'justificativa_enviada', 'justificativa_abonada'] as $acao) {
            $trilha->registrar($this->escritorio(9), $autor, $acao, ['tipo' => 'entrada']);
        }

        self::assertSame([1, 2, 3], array_column($this->eventosDaTrilha, 'sequencia'));
        self::assertNull($this->eventosDaTrilha[0]['selo_anterior']);
        self::assertSame($this->eventosDaTrilha[0]['selo'], $this->eventosDaTrilha[1]['selo_anterior']);
        self::assertSame($this->eventosDaTrilha[1]['selo'], $this->eventosDaTrilha[2]['selo_anterior']);
        $chave = (string) (new ChavesDoHmacDoPonto('segredo-de-teste'))->chave(ChavesDoHmacDoPonto::FINALIDADE_TRILHA, 1);
        foreach ($this->eventosDaTrilha as $linha) {
            self::assertSame(1, $linha['versao_da_chave']);
            self::assertSame(SeloDaTrilhaDoPonto::calcular($chave, $linha), $linha['selo']);
        }
        self::assertSame(['tenant_id' => 9, 'em' => '2026-03-10 08:00:00', 'autor_id' => 5, 'autor_nome' => 'Ana Souza'], array_intersect_key($this->eventosDaTrilha[0], array_flip(['tenant_id', 'em', 'autor_id', 'autor_nome'])));
    }

    #[TestDox('Sem segredo: a linha entra sem selo e sem versão, encadeada pela sequência, e nada falha')]
    public function testSemSegredoGravaSemSelo(): void
    {
        $trilha = $this->trilhaDeTeste('');

        $trilha->registrar($this->escritorio(9), null, 'escala_alterada', []);
        $trilha->registrar($this->escritorio(9), null, 'escala_alterada', []);

        self::assertSame([1, 2], array_column($this->eventosDaTrilha, 'sequencia'));
        self::assertSame([null, null], array_column($this->eventosDaTrilha, 'selo'));
        self::assertSame([null, null], array_column($this->eventosDaTrilha, 'versao_da_chave'));
        self::assertNull($this->eventosDaTrilha[0]['autor_nome'], 'sem autor (fora de uma sessão)');
    }

    #[TestDox('Falta do segredo no meio: a linha sem selo guarda o último selo, e a selada seguinte se prende a ele')]
    public function testFaltaDoSegredoNoMeio(): void
    {
        $this->trilhaDeTeste('segredo-de-teste')->registrar($this->escritorio(9), null, 'batida_registrada', []);
        $this->trilhaDeTeste('')->registrar($this->escritorio(9), null, 'batida_registrada', []);
        $this->trilhaDeTeste('')->registrar($this->escritorio(9), null, 'batida_registrada', []);
        $this->trilhaDeTeste('segredo-de-teste')->registrar($this->escritorio(9), null, 'batida_registrada', []);

        $primeiroSelo = $this->eventosDaTrilha[0]['selo'];
        self::assertNotNull($primeiroSelo);
        self::assertSame([null, null], [$this->eventosDaTrilha[1]['selo'], $this->eventosDaTrilha[2]['selo']]);
        self::assertSame([$primeiroSelo, $primeiroSelo, $primeiroSelo], array_column(\array_slice($this->eventosDaTrilha, 1), 'selo_anterior'));
    }

    #[TestDox('Sem nome completo, o nome do autor é o e-mail')]
    public function testAutorSemNome(): void
    {
        $sem = $this->pessoa(6, '');
        $sem->setEmail('sem.nome@teste.com');

        $this->trilhaDeTeste()->registrar($this->escritorio(9), $sem, 'batida_registrada', []);

        self::assertSame('sem.nome@teste.com', $this->eventosDaTrilha[0]['autor_nome']);
    }

    #[TestDox('Trava a trilha do escritório antes de ler o último elo e de inserir, dentro da transação')]
    public function testOrdemDaGravacao(): void
    {
        $passos = [];
        $repositorio = $this->createMock(TrilhaDoPontoRepository::class);
        $repositorio->expects(self::once())->method('emTransacao')->willReturnCallback(static function (\Closure $trabalho) use (&$passos): mixed {
            $passos[] = 'transacao';

            return $trabalho();
        });
        $repositorio->expects(self::once())->method('travar')->with(9)->willReturnCallback(static function () use (&$passos): void {
            $passos[] = 'travar';
        });
        $repositorio->expects(self::once())->method('ultimoElo')->with(9)->willReturnCallback(static function () use (&$passos): array {
            $passos[] = 'ultimo';

            return ['sequencia' => 41, 'selo' => str_repeat('b', 64)];
        });
        $repositorio->expects(self::once())->method('inserir')->willReturnCallback(static function (array $linha) use (&$passos): void {
            $passos[] = 'inserir:' . $linha['sequencia'] . ':' . substr((string) $linha['selo_anterior'], 0, 1);
        });

        (new TrilhaDoPonto($repositorio, new ChavesDoHmacDoPonto(null), new MockClock(), new TokenStorage()))
            ->registrar($this->escritorio(9), null, 'batida_registrada', []);

        self::assertSame(['transacao', 'travar', 'ultimo', 'inserir:42:b'], $passos);
    }

    #[TestDox('Escritório ainda sem id: erro de programação, nada é gravado')]
    public function testEscritorioSemId(): void
    {
        $this->expectException(\LogicException::class);

        $this->trilhaDeTeste()->registrar((new Tenant())->setName('Novo'), null, 'batida_registrada', []);
    }

    #[TestDox('autorDaSessao: o usuário da sessão; sem sessão, nulo')]
    public function testAutorDaSessao(): void
    {
        $sessao = new TokenStorage();
        $trilha = new TrilhaDoPonto($this->createStub(TrilhaDoPontoRepository::class), new ChavesDoHmacDoPonto(null), new MockClock(), $sessao);
        self::assertNull($trilha->autorDaSessao());

        $eu = $this->pessoa(5, 'Ana');
        $sessao->setToken(new UsernamePasswordToken($eu, 'main', ['ROLE_USER']));

        self::assertSame($eu, $trilha->autorDaSessao());
    }

    private function escritorio(int $id): Tenant
    {
        $escritorio = (new Tenant())->setName('Escritório ' . $id);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($escritorio, $id);

        return $escritorio;
    }

    private function pessoa(int $id, string $nome): User
    {
        $pessoa = (new User())->setEmail('p' . $id . '@teste.com')->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, $id);

        return $pessoa;
    }
}
