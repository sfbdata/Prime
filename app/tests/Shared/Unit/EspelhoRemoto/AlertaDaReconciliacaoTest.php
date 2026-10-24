<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\EspelhoRemoto\Reconciliacao\AlertaDaReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\ClasseDeReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use App\Shared\EspelhoRemoto\Reconciliacao\RelatorioDeReconciliacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O critério do alerta da P2.7: o que derruba a rodada agendada e o que só aparece na contagem. */
#[CoversClass(AlertaDaReconciliacao::class)]
#[CoversClass(RelatorioDeReconciliacao::class)]
#[CoversClass(ReferenciaDeArquivo::class)]
final class AlertaDaReconciliacaoTest extends TestCase
{
    #[TestDox('ausente no espelho, tamanho e SHA divergentes e erro de consulta (R2 fora do ar) disparam, com contagem por categoria')]
    public function testClassesQueExigemAcao(): void
    {
        $relatorio = new RelatorioDeReconciliacao();
        $relatorio->registrar($this->ref(CategoriaDeArquivo::FOTO_PERFIL), ClasseDeReconciliacao::AUSENTE_NO_ESPELHO);
        $relatorio->registrar($this->ref(CategoriaDeArquivo::FOTO_PERFIL), ClasseDeReconciliacao::AUSENTE_NO_ESPELHO);
        $relatorio->registrar($this->ref(CategoriaDeArquivo::CLIENTE_DOCUMENTO), ClasseDeReconciliacao::AUSENTE_NO_ESPELHO);
        $relatorio->registrar($this->ref(CategoriaDeArquivo::CLIENTE_DOCUMENTO), ClasseDeReconciliacao::TAMANHO_DIVERGENTE);
        $relatorio->registrar($this->ref(CategoriaDeArquivo::FOTO_PERFIL), ClasseDeReconciliacao::SHA_DIVERGENTE);
        $relatorio->registrar($this->ref(CategoriaDeArquivo::FOTO_PERFIL), ClasseDeReconciliacao::ERRO_DE_CONSULTA);

        $alerta = AlertaDaReconciliacao::avaliar($relatorio, []);

        self::assertTrue($alerta->disparou());
        self::assertSame([
            'ausente_no_espelho: 3 (cliente_documento: 1, foto_perfil: 2)',
            'tamanho_divergente: 1 (cliente_documento: 1)',
            'sha_divergente: 1 (foto_perfil: 1)',
            'erro_de_consulta: 1 (foto_perfil: 1)',
        ], $alerta->linhas());
    }

    #[TestDox('linhas em falhou na outbox disparam sozinhas')]
    public function testFalhouNaOutbox(): void
    {
        $alerta = AlertaDaReconciliacao::avaliar(new RelatorioDeReconciliacao(), ['foto_perfil' => 2, 'cliente_documento' => 1]);

        self::assertSame(['falhou_na_outbox: 3 (cliente_documento: 1, foto_perfil: 2)'], $alerta->linhas());
    }

    #[TestDox('igual, presente sem SHA, suspeito do Drive, em fila, local ausente, chave inválida e sem SHA no espelho não disparam')]
    public function testNaoDisparam(): void
    {
        $relatorio = new RelatorioDeReconciliacao();
        foreach ([
            ClasseDeReconciliacao::IGUAL,
            ClasseDeReconciliacao::PRESENTE_SEM_SHA_CONFERIDO,
            ClasseDeReconciliacao::SUSPEITO_ERRO_DO_DRIVE,
            ClasseDeReconciliacao::AUSENTE_PENDENTE_OUTBOX,
            // Problemas conhecidos do acervo local (P1), não do espelho: só contagem.
            ClasseDeReconciliacao::LOCAL_AUSENTE,
            ClasseDeReconciliacao::CHAVE_INVALIDA,
            ClasseDeReconciliacao::SEM_SHA_NO_ESPELHO,
        ] as $classe) {
            $relatorio->registrar($this->ref(CategoriaDeArquivo::FOTO_PERFIL), $classe);
        }

        self::assertFalse(AlertaDaReconciliacao::avaliar($relatorio, [])->disparou());
        self::assertSame(1, $relatorio->porCategoria(ClasseDeReconciliacao::SUSPEITO_ERRO_DO_DRIVE)['foto_perfil'] ?? 0);
    }

    #[TestDox('referência sem chave válida conta pela categoria da fonte')]
    public function testCategoriaDaFonte(): void
    {
        $relatorio = new RelatorioDeReconciliacao();
        $relatorio->registrar(new ReferenciaDeArquivo('kanban_anexo', 1, null, CategoriaDeArquivo::KANBAN_ANEXO), ClasseDeReconciliacao::CHAVE_INVALIDA);
        $relatorio->registrar(new ReferenciaDeArquivo('outra', 2, null), ClasseDeReconciliacao::CHAVE_INVALIDA);

        self::assertSame(['kanban_anexo' => 1, RelatorioDeReconciliacao::SEM_CATEGORIA => 1], $relatorio->porCategoria(ClasseDeReconciliacao::CHAVE_INVALIDA));
    }

    private function ref(CategoriaDeArquivo $categoria): ReferenciaDeArquivo
    {
        $escopo = $categoria === CategoriaDeArquivo::FOTO_PERFIL ? EscopoDeArquivo::global() : EscopoDeArquivo::deTenant(3);

        return new ReferenciaDeArquivo($categoria->value, random_int(1, 1000), new ChaveDeArquivo($escopo, $categoria, 'Arquivo de Maria.pdf'));
    }
}
