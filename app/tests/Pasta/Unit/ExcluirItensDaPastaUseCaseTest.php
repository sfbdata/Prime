<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\SelecaoDeItensDaPasta;
use App\Pasta\UseCase\ExcluirItensDaPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Excluir em lote (D4) é LIXEIRA desde o L7 (D7): nada é removido do banco (`remove()` nunca é
 * chamado) nem do disco (o resultado não carrega chave nenhuma); cada item selecionado recebe a
 * lápide, a subárvore de cada subpasta inteira com o MESMO carimbo, e o que já estava na lixeira
 * mantém o carimbo de antes. UM flush.
 */
#[CoversClass(ExcluirItensDaPastaUseCase::class)]
#[CoversClass(SelecaoDeItensDaPasta::class)]
final class ExcluirItensDaPastaUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-07 10:00:00';

    private EntityManagerInterface&MockObject $em;
    private ExcluirItensDaPastaUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirItensDaPastaUseCase($this->em, new MockClock(self::AGORA));
        $this->tenant  = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->pasta = (new Pasta())->setTenant($this->tenant);
        $this->autor = (new User())->setEmail('autor@test.com');

        // Lixeira: a linha fica. Um `remove()` aqui seria a regressão para a exclusão física.
        $this->em->expects($this->never())->method('remove');
    }

    private function secao(string $nome, ?PastaSecao $pai = null, ?Tenant $tenant = null, int $id = 0): PastaSecao
    {
        $s = (new PastaSecao())->setNome($nome)->setPai($pai);
        $s->setPasta($this->pasta);
        $s->setTenant($tenant ?? $this->tenant);
        if ($id > 0) {
            (new \ReflectionProperty(PastaSecao::class, 'id'))->setValue($s, $id);
        }

        return $s;
    }

    /** `setSecao()` não sincroniza a coleção da seção; aqui a coleção é alimentada à mão. */
    private function documento(string $nome, ?PastaSecao $secao = null, ?Pasta $pasta = null, int $id = 0): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setPasta($pasta ?? $this->pasta);
        $d->setTenant($this->tenant);
        $d->setTitulo($nome);
        $d->setNomeOriginal($nome);
        $d->setCategoria('DEMAIS');
        $d->setCaminhoArquivo($nome);
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(1);
        $d->setSecao($secao);
        $secao?->getDocumentos()->add($d);
        if ($id > 0) {
            (new \ReflectionProperty(PastaDocumento::class, 'id'))->setValue($d, $id);
        }

        return $d;
    }

    #[TestDox('documento solto + subpasta com árvore: tudo vai para a lixeira com o MESMO carimbo, contagens certas, UM flush, nenhum remove()')]
    public function testMandaArvoreInteiraParaALixeira(): void
    {
        $solto = $this->documento('solto.pdf', id: 11);
        $mae   = $this->secao('MAE', id: 1);
        $filha = $this->secao('FILHA', $mae, id: 2);
        $neta  = $this->secao('NETA', $filha, id: 3);
        $naMae  = $this->documento('na-mae.pdf', $mae);
        $naNeta = $this->documento('na-neta.pdf', $neta);
        $fica   = $this->documento('fica.pdf');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, [$solto], [$mae], $this->autor, $this->tenant);

        self::assertSame(1, $resultado->documentosRemovidos);
        self::assertSame(3, $resultado->subpastasRemovidas, 'MAE + FILHA + NETA');
        self::assertSame(3, $resultado->arquivosRemovidos);
        self::assertSame([11], $resultado->idsDocumentos);
        self::assertSame([1], $resultado->idsSecoes);
        self::assertSame(self::AGORA, $resultado->excluidoEm->format('Y-m-d H:i:s'));

        foreach ([$solto, $naMae, $naNeta] as $doc) {
            self::assertTrue($doc->estaNaLixeira(), $doc->getNomeOriginal());
            self::assertSame($resultado->excluidoEm, $doc->getExcluidoEm(), 'o carimbo é o MESMO objeto para o bloco inteiro');
            self::assertSame($this->autor, $doc->getExcluidoPor());
        }
        foreach ([$mae, $filha, $neta] as $secao) {
            self::assertTrue($secao->estaNaLixeira(), $secao->getNome());
            self::assertSame($resultado->excluidoEm, $secao->getExcluidoEm());
        }
        self::assertFalse($fica->estaNaLixeira(), 'o que não foi selecionado não se move');
    }

    #[TestDox('documento dentro de subpasta selecionada: marcado uma vez só, pela árvore, e contado uma vez')]
    public function testDocumentoDentroDaSubpastaNaoRepete(): void
    {
        $a      = $this->secao('A', id: 5);
        $dentro = $this->documento('dentro.pdf', $a, id: 50);

        $resultado = $this->useCase->executar($this->pasta, [$dentro], [$a], $this->autor, $this->tenant);

        self::assertSame(0, $resultado->documentosRemovidos);
        self::assertSame(1, $resultado->arquivosRemovidos);
        self::assertSame(1, $resultado->subpastasRemovidas);
        self::assertTrue($dentro->estaNaLixeira());
        self::assertSame([50], $resultado->idsDocumentos, 'os ids devolvidos são os que a tela mandou — é o que o Desfazer manda de volta');
    }

    #[TestDox('o que já estava na lixeira dentro da subpasta mantém o carimbo antigo (não volta junto no restaurar)')]
    public function testNaoRecarimbaOQueJaEstavaNaLixeira(): void
    {
        $a      = $this->secao('A');
        $antigo = $this->documento('antigo.pdf', $a);
        $outro  = (new User())->setEmail('outro@test.com');
        $antes  = new \DateTimeImmutable('2026-09-01 08:00:00');
        $antigo->marcarExcluido($outro, $antes);
        $novo = $this->documento('novo.pdf', $a);

        $resultado = $this->useCase->executar($this->pasta, [], [$a], $this->autor, $this->tenant);

        self::assertSame(1, $resultado->arquivosRemovidos, 'só o que estava vivo conta');
        self::assertSame($antes, $antigo->getExcluidoEm());
        self::assertSame($outro, $antigo->getExcluidoPor());
        self::assertSame($resultado->excluidoEm, $novo->getExcluidoEm());
    }

    #[TestDox('filha e mãe selecionadas: a mãe cobre a filha')]
    public function testFilhaEMaeSelecionadas(): void
    {
        $mae   = $this->secao('MAE');
        $filha = $this->secao('FILHA', $mae);

        $resultado = $this->useCase->executar($this->pasta, [], [$filha, $mae], $this->autor, $this->tenant);

        self::assertSame(2, $resultado->subpastasRemovidas);
        self::assertTrue($mae->estaNaLixeira());
        self::assertTrue($filha->estaNaLixeira());
    }

    #[TestDox('item de outro escritório: AccessDenied, nada marcado, nada gravado')]
    public function testTenantErrado(): void
    {
        $alheia = $this->secao('ALHEIA', null, new Tenant());
        $meu    = $this->documento('meu.pdf');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        try {
            $this->useCase->executar($this->pasta, [$meu], [$alheia], $this->autor, $this->tenant);
        } finally {
            self::assertFalse($meu->estaNaLixeira());
            self::assertFalse($alheia->estaNaLixeira());
        }
    }

    #[TestDox('documento de outra pasta do escritório: recusado, nada marcado')]
    public function testPastaIrma(): void
    {
        $alheio = $this->documento('alheio.pdf', null, (new Pasta())->setTenant($this->tenant));
        $meu    = $this->documento('meu.pdf');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($this->pasta, [$meu, $alheio], [], $this->autor, $this->tenant);
        } finally {
            self::assertFalse($meu->estaNaLixeira());
            self::assertFalse($alheio->estaNaLixeira());
        }
    }

    #[TestDox('seleção vazia é recusada')]
    public function testSelecaoVazia(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->pasta, [], [], $this->autor, $this->tenant);
    }
}
