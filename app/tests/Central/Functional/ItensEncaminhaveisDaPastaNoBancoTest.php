<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Service\ItensEncaminhaveisDaPasta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaSecao;
use App\Processo\Entity\NotaTecnica;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Cada tipo de item relido do banco (`ItensEncaminhaveisDaPasta`): o cartão esperado e o 404 quando o id é de OUTRA
 * pasta do MESMO escritório. Para processo, nota técnica e push a guarda é o vínculo da pasta com o processo (e os
 * números dos processos vinculados), não uma coluna `pasta`: por isso a irmã sem o processo tem de dar 404.
 */
#[CoversClass(ItensEncaminhaveisDaPasta::class)]
final class ItensEncaminhaveisDaPastaNoBancoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private Tenant $tenant;
    private User $autor;
    private Pasta $pasta;
    private Pasta $irma;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        [, $this->tenant] = $this->criarAdmin();
        $this->autor = $this->criarUsuarioComPermissoes($this->tenant, ['resources.pasta.view'], 'autor');
        $this->pasta = $this->criarPasta($this->tenant);
        $this->irma = $this->criarPasta($this->tenant);
    }

    #[TestDox('Registro de Detalhes e do Financeiro: "Registro de AUTOR · data" e o conteúdo limpo; da irmã é 404')]
    public function testRegistrosDeDetalhesEFinanceiro(): void
    {
        foreach ([[new PastaObservacaoDetalhes(), TipoDeItemEncaminhado::RegistroDetalhes], [new PastaObservacaoFinanceira(), TipoDeItemEncaminhado::RegistroFinanceiro]] as [$obs, $tipo]) {
            $obs->setTenant($this->tenant)->setPasta($this->pasta)->setAutor($this->autor)->setConteudo('<p>Honorários &amp; custas</p>');
            $this->em()->persist($obs);
            $this->em()->flush();

            $item = $this->resumir($tipo, (int) $obs->getId());
            self::assertSame('Registro de Usuário autor · ' . $obs->getCriadaEm()->format('d/m/Y H:i'), $item->titulo, $tipo->value);
            self::assertSame('Honorários & custas', $item->resumo, $tipo->value);
            $this->naIrmaEh404($tipo, (int) $obs->getId());
        }
    }

    // Título do documento, nome da seção e classe processual: as entidades gravam em maiúsculas (setter delas).
    #[TestDox('Documento: o título dele e "Documento"; da irmã é 404')]
    public function testDocumento(): void
    {
        $documento = $this->criarDocumento($this->tenant, $this->pasta, 'Procuração assinada');

        $item = $this->resumir(TipoDeItemEncaminhado::Documento, (int) $documento->getId());
        self::assertSame(['PROCURAÇÃO ASSINADA', 'Documento'], [$item->titulo, $item->resumo]);
        $this->naIrmaEh404(TipoDeItemEncaminhado::Documento, (int) $documento->getId());
    }

    #[TestDox('Seção: o nome dela e "Seção de documentos"; da irmã é 404')]
    public function testSecao(): void
    {
        $secao = (new PastaSecao())->setTenant($this->tenant)->setPasta($this->pasta)->setNome('Petições')->setOrdem(1);
        $this->em()->persist($secao);
        $this->em()->flush();

        $item = $this->resumir(TipoDeItemEncaminhado::Secao, (int) $secao->getId());
        self::assertSame(['PETIÇÕES', 'Seção de documentos'], [$item->titulo, $item->resumo]);
        $this->naIrmaEh404(TipoDeItemEncaminhado::Secao, (int) $secao->getId());
    }

    #[TestDox('Processo: número CNJ e "classe · tribunal"; a irmã sem o processo vinculado dá 404')]
    public function testProcesso(): void
    {
        $processo = $this->criarProcesso($this->tenant, '07011345720258070007');
        $processo->setSiglaTribunal('TJDFT');
        $this->vincular($this->pasta, $processo);

        $item = $this->resumir(TipoDeItemEncaminhado::Processo, (int) $processo->getId());
        self::assertSame(['Processo 0701134-57.2025.8.07.0007', 'PROCEDIMENTO COMUM · TJDFT'], [$item->titulo, $item->resumo]);
        $this->naIrmaEh404(TipoDeItemEncaminhado::Processo, (int) $processo->getId());
    }

    #[TestDox('Meta: título e "vence dd/mm/aaaa" (ou "concluída"); da irmã é 404')]
    public function testMeta(): void
    {
        $meta = $this->criarMeta($this->tenant, $this->pasta, $this->autor, 'Protocolar a réplica', '2026-11-05 10:00');

        $item = $this->resumir(TipoDeItemEncaminhado::Meta, (int) $meta->getId());
        self::assertSame(['Protocolar a réplica', 'vence 05/11/2026'], [$item->titulo, $item->resumo]);
        $this->naIrmaEh404(TipoDeItemEncaminhado::Meta, (int) $meta->getId());
    }

    #[TestDox('Prazo: título e "tipo · data · responsável"; da irmã é 404')]
    public function testPrazo(): void
    {
        $prazo = new PastaPrazo($this->tenant, $this->pasta, $this->autor);
        $prazo->definir(PastaPrazo::TIPO_AUDIENCIA, 'Audiência de conciliação', new \DateTimeImmutable('2026-11-10'), '14:00', 'Dra. Ana');
        $this->em()->persist($prazo);
        $this->em()->flush();

        $item = $this->resumir(TipoDeItemEncaminhado::Prazo, (int) $prazo->getId());
        self::assertSame(['Audiência de conciliação', 'Audiência · 10/11/2026 · Dra. Ana'], [$item->titulo, $item->resumo]);
        $this->naIrmaEh404(TipoDeItemEncaminhado::Prazo, (int) $prazo->getId());
    }

    #[TestDox('Push: "TIPO de data" e o teor; a irmã sem o processo da publicação dá 404')]
    public function testPush(): void
    {
        [$pasta, , $publicacao] = $this->criarPastaComPublicacao($this->tenant);
        $this->pasta = $pasta;

        $item = $this->resumir(TipoDeItemEncaminhado::Push, (int) $publicacao->getId());
        self::assertSame('Intimação de 01/10/2026', $item->titulo);
        self::assertSame('Intimação da sentença. Prazo de 15 dias para apelação.', $item->resumo);
        $this->naIrmaEh404(TipoDeItemEncaminhado::Push, (int) $publicacao->getId());
    }

    #[TestDox('Nota técnica: "Nota técnica · trecho" e o texto limpo; a irmã sem o processo da nota dá 404')]
    public function testNotaTecnica(): void
    {
        $processo = $this->criarProcesso($this->tenant, '07011345720258070007');
        $this->vincular($this->pasta, $processo);
        $nota = new NotaTecnica($this->tenant, $processo, $this->autor, sprintf('<p>Recorrer, @[Ana](user:%d) confere</p>', (int) $this->autor->getId()));
        $this->em()->persist($nota);
        $this->em()->flush();

        $item = $this->resumir(TipoDeItemEncaminhado::NotaTecnica, (int) $nota->getId());
        self::assertSame(['Nota técnica · Recorrer, @Ana confere', 'Recorrer, @Ana confere'], [$item->titulo, $item->resumo]);
        $this->naIrmaEh404(TipoDeItemEncaminhado::NotaTecnica, (int) $nota->getId());
    }

    private function resumir(TipoDeItemEncaminhado $tipo, int $id): \App\Central\DTO\ItemEncaminhado
    {
        return $this->itens()->resumir($this->tenant, $this->pasta, $tipo, $id);
    }

    private function naIrmaEh404(TipoDeItemEncaminhado $tipo, int $id): void
    {
        try {
            $this->itens()->resumir($this->tenant, $this->irma, $tipo, $id);
            self::fail($tipo->value . ': o item de outra pasta do mesmo escritório devia dar 404');
        } catch (ItemEncaminhadoNaoEncontradoException) {
            $this->addToAssertionCount(1);
        }
    }

    private function itens(): ItensEncaminhaveisDaPasta
    {
        return static::getContainer()->get(ItensEncaminhaveisDaPasta::class);
    }
}
