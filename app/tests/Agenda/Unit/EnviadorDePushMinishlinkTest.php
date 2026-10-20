<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\InscricaoDePush;
use App\Agenda\Infra\EnviadorDePushMinishlink;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(EnviadorDePushMinishlink::class)]
final class EnviadorDePushMinishlinkTest extends TestCase
{
    #[TestDox('Sem as três variáveis VAPID o push fica desligado e a chave pública é nula')]
    public function testDesligado(): void
    {
        $sut = new EnviadorDePushMinishlink(new NullLogger(), 'pub', 'priv', '');

        self::assertFalse($sut->ligado());
        self::assertNull($sut->chavePublica());
        self::assertSame([], $sut->enviar([['inscricao' => $this->inscricao('x'), 'conteudo' => []]]));
    }

    #[TestDox('Chaves VAPID preenchidas mas inválidas deixam o push desligado (não reserva lembrete para depois falhar)')]
    public function testVapidInvalidaDesliga(): void
    {
        $vapid = VAPID::createVapidKeys();

        self::assertFalse((new EnviadorDePushMinishlink(new NullLogger(), 'curta', $vapid['privateKey'], 'mailto:a@b.com'))->ligado());
        self::assertFalse((new EnviadorDePushMinishlink(new NullLogger(), $vapid['publicKey'], $vapid['privateKey'], 'sem-esquema'))->ligado());
        self::assertTrue((new EnviadorDePushMinishlink(new NullLogger(), $vapid['publicKey'], $vapid['privateKey'], 'mailto:a@b.com'))->ligado());
    }

    #[TestDox('Inscrição com chave torta não lança exceção nem derruba o lote: volta como sem serventia, para ser apagada')]
    public function testChaveTortaNaoDerrubaOLote(): void
    {
        $vapid = VAPID::createVapidKeys();
        $sut = new EnviadorDePushMinishlink(new NullLogger(), $vapid['publicKey'], $vapid['privateKey'], 'mailto:teste@bluejus.com.br');
        $torta = $this->inscricao('BOr5QnS8RTc');
        $outraTorta = $this->inscricao('BOr5');

        $vencidas = $sut->enviar([
            ['inscricao' => $torta, 'conteudo' => ['titulo' => 'a']],
            ['inscricao' => $outraTorta, 'conteudo' => ['titulo' => 'b']],
        ]);

        self::assertSame([$torta, $outraTorta], $vencidas);
    }

    private function inscricao(string $p256dh): InscricaoDePush
    {
        return new InscricaoDePush(new Tenant(), new User(), 'https://fcm.googleapis.com/fcm/send/' . uniqid(), $p256dh, 'AAECAwQFBgcICQoLDA0ODw', 'UTC');
    }
}
