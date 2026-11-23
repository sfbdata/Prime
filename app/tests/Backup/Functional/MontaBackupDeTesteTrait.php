<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Coletor\ColetorDoBackup;
use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\UseCase\MontarBackupUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\Tenant\TenantContext;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Symfony\Component\Clock\MockClock;

/**
 * Monta o backup SEM sessão e SEM `TenantContext`, como o worker, só com os coletores que o teste entrega, e abre o
 * ZIP de verdade. Quem usa define {@see coletores()}.
 */
trait MontaBackupDeTesteTrait
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const RAIZ = 'backup-bluejus-2026-11-23/';

    private ArmazenamentoEmMemoriaNoContainer $duble;

    /** @return list<ColetorDoBackup> */
    abstract private function coletores(int $lote): array;

    private function iniciarBackupDeTeste(): void
    {
        self::bootKernel();
        $this->duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
    }

    /** @return array<string, string> caminho dentro da raiz => conteúdo */
    private function backupDe(User $usuario, Tenant $tenant, int $lote = 25): array
    {
        self::assertNull(static::getContainer()->get(TenantContext::class)->getCurrentTenant(), 'o worker não tem escritório na sessão');

        $caso = new MontarBackupUseCase($this->duble->memoria, $this->duble->memoria, new MockClock('2026-11-23 10:00:00'), $this->coletores($lote));
        $resultado = $caso->executar(new PedidoDeBackup($tenant, $usuario, new \DateTimeImmutable('2026-11-23 10:00:00')));

        self::assertInstanceOf(ResultadoDoBackup::class, $resultado);
        $bytes = $this->duble->memoria->ler(new ChaveDeArquivo(EscopoDeArquivo::deTenant((int) $tenant->getId()), CategoriaDeArquivo::BACKUP_ZIP, $resultado->chaveArquivo));

        return $this->abrirZip($bytes);
    }

    /** @return array<string, string> */
    private function abrirZip(string $bytes): array
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'bkp');
        file_put_contents((string) $arquivo, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open((string) $arquivo));
        $entradas = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nome = (string) $zip->getNameIndex($i);
            self::assertStringStartsWith(self::RAIZ, $nome);
            $entradas[substr($nome, strlen(self::RAIZ))] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink((string) $arquivo);

        return $entradas;
    }

    /** @param array<string, string> $zip */
    private static function tudo(array $zip): string
    {
        return implode("\n", array_keys($zip)) . "\n" . implode("\n", $zip);
    }

    /** @param array<string, string> $zip */
    private static function entradaCom(array $zip, string $trecho): ?string
    {
        foreach ($zip as $nome => $conteudo) {
            if (str_contains($nome, $trecho)) {
                return $conteudo;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $zip
     *
     * @return array<mixed>
     */
    private static function json(array $zip, string $caminho): array
    {
        self::assertArrayHasKey($caminho, $zip);

        return json_decode($zip[$caminho], true, flags: JSON_THROW_ON_ERROR);
    }

    /** O lote de 1 (clear a cada item) deve gerar o MESMO conteúdo do lote normal. */
    private function assertLoteDeUmIgual(User $usuario, Tenant $tenant): void
    {
        $normal = $this->backupDe($usuario, $tenant);
        $lote   = $this->backupDe($usuario, $tenant, 1);

        self::assertSame(array_keys($normal), array_keys($lote));
        foreach ($normal as $nome => $conteudo) {
            if ($nome !== 'LEIA-ME.txt' && $nome !== 'manifesto.json') {
                self::assertSame($conteudo, $lote[$nome], $nome);
            }
        }
    }
}
