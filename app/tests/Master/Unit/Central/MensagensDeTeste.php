<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit\Central;

use App\Master\DTO\DestinoDaResposta;
use App\Master\DTO\MensagemDaCentral;
use App\Master\Notificacoes\OrigemDaCentral;
use App\Entity\Auth\User;

/** Mensagens e uma origem em memória para os testes da Central do Master (sem banco). */
final class MensagensDeTeste
{
    public static function mensagem(string $origem, int $id, string $em = '2026-10-20 10:00', bool $podeResponder = true, ?string $email = 'ana@exemplo.com', ?int $tenantId = 7, string $texto = 'Texto da mensagem'): MensagemDaCentral
    {
        return new MensagemDaCentral($origem, $id, 'sug', 'Sugestão', 'Ana Lima', $email, 'Conta Nº 000007', $tenantId, 'Sugestão', $texto, new \DateTimeImmutable($em), $podeResponder);
    }

    /**
     * @param list<MensagemDaCentral> $mensagens
     */
    public static function origem(string $chave, array $mensagens, ?DestinoDaResposta $destino = null): OrigemDaCentral
    {
        return new class ($chave, $mensagens, $destino) implements OrigemDaCentral {
            /** @var list<array{int, string}> */
            public array $registradas = [];

            /** @param list<MensagemDaCentral> $mensagens */
            public function __construct(private string $chave, private array $mensagens, private ?DestinoDaResposta $destino)
            {
            }

            public function origem(): string
            {
                return $this->chave;
            }

            public function todas(): array
            {
                return $this->mensagens;
            }

            public function uma(int $idOrigem): ?MensagemDaCentral
            {
                foreach ($this->mensagens as $m) {
                    if ($m->idOrigem === $idOrigem) {
                        return $m;
                    }
                }

                return null;
            }

            public function destinoDaResposta(int $idOrigem): ?DestinoDaResposta
            {
                return $this->destino;
            }

            public function registrarResposta(int $idOrigem, string $texto, User $master): void
            {
                $this->registradas[] = [$idOrigem, $texto];
            }
        };
    }
}
