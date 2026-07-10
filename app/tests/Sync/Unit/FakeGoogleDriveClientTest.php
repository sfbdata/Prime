<?php

declare(strict_types=1);

namespace App\Tests\Sync\Unit;

use App\Tests\Sync\Support\FakeGoogleDriveClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeGoogleDriveClient::class)]
final class FakeGoogleDriveClientTest extends TestCase
{
    #[TestDox('criarPasta registra e listarSubpastas devolve pelo parent')]
    public function testCriarEListarSubpastas(): void
    {
        $fake = new FakeGoogleDriveClient();
        $id   = $fake->criarPasta('123 - FULANO', 'raiz');

        $subs = $fake->listarSubpastas('raiz');

        self::assertCount(1, $subs);
        self::assertSame($id, $subs[0]['id']);
        self::assertSame('123 - FULANO', $subs[0]['nome']);
        self::assertSame([], $fake->listarSubpastas('outra'));
    }

    #[TestDox('seedArquivo aparece em listarArquivos só da sua pasta')]
    public function testListarArquivos(): void
    {
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('f1', 'peca.pdf', 'folderA');
        $fake->seedArquivo('f2', 'outro.pdf', 'folderB');

        $arquivos = $fake->listarArquivos('folderA');

        self::assertCount(1, $arquivos);
        self::assertSame('peca.pdf', $arquivos[0]['nome']);
    }
}
